<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\ApiShopify;
use SalesManago\ApiVoucherify;
use SalesManago\Config;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende salesmanago.purchase: SalesManago avisa (webhooks/ejecutar_compra.php)
 * de que un contacto tiene una compra nueva.
 *
 * Sustituye a la lógica de v2/ejecutar_compra.php:
 *
 *   1. Pide a SalesManago la ficha del contacto con sus eventos externos.
 *   2. Toma sus eventos PURCHASE de las últimas COMPRAS_VENTANA_HORAS horas que
 *      no estén ya en sm_compras, del más antiguo al más reciente.
 *   3. Para cada uno, pide a Shopify el precio de sus productos y lo registra
 *      en Voucherify: sin código de descuento como pedido pagado
 *      (POST /v1/orders); con código, como canje (POST /v1/redemptions).
 *   4. Lo anota en sm_compras, registrado o rechazado.
 *
 * Campos del evento PURCHASE de SalesManago, los mismos que leía el original:
 *   date      fecha en milisegundos
 *   value     importe en euros
 *   products  ids de variante de Shopify separados por comas
 *   detail6   cantidades, en el mismo orden que products
 *   detail5   código de descuento; vacío si no hay
 *
 * UNA COMPRA, UN REGISTRO
 * -----------------------
 * El endpoint es público y SalesManago puede avisar más de una vez. Una compra
 * con fila en sm_compras no se vuelve a enviar, tanto si se registró como si
 * Voucherify la rechazó. Además, el pedido lleva source_id: si el proceso
 * muere entre la respuesta de Voucherify y la escritura en sm_compras, el
 * reintento actualiza el mismo pedido en lugar de crear otro. El canje no
 * tiene esa protección (ver ApiVoucherify::canjear); la ventana es de
 * milisegundos.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Registraba la compra cada vez que se le llamaba: repetir la llamada
 *   sumaba puntos.
 * - Tomaba el primer PURCHASE que devolviese SalesManago, que no tiene por qué
 *   ser el último. Ahora se registran todas las compras pendientes del
 *   contacto dentro de la ventana: si dos compras llegan seguidas, no se
 *   pierde ninguna.
 * - Desactivaba la verificación TLS y no tenía timeouts. Credenciales al .env.
 * - Un producto que Shopify no conoce rompía el script. Ahora el artículo va
 *   sin precio y queda aviso en el log.
 *
 * Si Voucherify rechaza el canje (cupón agotado, caducado...), la compra queda
 * como rechazada y no se registra como pedido sin cupón. Es una decisión de
 * negocio pendiente: así el cliente no suma los puntos de esa compra.
 */
final class ManejadorCompra implements ManejadorEvento
{
    /** Horas hacia atrás en que se buscan compras pendientes, si el .env no dice otra cosa. */
    private const VENTANA_HORAS_POR_DEFECTO = 48;

    /** @var array<string,?int> Precios ya consultados en este evento, por variante. */
    private array $precios = [];

    public function tipos(): array
    {
        return ['salesmanago.purchase'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $contactId = trim((string) ($payload['contactId'] ?? ''));

        if ($contactId === '') {
            Log::error('salesmanago.purchase sin contactId; se ignora', ['evento' => $idEvento]);

            return;
        }

        $contacto = ApiSalesManago::contactoPorId($contactId);

        if ($contacto === null) {
            Log::warning('Compra de un contacto que SalesManago no conoce; se ignora', [
                'evento'   => $idEvento,
                'contacto' => $contactId,
            ]);

            return;
        }

        $email = mb_strtolower(trim((string) ($contacto['email'] ?? '')));

        if ($email === '') {
            Log::warning('Compra de un contacto sin correo; no se puede registrar en Voucherify', [
                'evento'   => $idEvento,
                'contacto' => $contactId,
            ]);

            return;
        }

        $this->precios = [];
        $compras = $this->comprasRecientes($contacto);

        if ($compras === []) {
            Log::info('El contacto no tiene compras recientes en SalesManago', [
                'evento'   => $idEvento,
                'contacto' => $contactId,
            ]);

            return;
        }

        foreach ($compras as $compra) {
            $this->registrar($compra, $contactId, $email, $idEvento);
        }
    }

    /**
     * Eventos PURCHASE dentro de la ventana, del más antiguo al más reciente.
     *
     * @param array<string,mixed> $contacto
     * @return list<array<string,mixed>>
     */
    private function comprasRecientes(array $contacto): array
    {
        $horas = Config::getInt('COMPRAS_VENTANA_HORAS', self::VENTANA_HORAS_POR_DEFECTO) ?? self::VENTANA_HORAS_POR_DEFECTO;
        $desde = (time() - $horas * 3600) * 1000;
        $eventos = is_array($contacto['contactExtEvents'] ?? null) ? $contacto['contactExtEvents'] : [];

        $compras = array_values(array_filter(
            $eventos,
            static fn (mixed $e): bool => is_array($e)
                && ($e['contactExtEventType'] ?? null) === 'PURCHASE'
                && is_numeric($e['date'] ?? null)
                && (int) $e['date'] >= $desde
        ));

        usort($compras, static fn (array $a, array $b): int => (int) $a['date'] <=> (int) $b['date']);

        return $compras;
    }

    /**
     * Registra una compra en Voucherify si no lo estaba ya.
     *
     * @param array<string,mixed> $compra
     *
     * @throws \RuntimeException si Shopify o Voucherify fallan; el worker
     *                           reintenta y las compras ya anotadas se saltan
     */
    private function registrar(array $compra, string $contactId, string $email, int $idEvento): void
    {
        $clave = self::clave($compra, $contactId);

        $yaEsta = Db::valor(
            'SELECT 1 FROM sm_compras WHERE cliente = :cli AND clave = :clave',
            ['cli' => Db::cliente(), 'clave' => $clave]
        );

        if ($yaEsta !== null) {
            return;
        }

        $importe = (int) round((float) ($compra['value'] ?? 0) * 100);
        $cupon = trim((string) ($compra['detail5'] ?? ''));
        $articulos = $this->articulos($compra);
        $cliente = ['source_id' => $email, 'email' => $email];

        $resultado = $cupon === ''
            ? ApiVoucherify::registrarPedido([
                'source_id' => $clave,
                'amount'    => $importe,
                'items'     => $articulos,
                'customer'  => $cliente,
                'status'    => 'PAID',
            ])
            : ApiVoucherify::canjear([
                'redeemables' => [['object' => 'voucher', 'id' => $cupon]],
                'customer'    => $cliente,
                'order'       => [
                    'source_id' => $clave,
                    'amount'    => $importe,
                    'items'     => $articulos,
                ],
            ]);

        Db::ejecutar(
            'INSERT INTO sm_compras
                    (cliente, clave, contact_id, fecha, importe, codigo_cupon, estado, voucherify_id, motivo)
             VALUES (:cli, :clave, :contacto, to_timestamp(:fecha), :importe, :cupon, :estado, :voucherify, :motivo)
             ON CONFLICT (cliente, clave) DO NOTHING',
            [
                'cli'        => Db::cliente(),
                'clave'      => $clave,
                'contacto'   => $contactId,
                'fecha'      => intdiv((int) $compra['date'], 1000),
                'importe'    => $importe,
                'cupon'      => $cupon !== '' ? $cupon : null,
                'estado'     => $resultado['rechazado'] ? 'rechazada' : 'registrada',
                'voucherify' => $resultado['id'],
                'motivo'     => $resultado['motivo'],
            ]
        );

        $contexto = [
            'evento'     => $idEvento,
            'contacto'   => $contactId,
            'importe'    => $importe,
            'con_cupon'  => $cupon !== '',
            'voucherify' => $resultado['id'],
        ];

        if ($resultado['rechazado']) {
            Log::warning('Voucherify rechazó la compra; no se reintenta', $contexto + ['motivo' => $resultado['motivo']]);
        } else {
            Log::info('Compra registrada en Voucherify', $contexto);
        }
    }

    /**
     * Artículos de la compra para Voucherify, con el precio actual de Shopify.
     *
     * @param array<string,mixed> $compra
     * @return list<array<string,mixed>>
     */
    private function articulos(array $compra): array
    {
        $productos = array_map('trim', explode(',', (string) ($compra['products'] ?? '')));
        $cantidades = array_map('trim', explode(',', (string) ($compra['detail6'] ?? '')));
        $articulos = [];

        foreach ($productos as $i => $variante) {
            if ($variante === '') {
                continue;
            }

            if (!array_key_exists($variante, $this->precios)) {
                $this->precios[$variante] = ApiShopify::precioVariante($variante);

                if ($this->precios[$variante] === null) {
                    Log::warning('Producto de la compra sin precio en Shopify; se envía sin precio', ['variante' => $variante]);
                }
            }

            $cantidad = $cantidades[$i] ?? '';
            $articulo = [
                'product_id'     => $variante,
                'quantity'       => ctype_digit($cantidad) && (int) $cantidad > 0 ? (int) $cantidad : 1,
                'related_object' => 'product',
            ];

            if ($this->precios[$variante] !== null) {
                $articulo['price'] = $this->precios[$variante];
            }

            $articulos[] = $articulo;
        }

        return $articulos;
    }

    /**
     * Identificador estable de la compra: el que dé SalesManago o, si no da
     * ninguno, un hash de lo que la define.
     *
     * @param array<string,mixed> $compra
     */
    private static function clave(array $compra, string $contactId): string
    {
        foreach (['eventId', 'id', 'externalId'] as $campo) {
            $valor = $compra[$campo] ?? null;

            if (is_scalar($valor) && trim((string) $valor) !== '') {
                return 'sm-' . $campo . ':' . trim((string) $valor);
            }
        }

        return 'sm-hash:' . sha1(implode('|', [
            $contactId,
            (string) ($compra['date'] ?? ''),
            (string) ($compra['value'] ?? ''),
            (string) ($compra['products'] ?? ''),
            (string) ($compra['detail5'] ?? ''),
        ]));
    }
}
