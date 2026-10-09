<?php

declare(strict_types=1);

/**
 * Devuelve las recompensas disponibles de un miembro del programa de
 * fidelización y la configuración de los tramos (tiers).
 *
 * Sustituye a v2/obtener_loyalty.php.
 *
 *   - Las recompensas se piden a Voucherify
 *     (GET /v1/loyalties/members/{memberId}/rewards) y se devuelven tal cual.
 *   - Los tiers se leen de un fichero JSON del servidor (RUTA_TIERS_JSON), como
 *     en el conector de Blueshift. El original los leía de un perfil ficticio
 *     de Blueshift (tiers@tiers.com).
 *
 * FICHERO DE TIERS
 * ----------------
 * Lista con la forma que escribe ManejadorTierActualizado en el conector de
 * Blueshift, y que consume la web:
 *
 *   [{"id":"ltr_…","name":"Plata","points":{"from":250,"to":750}}, …]
 *
 * points.to puede ser null en el tramo superior. En este conector todavía no
 * hay webhook que lo mantenga: se actualiza a mano. Si falta o no es válido,
 * tiers va a null y queda aviso en el log; las recompensas se devuelven igual.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Si Voucherify fallaba, devolvía su respuesta de error dentro de «rewards»
 *   con success true. Ahora responde 502, o 404 si el miembro no existe.
 * - Leía campaignId y no lo usaba. Se ignora.
 * - Timeout en la llamada a Voucherify y credenciales en el .env.
 *
 * ENTRADA
 * -------
 *   POST /obtener_loyalty.php
 *   X-Loyalty-Key: <secreto>
 *
 *   { "memberId": "…" }
 *
 * SALIDA
 * ------
 *   200 {"success": true, "rewards": { …respuesta de Voucherify… }, "tiers": [ … ] | null}
 *   400 {"success": false, "message": "memberId requerido"}
 *   404 {"success": false, "message": "No se encontró el miembro"}
 *   502 {"success": false, "message": "No se pudieron obtener las recompensas"}
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\ApiVoucherify;
use SalesManago\Auth;
use SalesManago\Config;
use SalesManago\Cors;
use SalesManago\Log;

/** Ruta por defecto del fichero de tiers, si el .env no indica otra. */
const RUTA_TIERS_POR_DEFECTO = '/opt/salesmanago/datos/tiers.json';

/** Longitud máxima de un memberId; cualquier cosa mayor no es un id. */
const MEMBER_ID_MAX = 100;

Cors::aplicar();
Auth::exigirMetodo('POST');
Auth::exigir();

$entrada = Auth::cuerpoJson();
$memberId = trim((string) ($entrada['memberId'] ?? ''));

if ($memberId === '' || mb_strlen($memberId) > MEMBER_ID_MAX) {
    responder(400, ['success' => false, 'message' => 'memberId requerido']);
}

try {
    $rewards = ApiVoucherify::recompensasMiembro($memberId);
} catch (Throwable $e) {
    Log::error('obtener_loyalty: Voucherify no devolvió las recompensas', ['mensaje' => $e->getMessage()]);

    responder(502, ['success' => false, 'message' => 'No se pudieron obtener las recompensas']);
}

if ($rewards === null) {
    Log::info('obtener_loyalty: Voucherify no conoce al miembro');

    responder(404, ['success' => false, 'message' => 'No se encontró el miembro']);
}

$tiers = leerTiers(Config::get('RUTA_TIERS_JSON', RUTA_TIERS_POR_DEFECTO) ?? RUTA_TIERS_POR_DEFECTO);

Log::info('Loyalty de usuario servida', [
    'recompensas' => is_array($rewards['data'] ?? null) ? count($rewards['data']) : null,
    'tiers'       => $tiers !== null ? count($tiers) : null,
]);

responder(200, ['success' => true, 'rewards' => $rewards, 'tiers' => $tiers]);

// -----------------------------------------------------------------------------

/**
 * Lista de tramos del fichero, o null si no se puede usar. Nunca lanza: sin
 * tiers, la web puede seguir mostrando las recompensas.
 *
 * @return list<array<string,mixed>>|null
 */
function leerTiers(string $ruta): ?array
{
    if (!is_file($ruta) || !is_readable($ruta)) {
        Log::warning('obtener_loyalty: fichero de tiers no encontrado o no legible', ['ruta' => $ruta]);

        return null;
    }

    $tramos = json_decode((string) file_get_contents($ruta), true);

    // Misma comprobación que ManejadorTierActualizado: una lista de objetos.
    $esLista = is_array($tramos)
        && array_is_list($tramos)
        && array_filter($tramos, static fn (mixed $t): bool => !is_array($t)) === [];

    if (!$esLista) {
        Log::warning('obtener_loyalty: el fichero de tiers no contiene una lista de tramos válida', ['ruta' => $ruta]);

        return null;
    }

    return $tramos;
}

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

    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);

    exit;
}
