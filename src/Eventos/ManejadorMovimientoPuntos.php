<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende voucher.loyalty_card.transaction.created: un movimiento de puntos en
 * la tarjeta de fidelización de un cliente.
 *
 * Sustituye a v2/webhooks/anadir_puntos.php. Copia adaptada de
 * Loyalty\Eventos\ManejadorMovimientoPuntos del conector de Blueshift. Hace
 * tres cosas, en este orden:
 *
 *   1. Registra el movimiento en sm_movimientos_puntos.
 *   2. Actualiza saldo, total y tier del socio en sm_clientes, salvo que el
 *      movimiento sea anterior al último aplicado.
 *   3. Actualiza esas propiedades en su contacto de SalesManago, solo si se
 *      aplicó el paso 2.
 *
 * Los dos primeros van en una transacción. El aviso queda fuera: no tiene
 * sentido mantenerla abierta mientras se espera a SalesManago.
 *
 * SOLO SOCIOS REGISTRADOS
 * -----------------------
 * El socio se busca en sm_clientes por voucherify_id. Si no está (no pasó por
 * registro_club.php), el movimiento se guarda igualmente en el histórico, pero
 * no hay saldo que actualizar ni se avisa a SalesManago: un upsert crearía allí
 * un contacto que nunca se registró en el club.
 *
 * El correo para SalesManago es el de sm_clientes, no el del payload: es el que
 * identifica el contacto que creó registro_club.php.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Notifica a SalesManago en lugar de a Blueshift.
 * - El tier se toma de holder_loyalty_tier, que viene en el propio payload. El
 *   original consultaba en Blueshift un usuario ficticio con los tramos y lo
 *   deducía del saldo. Si el payload no lo trae, se conserva el guardado.
 * - El histórico va a sm_movimientos_puntos y no a puntos/{customer_id}.json.
 *   No se poda: la ventana de 10 meses se aplica al consultar
 *   (obtener_datos_usuario.php).
 * - Un movimiento que llega dos veces no se duplica (transaction_id único) y
 *   uno que llega tarde no hace retroceder el saldo. Ver actualizarSaldo().
 * - No llama al script de Google Wallet (actualizar_puntos.php).
 *
 * SIN MARCA DE NOTIFICACIÓN
 * -------------------------
 * El upsert fija valores absolutos, así que repetirlo deja el contacto igual.
 * El riesgo real es el orden: por eso el aviso depende de la guarda de orden,
 * que se vuelve a evaluar en cada intento.
 */
final class ManejadorMovimientoPuntos implements ManejadorEvento
{
    public function tipos(): array
    {
        return ['voucher.loyalty_card.transaction.created'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $datos = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $transaccion = is_array($datos['transaction'] ?? null) ? $datos['transaction'] : [];
        $detalles = is_array($transaccion['details'] ?? null) ? $transaccion['details'] : [];

        // El payload repite titular, saldo y tier en varios sitios. Se
        // prefieren las copias de primer nivel y se recurre a las internas
        // solo si faltan.
        $titular = $datos['holder'] ?? ($datos['voucher']['holder'] ?? []);
        $balance = $datos['balance'] ?? ($detalles['balance'] ?? []);
        $datosTier = $datos['holder_loyalty_tier'] ?? ($detalles['holder_loyalty_tier'] ?? []);

        $titular = is_array($titular) ? $titular : [];
        $balance = is_array($balance) ? $balance : [];
        $datosTier = is_array($datosTier) ? $datosTier : [];

        // Los puntos del movimiento: el original los leía de
        // transaction.details.balance.points.
        $puntos = $balance['points'] ?? ($detalles['balance']['points'] ?? null);

        $transactionId = trim((string) ($transaccion['id'] ?? ''));
        $customerId = trim((string) ($titular['id'] ?? $datos['voucher']['holder_id'] ?? ''));
        $fecha = trim((string) ($transaccion['created_at'] ?? ''));

        if (
            $transactionId === '' || $customerId === '' || $fecha === ''
            || !is_numeric($puntos) || !is_numeric($balance['balance'] ?? null) || !is_numeric($balance['total'] ?? null)
        ) {
            // Sin estos campos no hay fila que escribir, porque son NOT NULL.
            // Reintentarlo no lo va a arreglar.
            Log::error('voucher.loyalty_card.transaction.created sin identificadores, fecha o saldo; se ignora', [
                'evento' => $idEvento,
            ]);

            return;
        }

        $movimiento = [
            'transaction_id' => $transactionId,
            'customer_id'    => $customerId,
            'fecha'          => $fecha,
            'puntos'         => (int) $puntos,
            'saldo'          => (int) $balance['balance'],
            'total'          => (int) $balance['total'],
            'tier'           => is_string($datosTier['name'] ?? null) && $datosTier['name'] !== '' ? $datosTier['name'] : null,
        ];

        if ($movimiento['tier'] === null) {
            Log::warning('Movimiento de puntos sin holder_loyalty_tier; el tier se mantiene', [
                'transaccion' => $transactionId,
                'evento'      => $idEvento,
            ]);
        }

        $email = Db::enTransaccion(
            function () use ($movimiento, $transaccion, $detalles, $payload, $idEvento): ?string {
                // Bloquea la fila del socio: otro movimiento suyo procesado a
                // la vez espera a que esta transacción termine, y eso es lo
                // que hace segura la guarda de actualizarSaldo().
                $socio = Db::fila(
                    'SELECT id, email FROM sm_clientes
                      WHERE cliente = :cli AND voucherify_id = :customer
                      ORDER BY id
                      LIMIT 1
                      FOR UPDATE',
                    ['cli' => Db::cliente(), 'customer' => $movimiento['customer_id']]
                );

                $this->registrarMovimiento($movimiento, $transaccion, $detalles, $payload);

                if ($socio === null) {
                    Log::info('Movimiento de puntos de un cliente sin registro en el club; solo se guarda en el histórico', [
                        'transaccion' => $movimiento['transaction_id'],
                        'customer'    => $movimiento['customer_id'],
                        'evento'      => $idEvento,
                    ]);

                    return null;
                }

                return $this->actualizarSaldo($movimiento, (int) $socio['id'], $idEvento)
                    ? (string) $socio['email']
                    : null;
            }
        );

        if ($email === null) {
            return;
        }

        $this->notificar($email, $movimiento);
    }

    /**
     * Registra el movimiento en el histórico.
     *
     * ON CONFLICT DO NOTHING sobre (cliente, transaction_id): si el evento se
     * reprocesa, la fila ya existe y no se duplica. Se registra también cuando
     * el movimiento es anterior al último aplicado, porque el histórico tiene
     * que estar completo aunque el saldo no retroceda.
     *
     * @param array<string,mixed> $movimiento
     * @param array<string,mixed> $transaccion
     * @param array<string,mixed> $detalles
     * @param array<string,mixed> $payload
     */
    private function registrarMovimiento(array $movimiento, array $transaccion, array $detalles, array $payload): void
    {
        $recompensa = is_array($detalles['reward'] ?? null) ? $detalles['reward'] : [];

        $insertadas = Db::ejecutar(
            'INSERT INTO sm_movimientos_puntos (
                    cliente, transaction_id, customer_id, voucher_id,
                    tipo, puntos, saldo, total, tier,
                    reward_id, reward_name,
                    fecha, payload
             ) VALUES (
                    :cli, :transaction_id, :customer_id, :voucher_id,
                    :tipo, :puntos, :saldo, :total, :tier,
                    :reward_id, :reward_name,
                    :fecha, :payload
             )
             ON CONFLICT (cliente, transaction_id) DO NOTHING',
            [
                'cli'            => Db::cliente(),
                'transaction_id' => $movimiento['transaction_id'],
                'customer_id'    => $movimiento['customer_id'],
                'voucher_id'     => $transaccion['voucher_id'] ?? null,
                'tipo'           => $transaccion['type'] ?? null,
                // Voucherify ya lo envía con signo, negativo en un gasto. Se
                // copia tal cual.
                'puntos'         => $movimiento['puntos'],
                'saldo'          => $movimiento['saldo'],
                'total'          => $movimiento['total'],
                'tier'           => $movimiento['tier'],
                'reward_id'      => $recompensa['id'] ?? null,
                'reward_name'    => $recompensa['name'] ?? null,
                'fecha'          => $movimiento['fecha'],
                'payload'        => json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null,
            ]
        );

        if ($insertadas === 0) {
            Log::info('El movimiento de puntos ya estaba registrado; no se duplica', [
                'transaccion' => $movimiento['transaction_id'],
            ]);
        }
    }

    /**
     * Copia saldo, total y tier del movimiento a la fila del socio, salvo que
     * ya se haya registrado un movimiento posterior.
     *
     * GUARDA DE ORDEN
     * ---------------
     * Un reintento puede procesar un movimiento después de otro más reciente
     * del mismo cliente. Sin guarda, el saldo retrocedería. La guarda es la
     * fecha del movimiento, no total: un gasto no cambia total.
     *
     * El «>» estricto va DENTRO del NOT EXISTS: la condición completa es «la
     * fecha de este movimiento es >= la más reciente guardada». No debe
     * cambiarse por «>=»: el propio movimiento ya está en la tabla, porque se
     * inserta justo antes, y se bloquearía a sí mismo.
     *
     * rowCount() de un UPDATE cuenta las filas que cumplen el WHERE aunque los
     * valores no cambien. El reproceso del último movimiento devuelve 1 y
     * vuelve a avisar, que es lo que se quiere tras un fallo de SalesManago.
     *
     * @param array<string,mixed> $movimiento
     * @return bool true si se aplicó; false si hay un movimiento posterior
     */
    private function actualizarSaldo(array $movimiento, int $idSocio, int $idEvento): bool
    {
        $actualizadas = Db::ejecutar(
            'UPDATE sm_clientes
                SET saldo_puntos = :saldo,
                    total_puntos = :total,
                    tier = COALESCE(:tier, tier),
                    actualizado_en = now()
              WHERE id = :id
                AND NOT EXISTS (
                      SELECT 1
                        FROM sm_movimientos_puntos m
                       WHERE m.cliente = :cli
                         AND m.customer_id = :customer
                         AND m.fecha > :fecha
                    )',
            [
                'saldo'    => $movimiento['saldo'],
                'total'    => $movimiento['total'],
                // Con null se conserva el tier guardado.
                'tier'     => $movimiento['tier'],
                'id'       => $idSocio,
                'cli'      => Db::cliente(),
                'customer' => $movimiento['customer_id'],
                'fecha'    => $movimiento['fecha'],
            ]
        );

        if ($actualizadas === 0) {
            Log::info('Movimiento de puntos anterior al último aplicado; no se actualiza el saldo ni se avisa a SalesManago', [
                'transaccion' => $movimiento['transaction_id'],
                'evento'      => $idEvento,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Actualiza saldo, total y tier en el contacto de SalesManago.
     *
     * Las propiedades se llaman como los atributos que el original enviaba a
     * Blueshift (balance, total_points, tier). Sin forceOptIn ni
     * useApiDoubleOptIn: no se toca el consentimiento del contacto ni se envía
     * otro correo de confirmación. Por eso, y porque los valores son
     * absolutos, se reintentan también las respuestas ambiguas.
     *
     * @param array<string,mixed> $movimiento
     */
    private function notificar(string $email, array $movimiento): void
    {
        $propiedades = [
            'balance'      => (string) $movimiento['saldo'],
            'total_points' => (string) $movimiento['total'],
        ];

        // Sin tier se omite la propiedad, para no vaciar la que ya tenga el
        // contacto.
        if ($movimiento['tier'] !== null) {
            $propiedades['tier'] = $movimiento['tier'];
        }

        ApiSalesManago::upsertContacto(
            [
                'contact'    => ['email' => $email],
                'properties' => $propiedades,
            ],
            reintentarAmbiguos: true,
        );
    }
}
