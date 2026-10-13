<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende redemption.succeeded: un cliente ha canjeado un cupón.
 *
 * Sustituye a v2/webhooks/consumir_cupon.php. Actualiza los usos del cupón en
 * sm_cupones. No avisa a nadie.
 *
 * NO SE BORRA: PASA A CANJEADO
 * ----------------------------
 * quantity es el número de veces que se puede consumir el cupón y
 * redeemed_quantity las que ya se ha consumido. Cuando son iguales, no se
 * puede consumir más.
 *
 * El original quitaba el cupón del fichero en su último uso. Aquí nada se
 * borra de la base: el cupón pasa a estado 'canjeado' y
 * obtener_datos_usuario.php, que solo devuelve los disponibles, deja de
 * ofrecerlo. Si no era el último uso, sigue disponible con el contador
 * actualizado, como en el original.
 *
 * Un cupón con quantity null es de usos ilimitados: nunca se agota.
 *
 * EL CONTADOR SALE DE VOUCHERIFY
 * ------------------------------
 * El original sumaba uno a su contador. Aquí se toma redeemed_quantity del
 * payload, que es el valor real de Voucherify: si un webhook llega dos veces o
 * se pierde uno, el contador no se desvía. GREATEST evita que un evento
 * antiguo procesado tarde haga retroceder el contador.
 *
 * Solo si el payload no trae el contador se suma uno, como el original.
 *
 * SIN EXCEPCIONES
 * ---------------
 * Un payload sin cupón o un cupón que no está en sm_cupones (porque no llegó su
 * voucher.published, o es una tarjeta de fidelización) no mejora al
 * reintentarlo: se registra y se termina.
 */
final class ManejadorCuponConsumido implements ManejadorEvento
{
    public function tipos(): array
    {
        return ['redemption.succeeded'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $datos = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $canje = is_array($datos['redemption'] ?? null) ? $datos['redemption'] : [];

        // El canje lleva dentro su propia copia del cupón y del cliente. Se
        // prefieren las de primer nivel.
        $voucher = is_array($datos['voucher'] ?? null) ? $datos['voucher'] : (is_array($canje['voucher'] ?? null) ? $canje['voucher'] : []);
        $cliente = is_array($datos['customer'] ?? null) ? $datos['customer'] : [];

        $voucherId = trim((string) ($voucher['id'] ?? ''));
        $codigo = trim((string) ($voucher['code'] ?? ''));
        $customerId = trim((string) ($cliente['id'] ?? $canje['customer_id'] ?? $voucher['holder_id'] ?? ''));

        if (($voucherId === '' && $codigo === '') || $customerId === '') {
            Log::error('redemption.succeeded sin cupón o sin cliente; se ignora', ['evento' => $idEvento]);

            return;
        }

        if (($voucher['type'] ?? null) === 'LOYALTY_CARD') {
            // Canje de puntos con la tarjeta de fidelización: no es un cupón
            // de sm_cupones.
            Log::info('redemption.succeeded de tarjeta de fidelización; no es un cupón, se ignora', [
                'evento' => $idEvento,
            ]);

            return;
        }

        $fila = $this->actualizarUsos($voucher, $voucherId, $codigo, $customerId, $idEvento);

        if ($fila === null) {
            Log::warning('Canje de un cupón que no está en sm_cupones; no se actualiza', [
                'evento'   => $idEvento,
                'voucher'  => $voucherId !== '' ? $voucherId : null,
                'customer' => $customerId,
            ]);

            return;
        }

        $quantity = $fila['quantity'] !== null ? (int) $fila['quantity'] : null;
        $usados = (int) $fila['redeemed_quantity'];
        $agotado = $quantity !== null && $usados >= $quantity;

        if ($agotado) {
            // Solo desde disponible: uno caducado conserva su estado.
            Db::ejecutar(
                "UPDATE sm_cupones
                    SET estado = 'canjeado', actualizado_en = now()
                  WHERE cliente = :cli
                    AND customer_id = :customer
                    AND voucher_id = :voucher
                    AND estado = 'disponible'",
                ['cli' => Db::cliente(), 'customer' => $customerId, 'voucher' => $fila['voucher_id']]
            );
        }

        Log::info(
            $agotado
                ? 'Cupón consumido por última vez; pasa a canjeado'
                : 'Uso de cupón registrado',
            [
                'evento'   => $idEvento,
                'voucher'  => $fila['voucher_id'],
                'usados'   => $usados,
                'quantity' => $quantity,
            ]
        );
    }

    /**
     * Actualiza el contador del cupón del cliente y devuelve cómo queda, o null
     * si no está en sm_cupones.
     *
     * @param array<string,mixed> $voucher
     * @return array<string,mixed>|null
     */
    private function actualizarUsos(
        array $voucher,
        string $voucherId,
        string $codigo,
        string $customerId,
        int $idEvento,
    ): ?array {
        // Ojo: data.campaign.voucher.redemption también trae contadores, pero
        // son los de la plantilla de la campaña. Los del cupón canjeado son
        // estos.
        $redencion = is_array($voucher['redemption'] ?? null) ? $voucher['redemption'] : [];
        $parametros = ['cli' => Db::cliente(), 'customer' => $customerId];

        if (isset($redencion['redeemed_quantity']) && is_numeric($redencion['redeemed_quantity'])) {
            $set = 'redeemed_quantity = GREATEST(redeemed_quantity, :usados)';
            $parametros['usados'] = (int) $redencion['redeemed_quantity'];
        } else {
            Log::warning('Canje sin redeemed_quantity en el payload; se suma un uso', ['evento' => $idEvento]);
            $set = 'redeemed_quantity = redeemed_quantity + 1';
        }

        // quantity solo si el payload la trae: null en Voucherify es «usos
        // ilimitados», que no es lo mismo que no saberlo.
        if (array_key_exists('quantity', $redencion)) {
            $set .= ', quantity = :quantity';
            $parametros['quantity'] = is_numeric($redencion['quantity']) ? (int) $redencion['quantity'] : null;
        }

        if ($voucherId !== '') {
            $condicion = 'voucher_id = :voucher';
            $parametros['voucher'] = $voucherId;
        } else {
            $condicion = 'codigo = :codigo';
            $parametros['codigo'] = $codigo;
        }

        return Db::fila(
            "UPDATE sm_cupones
                SET {$set},
                    actualizado_en = now()
              WHERE cliente = :cli
                AND customer_id = :customer
                AND {$condicion}
          RETURNING voucher_id, redeemed_quantity, quantity",
            $parametros
        );
    }
}
