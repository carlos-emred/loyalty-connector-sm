<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Guarda en sm_cupones un cupón de Voucherify asignado a un cliente y lanza el
 * evento externo de SalesManago que envía el correo.
 *
 * Lo comparten ManejadorCuponAsignado (voucher.published, cupón propio del
 * cliente) y ManejadorCuponGenerico (cupón genérico asignado desde
 * SalesManago), para que los dos traduzcan el cupón y avisen exactamente igual.
 *
 * UNA FILA POR CLIENTE Y CUPÓN
 * ----------------------------
 * La clave es (cliente, customer_id, voucher_id). Un cupón propio tiene un
 * solo titular, así que en la práctica es una fila por cupón. Un cupón
 * genérico tiene el mismo voucher_id para todos: una fila por cada cliente al
 * que se le asigna, marcada con generico = true.
 */
final class RegistroCupon
{
    /** Valor de detail1 con el que la automatización de SalesManago reconoce el evento. */
    private const MARCA_EVENTO = 'COMUNICAR_CUPON';

    /**
     * Tipo y valor del cupón, con los mismos criterios que el original
     * (v2/webhooks/añadir_cupon.php). Se calculan una sola vez para la base y
     * para el correo, para que no discrepen.
     *
     *   PERCENT  → porcentaje entero.
     *   AMOUNT   → céntimos, tal como los da Voucherify.
     *   SHIPPING → 100. Voucherify no tiene ese tipo: llega como UNIT con
     *              metadata.free_shipping = true.
     *   UNIT     → producto regalado. Sin valor numérico; va el nombre del
     *              producto.
     *
     * metadata.free_shipping sustituye a metadata.discount_type, que repetía
     * discount.type y solo servía para distinguir el envío gratis. Un cupón
     * que aún lleve discount_type = SHIPPING y no free_shipping se toma como
     * producto regalado (UNIT).
     *
     * @param array<string,mixed> $voucher
     * @return array{tipo: string, valor: ?int, producto: ?string}|null null si el tipo es desconocido
     */
    public static function clasificarDescuento(array $voucher): ?array
    {
        $descuento = is_array($voucher['discount'] ?? null) ? $voucher['discount'] : [];
        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];
        // Voucherify puede enviarlo como booleano o como texto ("true").
        $envioGratis = filter_var($metadata['free_shipping'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return match ($descuento['type'] ?? null) {
            'PERCENT' => ['tipo' => 'PERCENT', 'valor' => (int) ($descuento['percent_off'] ?? 0), 'producto' => null],
            'AMOUNT'  => ['tipo' => 'AMOUNT', 'valor' => (int) ($descuento['amount_off'] ?? 0), 'producto' => null],
            'UNIT'    => $envioGratis
                ? ['tipo' => 'SHIPPING', 'valor' => 100, 'producto' => null]
                : [
                    'tipo'     => 'UNIT',
                    'valor'    => null,
                    'producto' => isset($descuento['product']['name']) ? (string) $descuento['product']['name'] : null,
                ],
            default   => null,
        };
    }

    /**
     * Inserta el cupón o, si el cliente ya lo tenía, refresca los datos que
     * cambian en Voucherify. notificado_en no se toca aquí.
     *
     * ESTADO
     * ------
     * Un cupón nuevo entra como disponible, salvo que ya llegue agotado
     * (redeemed_quantity igual a quantity: canjeado) o caducado. Si ya estaba,
     * solo puede pasar de disponible a canjeado o caducado, nunca volver: un
     * voucher.published repetido no resucita un cupón consumido.
     *
     * redeemed_quantity nunca retrocede (GREATEST): si el canje se procesó
     * antes que un voucher.published reenviado, se conserva el del canje.
     *
     * @param array<string,mixed> $voucher El objeto voucher de Voucherify
     * @param array<string,mixed> $datos   Lo que lo acompaña; se usa campaign
     * @param array{tipo: string, valor: ?int, producto: ?string} $descuento De clasificarDescuento()
     */
    public static function guardar(
        array $voucher,
        array $datos,
        string $voucherId,
        string $codigo,
        string $customerId,
        array $descuento,
        bool $generico = false,
    ): void {
        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];
        $redencion = is_array($voucher['redemption'] ?? null) ? $voucher['redemption'] : [];
        $minimo = $metadata['minimum_purchase_quantity'] ?? null;
        // quantity a null en Voucherify significa usos ilimitados.
        $quantity = isset($redencion['quantity']) ? (int) $redencion['quantity'] : null;
        // En un genérico, redeemed_quantity es el de todos los clientes juntos;
        // para este cliente empieza en 0.
        $canjeados = $generico ? 0 : (int) ($redencion['redeemed_quantity'] ?? 0);
        $caducidad = $voucher['expiration_date'] ?? null;

        Db::ejecutar(
            'INSERT INTO sm_cupones (
                    voucher_id, cliente, customer_id, codigo, generico,
                    campaign_id, campaign_name, tipo, valor, producto,
                    importe_maximo, quantity, redeemed_quantity,
                    descripcion, descripcion_corta, image_url, minimo_compra,
                    solo_uno, product_id, category_id, category_name,
                    fecha_inicio, fecha_caducidad, creado_voucherify_en, estado
             ) VALUES (
                    :voucher_id, :cli, :customer_id, :codigo, :generico,
                    :campaign_id, :campaign_name, :tipo, :valor, :producto,
                    :maximo, :quantity, :redeemed,
                    :descripcion, :descripcion_corta, :image_url, :minimo,
                    :solo_uno, :product_id, :category_id, :category_name,
                    :inicio, :caducidad, :creado, :estado
             )
             ON CONFLICT (cliente, customer_id, voucher_id) DO UPDATE
                SET quantity = EXCLUDED.quantity,
                    redeemed_quantity = GREATEST(sm_cupones.redeemed_quantity, EXCLUDED.redeemed_quantity),
                    fecha_caducidad = EXCLUDED.fecha_caducidad,
                    estado = CASE
                        WHEN sm_cupones.estado <> \'disponible\' THEN sm_cupones.estado
                        WHEN EXCLUDED.quantity IS NOT NULL
                         AND GREATEST(sm_cupones.redeemed_quantity, EXCLUDED.redeemed_quantity) >= EXCLUDED.quantity
                            THEN \'canjeado\'
                        WHEN EXCLUDED.fecha_caducidad IS NOT NULL AND EXCLUDED.fecha_caducidad <= now()
                            THEN \'caducado\'
                        ELSE sm_cupones.estado
                    END,
                    actualizado_en = now()',
            [
                'voucher_id'        => $voucherId,
                'cli'               => Db::cliente(),
                'customer_id'       => $customerId,
                'codigo'            => $codigo,
                'generico'          => $generico ? 'true' : 'false',
                'campaign_id'       => $voucher['campaign_id'] ?? null,
                'campaign_name'     => $voucher['campaign'] ?? ($datos['campaign']['name'] ?? null),
                'tipo'              => $descuento['tipo'],
                'valor'             => $descuento['valor'],
                'producto'          => $descuento['producto'],
                'maximo'            => isset($voucher['discount']['amount_limit'])
                    ? (int) $voucher['discount']['amount_limit']
                    : null,
                'quantity'          => $quantity,
                'redeemed'          => $canjeados,
                'descripcion'       => $metadata['description'] ?? null,
                'descripcion_corta' => $datos['campaign']['description'] ?? null,
                'image_url'         => $metadata['image_url'] ?? null,
                'minimo'            => is_numeric($minimo) ? (int) $minimo : null,
                'solo_uno'          => isset($metadata['only_one']) ? self::texto($metadata['only_one']) : null,
                'product_id'        => isset($metadata['product_id']) ? self::texto($metadata['product_id']) : null,
                'category_id'       => isset($metadata['category_id']) ? self::texto($metadata['category_id']) : null,
                'category_name'     => $metadata['category_name'] ?? null,
                'inicio'            => $voucher['start_date'] ?? null,
                'caducidad'         => $caducidad,
                'creado'            => $voucher['created_at'] ?? null,
                'estado'            => self::estadoInicial($quantity, $canjeados, $caducidad),
            ]
        );
    }

    /**
     * Lanza el evento externo (detail1 = COMUNICAR_CUPON) en el contacto de
     * SalesManago, que es lo que dispara el correo, y marca notificado_en.
     *
     * SIN CORREOS DUPLICADOS
     * ----------------------
     * Cada evento externo es un correo. Antes de lanzarlo se mira
     * sm_cupones.notificado_en, y se escribe justo después: un reproceso no lo
     * reenvía. Queda una ventana de milisegundos (el proceso muere entre la
     * respuesta de SalesManago y el UPDATE), la misma que acepta el conector
     * de Blueshift.
     *
     * @param array<string,mixed> $voucher
     * @param array<string,mixed> $datos   Lo que acompaña al voucher; se usa campaign
     * @param array{tipo: string, valor: ?int, producto: ?string} $descuento De clasificarDescuento()
     *
     * @throws \RuntimeException si SalesManago no confirma; el worker reintenta
     */
    public static function notificar(
        array $voucher,
        array $datos,
        string $email,
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

        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];

        // Posiciones de detail1..detail10 tal como las definía el original
        // (v2/webhooks/añadir_cupon.php): la plantilla del correo en
        // SalesManago se apoya en ellas. detail11 es nuevo y lleva el código,
        // que el original ponía en «value».
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
            $evento['description'] = self::texto($metadata['description']);
        }

        // Los detalles son texto en SalesManago. Los vacíos se omiten en lugar
        // de enviarse como null.
        foreach ($detalles as $clave => $valor) {
            if ($valor !== null && $valor !== '') {
                $evento[$clave] = self::texto($valor);
            }
        }

        ApiSalesManago::anadirEventoExterno($email, $evento);

        Db::ejecutar(
            'UPDATE sm_cupones SET notificado_en = now(), actualizado_en = now()
              WHERE cliente = :cli AND customer_id = :customer AND voucher_id = :id',
            ['cli' => Db::cliente(), 'customer' => $customerId, 'id' => $voucherId]
        );
    }

    public static function texto(mixed $valor): string
    {
        return match (true) {
            is_bool($valor)   => $valor ? 'true' : 'false',
            is_scalar($valor) => (string) $valor,
            default           => (string) json_encode($valor, JSON_UNESCAPED_UNICODE),
        };
    }

    /**
     * Estado de un cupón que entra en sm_cupones: canjeado si ya no le quedan
     * usos, caducado si ya pasó su fecha, disponible en otro caso.
     *
     * La comprobación de null en quantity va explícita: null es «usos
     * ilimitados», y en PHP 0 >= null da true.
     */
    private static function estadoInicial(?int $quantity, int $canjeados, mixed $caducidad): string
    {
        if ($quantity !== null && $canjeados >= $quantity) {
            return 'canjeado';
        }

        if (is_string($caducidad) && $caducidad !== '') {
            $fecha = strtotime($caducidad);

            if ($fecha !== false && $fecha <= time()) {
                return 'caducado';
            }
        }

        return 'disponible';
    }
}
