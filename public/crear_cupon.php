<?php

declare(strict_types=1);

/**
 * Crea un cupón en Shopify para un cliente registrado en sm_clientes.
 *
 * Sustituye a v2/crear_cupon.php. Todos los datos del cupón llegan en la
 * petición: no se consulta a Voucherify.
 *
 * EL CLIENTE SE COMPRUEBA EN LA BASE, NO EN SHOPIFY
 * -------------------------------------------------
 * Solo se crea el cupón si el correo recibido tiene fila en sm_clientes. No se
 * busca al cliente en Shopify: esa búsqueda exige que la app de Shopify tenga
 * acceso al email de los clientes (datos protegidos de nivel 2). Sin su id de
 * Shopify, el cupón no puede restringirse a ese cliente y vale para cualquiera
 * que tenga el código.
 *
 * SEGURIDAD
 * ---------
 * Lo llama el storefront desde el navegador, así que el secreto X-Loyalty-Key
 * es público (ver Auth.php) y este endpoint crea en la tienda el descuento que
 * se le pida. Las únicas barreras son CORS, que no frena una petición hecha
 * fuera del navegador, y que el correo tiene que estar registrado
 * en sm_clientes. Pendiente de decidir una protección real.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - El correo es obligatorio y se comprueba en sm_clientes. El original buscaba
 *   el cliente en Shopify aunque llegase vacío y se quedaba con el primer
 *   resultado.
 * - El resto de correcciones están en ApiShopify.
 *
 * SIN VALIDEZ EN SHOPIFY
 * ----------------------
 * El cupón no lleva fechas de inicio ni de caducidad ni número de usos
 * (quantity): si se puede usar lo decide Voucherify, al que se consulta
 * siempre justo antes de aplicarlo. Shopify solo aplica el descuento. Si la
 * petición trae quantity, start_date o expiration_date, se ignoran.
 *
 * Tampoco hay importe máximo: maxCount se ignora (ver ApiShopify).
 *
 * ENTRADA
 * -------
 *   POST /crear_cupon.php
 *   X-Loyalty-Key: <secreto>
 *
 *   {
 *     "code":   "4Q7vCP",
 *     "email":  "cliente@…",              obligatorio
 *     "type":   "AMOUNT",                 AMOUNT | PERCENT | SHIPPING (y alias)
 *     "count":  1000,                     céntimos si AMOUNT, % si PERCENT
 *     "gift_product_id": null,            id de producto de Shopify que se regala
 *     "metadata": {
 *       "product_id": null,               id de producto de Shopify
 *       "category_id": null,              id de colección de Shopify
 *       "only_one": false,
 *       "minimum_purchase_quantity": 30   mínimo de compra en euros
 *     },
 *     "cart": [ { "product_id": 123, "final_price": 1999 }, … ]
 *   }
 *
 * Como en el original, product_id, category_id, only_one y
 * minimum_purchase_quantity pueden ir en la raíz o en metadata, y metadata
 * manda. Sin type, se admiten porcentaje_dto (%) o reduction_amount (euros).
 * cart solo hace falta con only_one y sin producto, colección ni regalo: el
 * descuento va al artículo más barato.
 *
 * SALIDA
 * ------
 *   200 {"success": true, "message": "Cupón creado correctamente",
 *        "priceRuleId": "…", "discountCode": {"id": "…", "code": "…"}}
 *   400 datos no válidos o carrito sin productos
 *   404 el correo no está en sm_clientes
 *   409 el código ya existe en Shopify
 *   502 Shopify no pudo crear el cupón
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\ApiShopify;
use SalesManago\Auth;
use SalesManago\Cors;
use SalesManago\Db;
use SalesManago\Log;
use SalesManago\Shopify\EspecificacionCupon;
use SalesManago\Shopify\ResultadoCupon;

Cors::aplicar();
Auth::exigirMetodo('POST');
Auth::exigir();

$datos = Auth::cuerpoJson();
$metadata = is_array($datos['metadata'] ?? null) ? $datos['metadata'] : [];

$productoId = texto(primerValor($metadata['product_id'] ?? null, $datos['product_id'] ?? null));
$categoriaId = texto(primerValor($metadata['category_id'] ?? null, $datos['category_id'] ?? null));
$soloUno = filter_var(primerValor($metadata['only_one'] ?? null, $datos['only_one'] ?? null) ?? false, FILTER_VALIDATE_BOOLEAN);
$minimoEuros = primerValor($metadata['minimum_purchase_quantity'] ?? null, $datos['minimum_purchase_quantity'] ?? null);
$regaloId = texto(primerValor($datos['gift_product_id'] ?? null));

[$tipo, $valor] = tipoYValor($datos, $regaloId !== null);

if ($tipo === null) {
    responder(400, false, 'Tipo de cupón no reconocido. Admitidos: AMOUNT, PERCENT, SHIPPING.');
}

try {
    $cupon = new EspecificacionCupon(
        codigo: trim((string) ($datos['code'] ?? '')),
        tipo: $tipo,
        valor: $valor,
        soloUno: $soloUno,
        minimoCompra: is_numeric($minimoEuros) ? (int) round((float) $minimoEuros * 100) : null,
        productoId: $productoId,
        categoriaId: $categoriaId,
        regaloProductoId: $regaloId,
    );

    if ($cupon->necesitaCarrito()) {
        $masBarato = productoMasBarato($datos['cart'] ?? null);

        if ($masBarato === null) {
            responder(400, false, 'No se pudo obtener el producto más barato del carrito');
        }

        $cupon = $cupon->conProductoMasBarato($masBarato);
    }
} catch (InvalidArgumentException $e) {
    Log::warning('crear_cupon: datos no válidos', ['motivo' => $e->getMessage()]);

    responder(400, false, $e->getMessage());
}

// El correo se guarda siempre en minúsculas en sm_clientes.
$email = mb_strtolower(trim((string) ($datos['email'] ?? '')));

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    responder(400, false, 'Falta el correo del cliente o no es válido.');
}

$existe = Db::valor(
    'SELECT 1 FROM sm_clientes WHERE cliente = :cli AND email = :email',
    ['cli' => Db::cliente(), 'email' => $email]
);

if ($existe === null) {
    Log::warning('crear_cupon: el correo no está en sm_clientes', ['codigo' => $cupon->codigo]);

    responder(404, false, 'No se encontró ningún cliente con ese email');
}

$resultado = ApiShopify::crearCupon($cupon);

switch ($resultado->estado) {
    case ResultadoCupon::CREADO:
        Log::info('Cupón creado en Shopify', [
            'codigo'   => $cupon->codigo,
            'tipo'     => $cupon->tipo,
            'id_regla' => $resultado->idRegla,
        ]);

        responder(200, true, 'Cupón creado correctamente', [
            'priceRuleId'  => $resultado->idRegla,
            'discountCode' => ['id' => $resultado->idCodigo, 'code' => $cupon->codigo],
        ]);

    case ResultadoCupon::YA_EXISTE:
        Log::info('crear_cupon: el código ya existe en Shopify', ['codigo' => $cupon->codigo]);

        responder(409, false, "El código '{$cupon->codigo}' ya existe");

    default:
        Log::error('crear_cupon: Shopify no creó el cupón', [
            'codigo' => $cupon->codigo,
            'motivo' => $resultado->motivo,
        ]);

        responder(502, false, 'No se pudo crear el cupón');
}

// -----------------------------------------------------------------------------

/**
 * Tipo y valor del cupón, con los alias y la lógica del original.
 *
 * Con type: PERCENT usa count como porcentaje y AMOUNT como céntimos. Sin
 * type: porcentaje_dto (porcentaje) o reduction_amount (euros). Con producto
 * regalo y sin tipo válido, el tipo da igual: el valor no se usa.
 *
 * @param array<string,mixed> $datos
 * @return array{0: ?string, 1: int}
 */
function tipoYValor(array $datos, bool $hayRegalo): array
{
    $count = $datos['count'] ?? null;
    $tipo = strtoupper(trim((string) ($datos['type'] ?? '')));

    $resultado = match ($tipo) {
        'PERCENT', 'PERCENTAGE'     => [EspecificacionCupon::TIPO_PORCENTAJE, is_numeric($count) ? (int) $count : 0],
        'AMOUNT', 'FIXED_AMOUNT'    => [EspecificacionCupon::TIPO_IMPORTE, is_numeric($count) ? (int) $count : 0],
        'SHIPPING', 'FREE_SHIPPING' => [EspecificacionCupon::TIPO_ENVIO_GRATIS, 0],
        default                     => null,
    };

    if ($resultado !== null) {
        return $resultado;
    }

    if ($tipo === '') {
        $porcentaje = primerValor($datos['porcentaje_dto'] ?? null);
        $importe = primerValor($datos['reduction_amount'] ?? null);

        if (is_numeric($porcentaje)) {
            return [EspecificacionCupon::TIPO_PORCENTAJE, (int) $porcentaje];
        }

        if (is_numeric($importe)) {
            return [EspecificacionCupon::TIPO_IMPORTE, (int) round((float) $importe * 100)];
        }
    }

    return $hayRegalo ? [EspecificacionCupon::TIPO_IMPORTE, 0] : [null, 0];
}

/**
 * Id del producto más barato del carrito, por final_price (céntimos, tal como
 * lo da el carrito de Shopify). null si el carrito no sirve.
 */
function productoMasBarato(mixed $carrito): ?string
{
    if (!is_array($carrito)) {
        return null;
    }

    $masBarato = null;

    foreach ($carrito as $articulo) {
        $precio = $articulo['final_price'] ?? null;
        $id = texto($articulo['product_id'] ?? null);

        if (!is_numeric($precio) || $id === null || !ctype_digit($id)) {
            continue;
        }

        if ($masBarato === null || (float) $precio < $masBarato['precio']) {
            $masBarato = ['precio' => (float) $precio, 'id' => $id];
        }
    }

    return $masBarato['id'] ?? null;
}

/**
 * Primer valor útil. Trata como ausentes null, la cadena vacía y la cadena
 * literal «null», que es lo que llega cuando el llamante serializa mal.
 */
function primerValor(mixed ...$valores): mixed
{
    foreach ($valores as $valor) {
        if ($valor !== null && $valor !== '' && $valor !== 'null') {
            return $valor;
        }
    }

    return null;
}

function texto(mixed $valor): ?string
{
    $valor = primerValor($valor);

    return is_scalar($valor) ? trim((string) $valor) : null;
}

/**
 * @param array<string,mixed> $extra
 * @return never
 */
function responder(int $codigo, bool $exito, string $mensaje, array $extra = []): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(
        ['success' => $exito, 'message' => $mensaje] + $extra,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}
