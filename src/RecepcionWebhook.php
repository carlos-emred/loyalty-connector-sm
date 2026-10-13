<?php

declare(strict_types=1);

namespace SalesManago;

use Throwable;

/**
 * Recepción común de los webhooks de Voucherify.
 *
 * Cada fichero de public/webhooks/ atiende un tipo de evento y solo hace esto:
 * autentica, guarda el payload en la cola (sm_eventos_pendientes) y responde
 * 200. El trabajo lo hace después el worker, con el manejador registrado para
 * ese tipo en worker/run.php.
 *
 * Mismo esquema que webhooks/voucherify.php del conector de Blueshift.
 *
 * RESPUESTAS
 * ----------
 *   200  encolado, duplicado o de otro tipo (se ignora). Otro tipo no es un
 *        error: así suscribir de más en Voucherify no llena la cola ni provoca
 *        reintentos.
 *   400  sin identificador de evento.
 *   503  la base no acepta el evento. Es temporal: Voucherify reintentará.
 */
final class RecepcionWebhook
{
    /**
     * Encola el webhook si es del tipo indicado y termina la petición.
     *
     * @return never
     */
    public static function encolar(string $tipoAdmitido): void
    {
        // Sin CORS: esto no lo llama ningún navegador.
        Auth::exigirMetodo('POST');
        Auth::exigir();

        $payload = Auth::cuerpoJson();

        // event.id antes que el id de la raíz: el segundo identifica el envío
        // y puede cambiar entre reintentos; el primero identifica el hecho
        // ocurrido.
        $idExterno = $payload['event']['id'] ?? $payload['id'] ?? null;
        $tipo = $payload['type'] ?? $payload['event']['type'] ?? null;

        if ($tipo !== $tipoAdmitido) {
            Log::info('Webhook ignorado: no es ' . $tipoAdmitido, [
                'tipo' => is_string($tipo) ? $tipo : '(desconocido)',
            ]);

            self::responder(200, true, 'Evento ignorado.', ['encolado' => false]);
        }

        if (!is_string($idExterno) || $idExterno === '') {
            Log::warning('Webhook sin identificador de evento; se rechaza', ['tipo' => $tipo]);

            self::responder(400, false, 'El webhook no incluye un identificador de evento.');
        }

        try {
            $encolado = Db::encolar($tipo, $idExterno, $payload);
        } catch (Throwable $e) {
            Log::error('No se pudo encolar el webhook', [
                'tipo'       => $tipo,
                'id_externo' => $idExterno,
                'mensaje'    => $e->getMessage(),
            ]);

            // 503 y no 500: es un fallo temporal y queremos que Voucherify
            // reintente.
            self::responder(503, false, 'El servicio no puede aceptar el evento en este momento.');
        }

        if (!$encolado) {
            Log::info('Webhook duplicado; ya estaba en la cola', [
                'tipo'       => $tipo,
                'id_externo' => $idExterno,
            ]);

            self::responder(200, true, 'Evento ya recibido anteriormente.', ['duplicado' => true]);
        }

        Log::info('Webhook encolado', ['tipo' => $tipo, 'id_externo' => $idExterno]);

        self::responder(200, true, 'Evento encolado.', ['duplicado' => false]);
    }

    /**
     * @param array<string,mixed> $extra
     * @return never
     */
    private static function responder(int $codigo, bool $exito, string $mensaje, array $extra = []): void
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
}
