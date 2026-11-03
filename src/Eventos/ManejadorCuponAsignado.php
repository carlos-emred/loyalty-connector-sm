<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;
use RuntimeException;

/**
 * Atiende voucher.published: Voucherify ha asignado un cupón a un cliente.
 *
 * Sustituye a v2/webhooks/añadir_cupon.php. Hace dos cosas, en este orden:
 *
 *   1. Registra el cupón en sm_cupones (RegistroCupon), en lugar del fichero
 *      cupones/{customer_id}.json del original.
 *   2. Lanza un evento externo (detail1 = COMUNICAR_CUPON) en el contacto de
 *      SalesManago. La automatización configurada allí envía el correo.
 *
 * La base va primero porque no depende de nadie. Si SalesManago falla, se
 * lanza excepción y el worker reintenta; el segundo intento actualiza la misma
 * fila sin duplicarla.
 *
 * SIN CORREOS DUPLICADOS
 * ----------------------
 * Cada evento externo es un correo. Antes de lanzarlo se mira
 * sm_cupones.notificado_en, y se escribe justo después: un reproceso del mismo
 * evento, o un segundo voucher.published del mismo cupón, no lo reenvía. Queda
 * una ventana de milisegundos (el proceso muere entre la respuesta de
 * SalesManago y el UPDATE), la misma que acepta el conector de Blueshift.
 *
 * TARJETAS DE FIDELIZACIÓN
 * ------------------------
 * voucher.published también llega cuando se asigna una LOYALTY_CARD. No es un
 * cupón: su código se guarda ÚNICAMENTE en sm_clientes.loyalty_card, y su
 * campaign_id en sm_clientes.loyalty_campaign. No pasa por sm_cupones ni se
 * avisa a SalesManago. El original la enviaba a
 * Blueshift con un identify, código heredado que en SalesManago no aplica.
 * Ver guardarTarjeta().
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Enviaba la clave del conector (API_KEY) como apiKey de SalesManago, y una
 *   constante ENDPOINT que no existía: no podía funcionar. Las credenciales
 *   salen ahora del .env.
 * - La fecha del evento era fija (1791288272000). Ahora es el momento del
 *   envío.
 * - Ponía el código del cupón en «value», que en SalesManago es numérico. El
 *   código va en detail11; «value» no se envía.
 * - Volcaba el cuerpo del webhook, con correo y datos del cliente, en un .txt
 *   del docroot. Ahora va a Log, sin datos personales.
 */
final class ManejadorCuponAsignado implements ManejadorEvento
{
    /** Valor de detail1 con el que la automatización de SalesManago reconoce el evento. */
    private const MARCA_EVENTO = 'COMUNICAR_CUPON';

    public function tipos(): array
    {
        return ['voucher.published'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $datos = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $voucher = is_array($datos['voucher'] ?? null) ? $datos['voucher'] : [];
        $cliente = is_array($datos['customer'] ?? null) ? $datos['customer'] : [];

        $voucherId = trim((string) ($voucher['id'] ?? ''));
        $codigo = trim((string) ($voucher['code'] ?? ''));
        $customerId = trim((string) ($cliente['id'] ?? $voucher['holder_id'] ?? ''));

        if ($voucherId === '' || $codigo === '' || $customerId === '') {
            // Payload inservible. No se lanza excepción: reintentarlo no lo
            // va a arreglar.
            Log::error('voucher.published sin voucher, código o cliente; se ignora', [
                'evento' => $idEvento,
            ]);

            return;
        }

        if (($voucher['type'] ?? null) === 'LOYALTY_CARD') {
            $campana = trim((string) ($voucher['campaign_id'] ?? ''));

            $this->guardarTarjeta($cliente, $codigo, $campana !== '' ? $campana : null, $customerId);

            Log::info('Tarjeta de fidelización guardada en sm_clientes', [
                'evento'   => $idEvento,
                'voucher'  => $voucherId,
                'customer' => $customerId,
            ]);

            return;
        }

        $descuento = RegistroCupon::clasificarDescuento($voucher);

        if ($descuento === null) {
            Log::error('voucher.published con un tipo de descuento desconocido; no se guarda ni se avisa', [
                'evento'  => $idEvento,
                'voucher' => $voucherId,
                'tipo'    => $voucher['discount']['type'] ?? '(sin tipo)',
            ]);

            return;
        }

        // La base va primero: no depende de nadie.
        RegistroCupon::guardar($voucher, $datos, $voucherId, $codigo, $customerId, $descuento);
        $this->notificar($voucher, $datos, $cliente, $voucherId, $codigo, $customerId, $descuento);

        Log::info('Cupón asignado registrado', [
            'evento'   => $idEvento,
            'voucher'  => $voucherId,
            'customer' => $customerId,
            'tipo'     => $descuento['tipo'],
        ]);
    }

    /**
     * Guarda el código de la tarjeta en sm_clientes.loyalty_card y su campaña
     * en loyalty_campaign.
     *
     * Un payload sin campaign_id no borra la campaña que ya se conocía
     * (COALESCE), como en el conector de Blueshift.
     *
     * Con correo en el payload, upsert por correo: si el cliente aún no tiene
     * fila (no pasó por registro_club.php, o el webhook llega antes de que
     * registro_club.php guarde su voucherify_id), se crea con lo mínimo. Esa
     * fila tiene sincronizado_en a NULL, así que no impide que el socio se
     * registre después; el registro la completa y conserva la tarjeta.
     *
     * Sin correo, solo se puede localizar al cliente por su id de Voucherify.
     * Si todavía no hay fila con ese id, puede ser que registro_club.php no lo
     * haya guardado aún: se lanza excepción para que el worker lo reintente
     * más tarde.
     *
     * @param array<string,mixed> $cliente
     *
     * @throws RuntimeException si no hay fila a la que asignar la tarjeta
     */
    private function guardarTarjeta(array $cliente, string $codigo, ?string $campana, string $customerId): void
    {
        $email = mb_strtolower(trim((string) ($cliente['email'] ?? '')));

        if ($email !== '') {
            Db::ejecutar(
                'INSERT INTO sm_clientes (cliente, email, voucherify_id, loyalty_card, loyalty_campaign)
                 VALUES (:cli, :email, :customer, :tarjeta, :campana)
                 ON CONFLICT (cliente, email) DO UPDATE
                    SET loyalty_card = EXCLUDED.loyalty_card,
                        loyalty_campaign = COALESCE(EXCLUDED.loyalty_campaign, sm_clientes.loyalty_campaign),
                        voucherify_id = COALESCE(sm_clientes.voucherify_id, EXCLUDED.voucherify_id),
                        actualizado_en = now()',
                [
                    'cli'      => Db::cliente(),
                    'email'    => $email,
                    'customer' => $customerId,
                    'tarjeta'  => $codigo,
                    'campana'  => $campana,
                ]
            );

            return;
        }

        $filas = Db::ejecutar(
            'UPDATE sm_clientes
                SET loyalty_card = :tarjeta,
                    loyalty_campaign = COALESCE(:campana, loyalty_campaign),
                    actualizado_en = now()
              WHERE cliente = :cli AND voucherify_id = :customer',
            [
                'cli'      => Db::cliente(),
                'customer' => $customerId,
                'tarjeta'  => $codigo,
                'campana'  => $campana,
            ]
        );

        if ($filas === 0) {
            throw new RuntimeException(
                'Tarjeta de fidelización sin correo y sin cliente con ese id de Voucherify en sm_clientes; se reintentará'
            );
        }
    }

    /**
     * Lanza el evento externo en SalesManago, que es lo que dispara el correo.
     *
     * @param array<string,mixed> $voucher
     * @param array<string,mixed> $datos
     * @param array<string,mixed> $cliente
     * @param array{tipo: string, valor: ?int, producto: ?string} $descuento
     *
     * @throws \RuntimeException si SalesManago no confirma; el worker reintenta
     */
    private function notificar(
        array $voucher,
        array $datos,
        array $cliente,
        string $voucherId,
        string $codigo,
        string $customerId,
        array $descuento,
    ): void {
        $yaNotificado = Db::valor(
            'SELECT notificado_en FROM sm_cupones
              WHERE cliente = :cli AND customer_id = :customer AND voucher_id = :id',
            ['cli' => Db::cliente(), 'customer' => $customerId, 'id' => $voucherId]
        );

        if ($yaNotificado !== null) {
            Log::info('El evento del cupón ya se envió a SalesManago; no se repite', [
                'voucher' => $voucherId,
            ]);

            return;
        }

        $email = $this->correoDelCliente($cliente, $customerId);

        if ($email === null) {
            // Sin correo no hay contacto al que avisar, y reintentar no lo
            // arreglará.
            Log::warning('Cupón sin correo asociado; no se avisa a SalesManago', [
                'voucher'  => $voucherId,
                'customer' => $customerId,
            ]);

            return;
        }

        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];

        // Posiciones de detail1..detail10 tal como las definía el original: la
        // plantilla del correo en SalesManago se apoya en ellas. detail11 es
        // nuevo y lleva el código, que el original ponía en «value».
        $detalles = [
            'detail1'  => self::MARCA_EVENTO,
            'detail2'  => $datos['campaign']['description'] ?? null,
            'detail3'  => $metadata['image_url'] ?? null,
            'detail4'  => $voucher['expiration_date'] ?? null,
            'detail5'  => $voucher['start_date'] ?? null,
            'detail6'  => $descuento['tipo'],
            'detail7'  => $metadata['category_id'] ?? null,
            'detail8'  => $metadata['category_name'] ?? null,
            'detail9'  => $metadata['product_id'] ?? null,
            'detail10' => $descuento['valor'] ?? $descuento['producto'],
            'detail11' => $codigo,
        ];

        $evento = [
            'date'                => (int) (microtime(true) * 1000),
            'contactExtEventType' => 'OTHER',
            // El id del voucher identifica el evento en SalesManago: permite
            // localizarlo y cruzarlo con sm_cupones.
            'externalId'          => $voucherId,
        ];

        if (isset($metadata['description']) && $metadata['description'] !== '') {
            $evento['description'] = RegistroCupon::texto($metadata['description']);
        }

        // Los detalles son texto en SalesManago. Los vacíos se omiten en lugar
        // de enviarse como null.
        foreach ($detalles as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                $evento[$clave] = RegistroCupon::texto($valor);
            }
        }

        ApiSalesManago::anadirEventoExterno($email, $evento);

        Db::ejecutar(
            'UPDATE sm_cupones SET notificado_en = now(), actualizado_en = now()
              WHERE cliente = :cli AND customer_id = :customer AND voucher_id = :id',
            ['cli' => Db::cliente(), 'customer' => $customerId, 'id' => $voucherId]
        );
    }

    /**
     * El correo del payload; si no lo trae, el de sm_clientes por el id de
     * Voucherify, que registro_club.php guarda al dar de alta al socio.
     *
     * @param array<string,mixed> $cliente
     */
    private function correoDelCliente(array $cliente, string $customerId): ?string
    {
        $email = mb_strtolower(trim((string) ($cliente['email'] ?? '')));

        if ($email !== '') {
            return $email;
        }

        $email = Db::valor(
            'SELECT email FROM sm_clientes WHERE cliente = :cli AND voucherify_id = :customer
              ORDER BY actualizado_en DESC LIMIT 1',
            ['cli' => Db::cliente(), 'customer' => $customerId]
        );

        return is_string($email) && $email !== '' ? $email : null;
    }
}
