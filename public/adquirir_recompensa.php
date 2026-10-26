<?php

declare(strict_types=1);

/**
 * Canjea puntos de fidelización de un miembro por una recompensa.
 *
 * Sustituye a v2/adquirir_recompensa.php. Solo recibe los datos de la
 * recompensa y del miembro y llama a Voucherify
 * (POST /v1/loyalties/{campaignId}/members/{memberId}/redemption). No escribe
 * en la base: si la recompensa es un cupón, Voucherify lo publica y llega por
 * webhooks/anadir_cupon.php como cualquier otro.
 *
 * La respuesta de Voucherify se devuelve tal cual, con su código HTTP, como en
 * el original: la tienda lee de ahí la recompensa obtenida o el motivo del
 * rechazo (puntos insuficientes, recompensa no disponible...).
 *
 * SEGURIDAD
 * ---------
 * Lo llama el navegador, así que el secreto X-Loyalty-Key es público (ver
 * Auth.php). Quien conozca el memberId de alguien (el código de su tarjeta de
 * fidelización) puede gastar sus puntos. Pendiente de la misma protección real
 * que crear_cupon.php. Por eso el memberId no se escribe entero en el log.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Los ids se codifican en la URL. Sin eso, un memberId con «/» o «?»
 *   apuntaba a otro recurso de Voucherify.
 * - rewardPoints tiene que ser un entero no negativo.
 * - Si Voucherify no respondía, devolvía 200 con cuerpo vacío. Ahora 502.
 * - Timeout, sin reintentar si la petición pudo llegar (gasta puntos), y
 *   credenciales en el .env. El original volcaba los datos en un .txt.
 *
 * ENTRADA
 * -------
 *   POST /adquirir_recompensa.php
 *   X-Loyalty-Key: <secreto>
 *
 *   {
 *     "campaignId":   "camp_…",
 *     "memberId":     "…",          código o id de la tarjeta de fidelización
 *     "rewardId":     "rew_…",
 *     "rewardPoints": 100
 *   }
 *
 * SALIDA
 * ------
 *   La respuesta de Voucherify, con su código (200 si se canjea, 4xx si la
 *   rechaza), o:
 *   400 {"success": false, "message": "Datos incompletos"}
 *   502 {"success": false, "message": "No se pudo contactar con Voucherify"}
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\ApiVoucherify;
use SalesManago\Auth;
use SalesManago\Cors;
use SalesManago\Log;

/** Longitud máxima de un id; cualquier cosa mayor no es un id de Voucherify. */
const ID_MAX = 100;

Cors::aplicar();
Auth::exigirMetodo('POST');
Auth::exigir();

$entrada = Auth::cuerpoJson();

$campanaId = texto($entrada['campaignId'] ?? null);
$memberId = texto($entrada['memberId'] ?? null);
$rewardId = texto($entrada['rewardId'] ?? null);
$puntos = $entrada['rewardPoints'] ?? null;

if ($campanaId === null || $memberId === null || $rewardId === null || $puntos === null || $puntos === '') {
    responder(400, 'Datos incompletos');
}

if (filter_var($puntos, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
    responder(400, 'rewardPoints debe ser un número entero de puntos');
}

$respuesta = ApiVoucherify::canjearRecompensa($campanaId, $memberId, $rewardId, (int) $puntos);

$contexto = [
    'campana'    => $campanaId,
    'recompensa' => $rewardId,
    'puntos'     => (int) $puntos,
    // Basta para identificar la tarjeta en el log sin dejar el código
    // completo, que permite gastar sus puntos.
    'miembro'    => mb_substr($memberId, 0, 4) . '…',
    'resumen'    => $respuesta->resumen(),
];

if ($respuesta->fallóTransporte()) {
    Log::error('adquirir_recompensa: Voucherify no respondió', $contexto);

    responder(502, 'No se pudo contactar con Voucherify');
}

if ($respuesta->ok()) {
    Log::info('Recompensa canjeada con puntos', $contexto);
} else {
    Log::warning('adquirir_recompensa: Voucherify rechazó el canje', $contexto + [
        'motivo' => is_string($respuesta->valor('key')) ? $respuesta->valor('key') : null,
    ]);
}

// La respuesta de Voucherify, tal cual, como el original.
if (!headers_sent()) {
    http_response_code($respuesta->codigo);
    header('Content-Type: application/json; charset=utf-8');
}

echo $respuesta->cuerpo;
exit;

// -----------------------------------------------------------------------------

/**
 * Texto recortado, o null si está vacío, es demasiado largo para ser un id o
 * no es texto ni número.
 */
function texto(mixed $valor): ?string
{
    if (!is_scalar($valor)) {
        return null;
    }

    $valor = trim((string) $valor);

    return $valor !== '' && mb_strlen($valor) <= ID_MAX ? $valor : null;
}

/**
 * @return never
 */
function responder(int $codigo, string $mensaje): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(['success' => false, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);

    exit;
}
