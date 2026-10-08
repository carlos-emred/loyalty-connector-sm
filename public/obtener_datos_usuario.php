<?php

declare(strict_types=1);

/**
 * Devuelve al storefront los datos de fidelización de un cliente: saldo y
 * total de puntos, tier, tarjeta de fidelización, cupones válidos e historial
 * de movimientos de puntos.
 *
 * Sustituye a v2/obtener_datos_usuario.php. Todo sale de PostgreSQL
 * (sm_clientes, sm_cupones y sm_movimientos_puntos): no se llama a Voucherify
 * ni a ninguna otra API. El detalle está en Storefront\DatosUsuario.
 *
 * SEGURIDAD
 * ---------
 * Lo llama el navegador, así que el secreto X-Loyalty-Key es público (ver
 * Auth.php): cualquiera que conozca un correo puede consultar sus puntos y
 * los códigos de sus cupones. Por eso la respuesta no lleva datos personales.
 * Pendiente de decidir una protección real, la misma que crear_cupon.php.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - El original preguntaba a Blueshift por el perfil y leía los ficheros
 *   cupones/{id}.json y puntos/{id}.json con bloqueo. Ahora es una lectura de
 *   la base, sin bloqueos ni escrituras.
 * - Borraba del fichero los cupones caducados. Aquí no se borra nada: los que
 *   ya no son válidos no se devuelven.
 * - Si el perfil no existía, fallaba al construir la ruta del fichero y
 *   devolvía datos vacíos con 200. Ahora responde 404.
 * - Leía precioTotal y productos y no los usaba. Se ignoran.
 *
 * ENTRADA
 * -------
 *   POST /obtener_datos_usuario.php
 *   X-Loyalty-Key: <secreto>
 *
 *   { "email": "cliente@…" }
 *
 * SALIDA
 * ------
 *   200 {"success": true, "datos": {"custom_attributes": {…}, "balance": …,
 *        "total_points": …, "loyalty_card": …, "loyalty_campaign": null,
 *        "cupones": [ … ], "historial": [ … ]}}
 *   400 {"success": false, "message": "Se requiere el email"}
 *   404 {"success": false, "message": "No se encontró ningún cliente con ese email"}
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\Auth;
use SalesManago\Cors;
use SalesManago\Log;
use SalesManago\Storefront\DatosUsuario;

Cors::aplicar();
Auth::exigirMetodo('POST');
Auth::exigir();

$entrada = Auth::cuerpoJson();
$email = mb_strtolower(trim((string) ($entrada['email'] ?? '')));

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    responder(400, ['success' => false, 'message' => 'Se requiere el email']);
}

$datos = DatosUsuario::paraCorreo($email);

if ($datos === null) {
    Log::info('obtener_datos_usuario: el correo no está en sm_clientes');

    responder(404, ['success' => false, 'message' => 'No se encontró ningún cliente con ese email']);
}

Log::info('Datos de usuario servidos al storefront', [
    'customer'    => $datos['custom_attributes']['customerIdVoucherify'],
    'cupones'     => count($datos['cupones']),
    'movimientos' => count($datos['historial']),
]);

// Sin «message», como el original.
responder(200, ['success' => true, 'datos' => $datos]);

// -----------------------------------------------------------------------------

/**
 * @param array<string,mixed> $cuerpo
 * @return never
 */
function responder(int $codigo, array $cuerpo): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }

    // JSON_THROW_ON_ERROR: una respuesta a medias sería peor que el 500
    // genérico del manejador de excepciones de bootstrap.php.
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    exit;
}
