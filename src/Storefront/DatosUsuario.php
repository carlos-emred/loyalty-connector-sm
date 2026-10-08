<?php

declare(strict_types=1);

namespace SalesManago\Storefront;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Datos de fidelización, cupones válidos e historial de puntos de un cliente,
 * con la forma que devolvía v2/obtener_datos_usuario.php.
 *
 * Copia adaptada de Loyalty\Storefront\DescuentosUsuario del conector de
 * Blueshift. Todo sale de PostgreSQL: no se llama a Voucherify ni a nadie, y
 * no se escribe nada.
 *
 * LA FORMA DE LA RESPUESTA ES UN CONTRATO
 * ---------------------------------------
 * Claves, anidamiento y orden de las listas reproducen los del original, que
 * leía cupones/{id}.json y puntos/{id}.json. La web depende de ellos.
 *
 * CUPONES VÁLIDOS
 * ---------------
 * El original borraba del fichero los cupones caducados. Aquí no se borra
 * nada: los que no son válidos simplemente no se devuelven. Un cupón deja de
 * serlo si:
 *
 *   - ha caducado (fecha_caducidad pasada),
 *   - todavía no ha empezado (fecha_inicio futura),
 *   - ha agotado sus usos (redeemed_quantity >= quantity).
 *
 * Los datos son los que llegaron con voucher.published. sm_cupones no sabe de
 * canjes ni de bajas posteriores en Voucherify: un cupón canjeado sigue
 * apareciendo hasta que algo actualice redeemed_quantity.
 *
 * SIN DATOS PERSONALES
 * --------------------
 * custom_attributes era la ficha completa de Blueshift, con DNI, teléfono y
 * fecha de nacimiento. Solo lleva los datos de fidelización: el endpoint
 * responde a cualquiera que conozca un correo.
 */
final class DatosUsuario
{
    /**
     * Zona de las fechas del historial. Los scripts originales las escribían
     * con date() en la zona de la tienda.
     */
    private const ZONA_HISTORIAL = 'Europe/Madrid';

    /** Ventana del historial que se devuelve; los movimientos no se borran. */
    private const MESES_HISTORIAL = 10;

    /**
     * @return array<string,mixed>|null null si el correo no está en sm_clientes
     */
    public static function paraCorreo(string $email): ?array
    {
        $cliente = Db::fila(
            'SELECT voucherify_id, saldo_puntos, total_puntos, tier, loyalty_card
               FROM sm_clientes
              WHERE cliente = :cli AND email = :email',
            ['cli' => Db::cliente(), 'email' => mb_strtolower(trim($email))]
        );

        if ($cliente === null) {
            return null;
        }

        $customerId = isset($cliente['voucherify_id']) ? (string) $cliente['voucherify_id'] : '';

        if ($customerId === '') {
            // Registro a medias: Voucherify no llegó a confirmar el alta. No
            // hay cupones ni movimientos que buscar.
            Log::warning('obtener_datos_usuario: cliente sin voucherify_id; se devuelve sin cupones ni historial');
        }

        $historial = $customerId !== '' ? self::historial($customerId) : [];
        [$saldo, $total] = self::puntos($cliente, $customerId);
        $tier = isset($cliente['tier']) ? (string) $cliente['tier'] : null;
        $tarjeta = isset($cliente['loyalty_card']) && $cliente['loyalty_card'] !== ''
            ? (string) $cliente['loyalty_card']
            : null;

        return [
            'custom_attributes' => [
                'customerIdVoucherify' => $customerId !== '' ? $customerId : null,
                'balance'              => $saldo,
                'total_points'         => $total,
                'tier'                 => $tier,
                'loyalty_card'         => $tarjeta,
                'loyalty_campaign'     => null,
            ],
            'balance'          => $saldo,
            'total_points'     => $total,
            'loyalty_card'     => $tarjeta,
            // sm_clientes no guarda la campaña de la tarjeta. Se mantiene la
            // clave para no romper la web.
            'loyalty_campaign' => null,
            'cupones'          => $customerId !== '' ? self::cupones($customerId) : [],
            'historial'        => $historial,
        ];
    }

    /**
     * Saldo y total de sm_clientes. Si aún no están (ningún flujo los rellena
     * todavía), los del último movimiento; sin movimientos, 0.
     *
     * @param array<string,mixed> $cliente
     * @return array{0: int, 1: int}
     */
    private static function puntos(array $cliente, string $customerId): array
    {
        $saldo = $cliente['saldo_puntos'] ?? null;
        $total = $cliente['total_puntos'] ?? null;

        if (($saldo === null || $total === null) && $customerId !== '') {
            $ultimo = Db::fila(
                'SELECT saldo, total FROM sm_movimientos_puntos
                  WHERE cliente = :cli AND customer_id = :customer
                  ORDER BY fecha DESC, id DESC
                  LIMIT 1',
                ['cli' => Db::cliente(), 'customer' => $customerId]
            );

            $saldo ??= $ultimo['saldo'] ?? null;
            $total ??= $ultimo['total'] ?? null;
        }

        return [(int) ($saldo ?? 0), (int) ($total ?? 0)];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function cupones(string $customerId): array
    {
        // Del más antiguo al más reciente, como crecía cupones/{id}.json.
        $filas = Db::filas(
            'SELECT codigo, tipo, valor, producto, importe_maximo,
                    quantity, redeemed_quantity, descripcion, descripcion_corta,
                    image_url, category_name, fecha_inicio, fecha_caducidad,
                    creado_voucherify_en, creado_en, metadata
               FROM sm_cupones
              WHERE cliente = :cli
                AND customer_id = :customer
                AND (fecha_caducidad IS NULL OR fecha_caducidad > now())
                AND (fecha_inicio IS NULL OR fecha_inicio <= now())
                AND (quantity IS NULL OR redeemed_quantity < quantity)
              ORDER BY creado_en, voucher_id',
            ['cli' => Db::cliente(), 'customer' => $customerId]
        );

        // array_values: json_encode convierte en objeto un array con claves
        // no consecutivas, y la web espera una lista.
        return array_values(array_map(self::formatearCupon(...), $filas));
    }

    /**
     * Las 17 claves que escribía el original en cupones/{id}.json.
     *
     * @param array<string,mixed> $fila
     * @return array<string,mixed>
     */
    private static function formatearCupon(array $fila): array
    {
        // Estos campos se leen del metadata en bruto y no de sus columnas, que
        // los guardan como texto: la web recibía el valor tal cual lo enviaba
        // Voucherify.
        $metadata = self::metadata($fila['metadata'] ?? null);

        return [
            'code'                      => (string) $fila['codigo'],
            'type'                      => (string) $fila['tipo'],
            // count del original: céntimos, porcentaje, 100 en envío gratis o
            // el nombre del producto si es un regalo (UNIT).
            'count'                     => $fila['valor'] !== null ? (int) $fila['valor'] : ($fila['producto'] ?? null),
            'maxCount'                  => isset($fila['importe_maximo']) ? (int) $fila['importe_maximo'] : null,
            'category_id'               => $metadata['category_id'] ?? null,
            'category_name'             => $metadata['category_name'] ?? ($fila['category_name'] ?? null),
            'product_id'                => $metadata['product_id'] ?? null,
            'only_one'                  => $metadata['only_one'] ?? null,
            'minimum_purchase_quantity' => $metadata['minimum_purchase_quantity'] ?? null,
            // null significa usos ilimitados y no debe acabar en 0.
            'quantity'                  => isset($fila['quantity']) ? (int) $fila['quantity'] : null,
            'redeemed_quantity'         => (int) $fila['redeemed_quantity'],
            'short_description'         => $fila['descripcion_corta'] ?? null,
            'description'               => $fila['descripcion'] ?? null,
            'image_url'                 => $fila['image_url'] ?? null,
            'start_date'                => self::isoUtc($fila['fecha_inicio'] ?? null),
            'expiration_date'           => self::isoUtc($fila['fecha_caducidad'] ?? null),
            'create_date'               => self::isoUtc($fila['creado_voucherify_en'] ?? $fila['creado_en'] ?? null),
        ];
    }

    /**
     * Movimientos de los últimos MESES_HISTORIAL meses, del más antiguo al más
     * reciente, con las 5 claves de puntos/{id}.json.
     *
     * @return list<array<string,mixed>>
     */
    private static function historial(string $customerId): array
    {
        // El intervalo va en el texto de la consulta y no como parámetro: es
        // una constante entera, y así no depende de cómo tipe PDO el valor.
        $filas = Db::filas(
            sprintf(
                "SELECT fecha, puntos, saldo, total, tier
                   FROM sm_movimientos_puntos
                  WHERE cliente = :cli
                    AND customer_id = :customer
                    AND fecha >= now() - interval '%d months'
                  ORDER BY fecha, id",
                self::MESES_HISTORIAL
            ),
            ['cli' => Db::cliente(), 'customer' => $customerId]
        );

        return array_values(array_map(
            static fn (array $fila): array => [
                'balance'     => (int) $fila['saldo'],
                'total'       => (int) $fila['total'],
                'tier'        => isset($fila['tier']) ? (string) $fila['tier'] : null,
                'transaction' => (int) $fila['puntos'],
                'fecha'       => self::fechaHistorial($fila['fecha'] ?? null),
            ],
            $filas
        ));
    }

    /**
     * pdo_pgsql devuelve jsonb como texto.
     *
     * @return array<string,mixed>
     */
    private static function metadata(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $datos = json_decode($json, true);

        return is_array($datos) ? $datos : [];
    }

    /** Formato en que Voucherify envía las fechas: 2026-09-15T00:00:00.000Z. */
    private static function isoUtc(mixed $marca): ?string
    {
        return self::marcaDeTiempo($marca)
            ?->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Formato date('Y-m-d H:i:s') con que se fechaba cada movimiento. */
    private static function fechaHistorial(mixed $marca): ?string
    {
        return self::marcaDeTiempo($marca)
            ?->setTimezone(new DateTimeZone(self::ZONA_HISTORIAL))
            ->format('Y-m-d H:i:s');
    }

    /**
     * pdo_pgsql devuelve timestamptz como texto con el desfase de la sesión
     * («2026-09-15 02:00:00+02»). Se interpreta con ese desfase para no
     * depender de la zona configurada en PostgreSQL.
     */
    private static function marcaDeTiempo(mixed $marca): ?DateTimeImmutable
    {
        if (!is_string($marca) || trim($marca) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($marca);
        } catch (Exception) {
            Log::warning('Marca de tiempo no interpretable; se devuelve null', ['valor' => $marca]);

            return null;
        }
    }
}
