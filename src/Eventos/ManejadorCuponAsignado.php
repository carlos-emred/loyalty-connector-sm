<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende voucher.published: Voucherify ha asignado un cupón a un cliente.
 *
 * Sustituye a v2/webhooks/añadir_cupon.php. Hace dos cosas, en este orden:
 *
 *   1. Registra el cupón en sm_cupones, en lugar del fichero
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
 * cupón: se registra en el log y se descarta. El original la enviaba a
 * Blueshift con un identify, código heredado que en SalesManago no aplica.
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
            Log::info('voucher.published de tarjeta de fidelización; no es un cupón, se ignora', [
                'evento'  => $idEvento,
                'voucher' => $voucherId,
            ]);

            return;
        }

        $descuento = self::clasificarDescuento($voucher);

        if ($descuento === null) {
            Log::error('voucher.published con un tipo de descuento desconocido; no se guarda ni se avisa', [
                'evento'  => $idEvento,
                'voucher' => $voucherId,
                'tipo'    => $voucher['discount']['type'] ?? '(sin tipo)',
            ]);

            return;
        }

        $this->registrarCupon($voucher, $datos, $voucherId, $codigo, $customerId, $descuento);
        $this->notificar($voucher, $datos, $cliente, $voucherId, $codigo, $customerId, $descuento);

        Log::info('Cupón asignado registrado', [
            'evento'   => $idEvento,
            'voucher'  => $voucherId,
            'customer' => $customerId,
            'tipo'     => $descuento['tipo'],
        ]);
    }

    /**
     * Tipo y valor del cupón, con los mismos criterios que el original. Se
     * calculan una sola vez para la base y para el correo, para que no
     * discrepen.
     *
     *   PERCENT  → porcentaje entero.
     *   AMOUNT   → céntimos, tal como los da Voucherify.
     *   SHIPPING → 100. Voucherify no tiene ese tipo: llega como UNIT con
     *              metadata.discount_type = SHIPPING.
     *   UNIT     → producto regalado. Sin valor numérico; va el nombre del
     *              producto.
     *
     * @param array<string,mixed> $voucher
     * @return array{tipo: string, valor: ?int, producto: ?string}|null
     */
    private static function clasificarDescuento(array $voucher): ?array
    {
        $descuento = is_array($voucher['discount'] ?? null) ? $voucher['discount'] : [];
        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];

        return match ($descuento['type'] ?? null) {
            'PERCENT' => ['tipo' => 'PERCENT', 'valor' => (int) ($descuento['percent_off'] ?? 0), 'producto' => null],
            'AMOUNT'  => ['tipo' => 'AMOUNT', 'valor' => (int) ($descuento['amount_off'] ?? 0), 'producto' => null],
            'UNIT'    => ($metadata['discount_type'] ?? null) === 'SHIPPING'
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
     * Inserta el cupón o, si ya estaba, refresca los datos que cambian en
     * Voucherify. notificado_en no se toca aquí.
     *
     * @param array<string,mixed> $voucher
     * @param array<string,mixed> $datos
     * @param array{tipo: string, valor: ?int, producto: ?string} $descuento
     */
    private function registrarCupon(
        array $voucher,
        array $datos,
        string $voucherId,
        string $codigo,
        string $customerId,
        array $descuento,
    ): void {
        $metadata = is_array($voucher['metadata'] ?? null) ? $voucher['metadata'] : [];
        $redencion = is_array($voucher['redemption'] ?? null) ? $voucher['redemption'] : [];
        $minimo = $metadata['minimum_purchase_quantity'] ?? null;

        Db::ejecutar(
            'INSERT INTO sm_cupones (
                    voucher_id, cliente, customer_id, codigo,
                    campaign_id, campaign_name, tipo, valor, producto,
                    importe_maximo, quantity, redeemed_quantity,
                    descripcion, descripcion_corta, image_url, minimo_compra,
                    solo_uno, product_id, category_id, category_name,
                    fecha_inicio, fecha_caducidad, creado_voucherify_en, metadata
             ) VALUES (
                    :voucher_id, :cli, :customer_id, :codigo,
                    :campaign_id, :campaign_name, :tipo, :valor, :producto,
                    :maximo, :quantity, :redeemed,
                    :descripcion, :descripcion_corta, :image_url, :minimo,
                    :solo_uno, :product_id, :category_id, :category_name,
                    :inicio, :caducidad, :creado, :metadata
             )
             ON CONFLICT (voucher_id) DO UPDATE
                SET quantity = EXCLUDED.quantity,
                    redeemed_quantity = EXCLUDED.redeemed_quantity,
                    fecha_caducidad = EXCLUDED.fecha_caducidad,
                    actualizado_en = now()',
            [
                'voucher_id'        => $voucherId,
                'cli'               => Db::cliente(),
                'customer_id'       => $customerId,
                'codigo'            => $codigo,
                'campaign_id'       => $voucher['campaign_id'] ?? null,
                'campaign_name'     => $voucher['campaign'] ?? ($datos['campaign']['name'] ?? null),
                'tipo'              => $descuento['tipo'],
                'valor'             => $descuento['valor'],
                'producto'          => $descuento['producto'],
                'maximo'            => isset($voucher['discount']['amount_limit'])
                    ? (int) $voucher['discount']['amount_limit']
                    : null,
                // quantity a null en Voucherify significa usos ilimitados.
                'quantity'          => isset($redencion['quantity']) ? (int) $redencion['quantity'] : null,
                'redeemed'          => (int) ($redencion['redeemed_quantity'] ?? 0),
                'descripcion'       => $metadata['description'] ?? null,
                'descripcion_corta' => $datos['campaign']['description'] ?? null,
                'image_url'         => $metadata['image_url'] ?? null,
                'minimo'            => is_numeric($minimo) ? (int) $minimo : null,
                'solo_uno'          => isset($metadata['only_one']) ? self::texto($metadata['only_one']) : null,
                'product_id'        => isset($metadata['product_id']) ? self::texto($metadata['product_id']) : null,
                'category_id'       => isset($metadata['category_id']) ? self::texto($metadata['category_id']) : null,
                'category_name'     => $metadata['category_name'] ?? null,
                'inicio'            => $voucher['start_date'] ?? null,
                'caducidad'         => $voucher['expiration_date'] ?? null,
                'creado'            => $voucher['created_at'] ?? null,
                'metadata'          => json_encode($metadata, JSON_UNESCAPED_UNICODE) ?: '{}',
            ]
        );
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
            'SELECT notificado_en FROM sm_cupones WHERE voucher_id = :id',
            ['id' => $voucherId]
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
            'UPDATE sm_cupones SET notificado_en = now(), actualizado_en = now() WHERE voucher_id = :id',
            ['id' => $voucherId]
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

    private static function texto(mixed $valor): string
    {
        return match (true) {
            is_bool($valor)   => $valor ? 'true' : 'false',
            is_scalar($valor) => (string) $valor,
            default           => (string) json_encode($valor, JSON_UNESCAPED_UNICODE),
        };
    }
}
