<?php

declare(strict_types=1);

namespace SalesManago;

use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use SalesManago\Shopify\EspecificacionCupon;
use SalesManago\Shopify\ResultadoCupon;

/**
 * Cliente de la API REST de administración de Shopify.
 *
 * Solo cubre lo que necesita crear_cupon.php: comprobar si un código existe,
 * buscar un cliente por correo y crear el cupón (regla de precio + código).
 * Copia adaptada de Loyalty\Ecommerce\ShopifyAdapter del conector de
 * Blueshift.
 *
 * REST Y NO GRAPHQL
 * -----------------
 * Shopify considera heredada la API REST de descuentos (PriceRule y
 * DiscountCode) y recomienda GraphQL. Sigue funcionando y es la que usa el
 * conector de Blueshift con el mismo token, así que se mantiene por ahora.
 *
 * DIFERENCIAS CON v2/crear_cupon.php
 * ----------------------------------
 * - Timeouts en todas las llamadas y credenciales en el .env.
 * - Se elimina priceRuleExiste(): price_rules.json no filtra por título, así
 *   que devolvía «existe» en cuanto la tienda tuviera una sola regla.
 * - El cliente se busca por correo exacto y se comprueba que coincide: la
 *   búsqueda de Shopify es aproximada y el original se quedaba con el primer
 *   resultado, que podía ser otro cliente.
 * - El producto regalo era un descuento fijo de 100 €, no un 100 %: un
 *   producto de más de 100 € no salía gratis. Ahora es un 100 %.
 * - Si el código no se puede asociar a la regla, la regla se elimina en lugar
 *   de quedar huérfana en la tienda.
 */
final class ApiShopify
{
    private const VERSION_POR_DEFECTO = '2026-07';

    /**
     * Crea el cupón restringido al cliente de la especificación.
     */
    public static function crearCupon(EspecificacionCupon $cupon): ResultadoCupon
    {
        if (self::cuponExiste($cupon->codigo)) {
            return ResultadoCupon::yaExiste($cupon->codigo);
        }

        try {
            $idCliente = self::buscarClientePorEmail($cupon->emailCliente);
        } catch (RuntimeException $e) {
            return ResultadoCupon::fallido($cupon->codigo, $e->getMessage());
        }

        if ($idCliente === null) {
            return ResultadoCupon::clienteNoEncontrado($cupon->codigo);
        }

        $respuesta = Http::postJson(
            url: self::url('price_rules.json'),
            payload: ['price_rule' => self::reglaDePrecio($cupon, $idCliente)],
            cabeceras: self::cabeceras(),
        );

        self::vigilarVersion($respuesta);
        $idRegla = $respuesta->valor('price_rule.id');

        if (!$respuesta->ok() || $idRegla === null) {
            return ResultadoCupon::fallido(
                $cupon->codigo,
                'Shopify rechazó la regla de precio: ' . $respuesta->resumen() . ' — ' . self::errores($respuesta)
            );
        }

        $idRegla = (string) $idRegla;

        $respuesta = Http::postJson(
            url: self::url('price_rules/' . $idRegla . '/discount_codes.json'),
            payload: ['discount_code' => ['code' => $cupon->codigo]],
            cabeceras: self::cabeceras(),
        );

        self::vigilarVersion($respuesta);
        $idCodigo = $respuesta->valor('discount_code.id');

        if (!$respuesta->ok() || $idCodigo === null) {
            // Una regla sin código no sirve y ensucia el panel de la tienda.
            self::eliminarRegla($idRegla);

            return ResultadoCupon::fallido(
                $cupon->codigo,
                'Shopify rechazó el código; la regla se ha revertido: ' . $respuesta->resumen()
                . ' — ' . self::errores($respuesta)
            );
        }

        return ResultadoCupon::creado($cupon->codigo, $idRegla, (string) $idCodigo);
    }

    /**
     * discount_codes/lookup responde 303 hacia el código si existe y 404 si
     * no. Http no sigue redirecciones, así que basta el código de estado.
     *
     * Ante otra respuesta se devuelve false: si en realidad existía, Shopify
     * rechazará después el código duplicado y la regla se revertirá.
     */
    private static function cuponExiste(string $codigo): bool
    {
        // El 404 es la respuesta normal cuando el código está libre. Se
        // silencia el log de Http para no dejar un ERROR en cada creación.
        Http::usarLogger(null);
        $respuesta = Http::getJson(
            self::url('discount_codes/lookup.json') . '?code=' . rawurlencode($codigo),
            self::cabeceras(),
            reintentos: 0,
        );
        Http::usarLogger(Log::comoCallable());

        self::vigilarVersion($respuesta);

        if ($respuesta->codigo === 404) {
            return false;
        }

        if ($respuesta->ok() || ($respuesta->codigo >= 300 && $respuesta->codigo < 400)) {
            return true;
        }

        Log::warning('Shopify: respuesta inesperada al comprobar el código', ['resumen' => $respuesta->resumen()]);

        return false;
    }

    /**
     * Id del cliente con ese correo exacto, o null si Shopify no tiene
     * ninguno.
     *
     * null significa solo eso: que Shopify respondió bien y no hay cliente.
     * Si la búsqueda falla, o Shopify devuelve clientes sin el campo email,
     * no se puede saber y se lanza excepción, para que crear_cupon.php
     * responda 502 y no un 404 engañoso.
     *
     * Clientes sin email: pasa cuando la app de Shopify no tiene concedido el
     * acceso a datos protegidos de clientes (protected customer data). La
     * búsqueda los encuentra, pero Shopify oculta el correo en la respuesta.
     *
     * @throws RuntimeException si no se puede determinar
     */
    private static function buscarClientePorEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));

        $respuesta = Http::getJson(
            self::url('customers/search.json') . '?query=' . rawurlencode('email:' . $email),
            self::cabeceras(),
        );

        self::vigilarVersion($respuesta);

        if (!$respuesta->ok()) {
            throw new RuntimeException(
                'Shopify: fallo al buscar el cliente: ' . $respuesta->resumen() . ' — ' . self::errores($respuesta)
            );
        }

        $clientes = $respuesta->valor('customers', []);
        $clientes = is_array($clientes) ? $clientes : [];
        $sinEmail = 0;

        foreach ($clientes as $cliente) {
            $suEmail = $cliente['email'] ?? null;

            if (!is_string($suEmail) || $suEmail === '') {
                $sinEmail++;
                continue;
            }

            if (mb_strtolower($suEmail) === $email && isset($cliente['id'])) {
                return (string) $cliente['id'];
            }
        }

        if ($sinEmail > 0) {
            throw new RuntimeException(sprintf(
                'Shopify: la búsqueda devolvió %d cliente(s) sin el campo email; la app no tiene '
                . 'acceso a los datos protegidos de clientes (protected customer data)',
                $sinEmail
            ));
        }

        Log::info('Shopify: búsqueda de cliente sin coincidencia exacta', ['resultados' => count($clientes)]);

        return null;
    }

    /**
     * Traduce la especificación a una price_rule, con las mismas reglas que
     * crearCuponShopify() del original salvo las correcciones de la cabecera.
     *
     * @return array<string,mixed>
     */
    private static function reglaDePrecio(EspecificacionCupon $cupon, string $idCliente): array
    {
        $regla = [
            'title'                     => $cupon->codigo,
            'customer_selection'        => 'prerequisite',
            'prerequisite_customer_ids' => [(int) $idCliente],
            // Shopify exige starts_at. Se pone el momento de creación y no se
            // fija ends_at: la validez la decide Voucherify, que se consulta
            // siempre antes de aplicar el cupón. Por lo mismo, quantity no
            // llega aquí; solo only_one limita los usos (ver abajo).
            'starts_at'                 => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            'once_per_customer'         => $cupon->soloUno,
        ];

        // only_one, como en el original: un uso y una vez por cliente.
        if ($cupon->soloUno) {
            $regla['usage_limit'] = 1;
        }

        if ($cupon->minimoCompra !== null && $cupon->minimoCompra > 0) {
            $regla['prerequisite_subtotal_range'] = [
                'greater_than_or_equal_to' => self::euros($cupon->minimoCompra),
            ];
        }

        // Envío gratis: 100 % sobre la línea de envío. Manda sobre todo lo
        // demás.
        if ($cupon->esEnvioGratis()) {
            return $regla + [
                'target_type'       => 'shipping_line',
                'target_selection'  => 'all',
                'allocation_method' => 'each',
                'value_type'        => 'percentage',
                'value'             => '-100.0',
            ];
        }

        // Producto regalo: 100 % sobre ese producto. Manda sobre el tipo y el
        // valor.
        if ($cupon->regaloProductoId !== null) {
            return $regla + [
                'target_type'          => 'line_item',
                'target_selection'     => 'entitled',
                'allocation_method'    => 'each',
                'value_type'           => 'percentage',
                'value'                => '-100.0',
                'entitled_product_ids' => [(int) $cupon->regaloProductoId],
            ];
        }

        $regla['target_type'] = 'line_item';

        // Con importe máximo, el original convertía el cupón en uno de importe
        // fijo por ese tope, fuera cual fuera el tipo: Shopify no admite topes
        // en los porcentajes. Se conserva ese criterio.
        if ($cupon->importeMaximo !== null) {
            $regla['value_type'] = 'fixed_amount';
            $regla['value'] = '-' . self::euros($cupon->importeMaximo);
        } elseif ($cupon->esPorcentaje()) {
            $regla['value_type'] = 'percentage';
            $regla['value'] = '-' . $cupon->valor . '.0';
        } else {
            $regla['value_type'] = 'fixed_amount';
            $regla['value'] = '-' . self::euros($cupon->valor);
        }

        if ($cupon->productoId !== null) {
            $regla['target_selection'] = 'entitled';
            $regla['allocation_method'] = 'across';
            $regla['entitled_product_ids'] = [(int) $cupon->productoId];
        } elseif ($cupon->categoriaId !== null) {
            $regla['target_selection'] = 'entitled';
            $regla['allocation_method'] = 'across';
            $regla['entitled_collection_ids'] = [(int) $cupon->categoriaId];
        } elseif ($cupon->soloUno && $cupon->productoMasBaratoId !== null) {
            $regla['target_selection'] = 'entitled';
            $regla['allocation_method'] = 'each';
            $regla['entitled_product_ids'] = [(int) $cupon->productoMasBaratoId];
        } else {
            $regla['target_selection'] = 'all';
            $regla['allocation_method'] = 'across';
        }

        return $regla;
    }

    private static function eliminarRegla(string $idRegla): void
    {
        $respuesta = Http::delete(self::url('price_rules/' . $idRegla . '.json'), self::cabeceras());

        if (!$respuesta->ok() && $respuesta->codigo !== 404) {
            Log::error('Shopify: no se pudo revertir la regla de precio; eliminarla a mano', [
                'id_regla' => $idRegla,
                'resumen'  => $respuesta->resumen(),
            ]);
        }
    }

    private static function euros(int $centimos): string
    {
        return number_format($centimos / 100, 2, '.', '');
    }

    private static function url(string $recurso): string
    {
        return sprintf(
            'https://%s/admin/api/%s/%s',
            Config::requerir('SHOPIFY_STORE'),
            self::version(),
            $recurso
        );
    }

    private static function version(): string
    {
        return Config::get('SHOPIFY_API_VERSION', self::VERSION_POR_DEFECTO) ?? self::VERSION_POR_DEFECTO;
    }

    /** @return string[] */
    private static function cabeceras(): array
    {
        return [
            'X-Shopify-Access-Token: ' . Config::requerir('SHOPIFY_TOKEN'),
            'Accept: application/json',
        ];
    }

    /**
     * Si Shopify atiende con otra versión de la API que la pedida, la nuestra
     * ya no está disponible y el comportamiento puede cambiar sin tocar el
     * código.
     */
    private static function vigilarVersion(Respuesta $respuesta): void
    {
        $servida = $respuesta->cabecera('X-Shopify-API-Version');

        if ($servida !== null && $servida !== self::version()) {
            Log::warning('Shopify: la versión de API solicitada no está disponible', [
                'solicitada' => self::version(),
                'servida'    => $servida,
                'accion'     => 'actualiza SHOPIFY_API_VERSION en el .env',
            ]);
        }
    }

    /** Shopify detalla el motivo del rechazo en «errors». Recortado. */
    private static function errores(Respuesta $respuesta): string
    {
        $errores = $respuesta->valor('errors');

        if ($errores === null) {
            return '(sin detalle)';
        }

        $texto = is_string($errores) ? $errores : (json_encode($errores, JSON_UNESCAPED_UNICODE) ?: '');

        return mb_substr($texto, 0, 300);
    }
}
