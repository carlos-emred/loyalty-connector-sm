<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: voucher.published (cupón asignado a un cliente).
 *
 * Sustituye a v2/webhooks/añadir_cupon.php. Mismo esquema que
 * webhooks/voucherify.php del conector de Blueshift: guarda el payload en la
 * cola (sm_eventos_pendientes) y responde 200. El trabajo lo hace después el
 * worker, en ManejadorCuponAsignado:
 *
 *   1. Registra el cupón en sm_cupones.
 *   2. Lanza un evento externo en el contacto de SalesManago, que dispara el
 *      correo al cliente.
 *
 * Por qué cola y no síncrono como el original: el original llamaba a
 * SalesManago sin timeout mientras Voucherify esperaba. Una respuesta lenta
 * hacía que Voucherify diera el webhook por fallido y lo reenviase, y cada
 * reenvío era un correo más al cliente.
 *
 * Solo encola voucher.published. Cualquier otro tipo se contesta con 200 y se
 * descarta aquí mismo, para que suscribir de más en Voucherify no llene la
 * cola.
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/anadir_cupon.php
 *
 * evento voucher.published, con la cabecera X-Loyalty-Key y el secreto de ESTE
 * conector (CONECTOR_SECRETO), no el del de Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\Auth;
use SalesManago\Db;
use SalesManago\Log;

// Sin CORS: esto no lo llama ningún navegador.
Auth::exigirMetodo('POST');
Auth::exigir();

$payload = Auth::cuerpoJson();

// event.id antes que el id de la raíz: el segundo identifica el envío y puede
// cambiar entre reintentos; el primero identifica el hecho ocurrido.
$idExterno = $payload['event']['id'] ?? $payload['id'] ?? null;
$tipo = $payload['type'] ?? $payload['event']['type'] ?? null;

if ($tipo !== 'voucher.published') {
    Log::info('Webhook ignorado: no es voucher.published', [
        'tipo' => is_string($tipo) ? $tipo : '(desconocido)',
    ]);

    responder(200, true, 'Evento ignorado.', ['encolado' => false]);
}

if (!is_string($idExterno) || $idExterno === '') {
    Log::warning('Webhook sin identificador de evento; se rechaza', ['tipo' => $tipo]);

    responder(400, false, 'El webhook no incluye un identificador de evento.');
}

try {
    $encolado = Db::encolar($tipo, $idExterno, $payload);
} catch (Throwable $e) {
    Log::error('No se pudo encolar el webhook', [
        'tipo'       => $tipo,
        'id_externo' => $idExterno,
        'mensaje'    => $e->getMessage(),
    ]);

    // 503 y no 500: es un fallo temporal y queremos que Voucherify reintente.
    responder(503, false, 'El servicio no puede aceptar el evento en este momento.');
}

if (!$encolado) {
    Log::info('Webhook duplicado; ya estaba en la cola', [
        'tipo'       => $tipo,
        'id_externo' => $idExterno,
    ]);

    responder(200, true, 'Evento ya recibido anteriormente.', ['duplicado' => true]);
}

Log::info('Webhook encolado', ['tipo' => $tipo, 'id_externo' => $idExterno]);

responder(200, true, 'Evento encolado.', ['duplicado' => false]);


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
