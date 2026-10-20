<?php

declare(strict_types=1);

/**
 * Webhook de SalesManago: ha entrado una compra (evento PURCHASE) de un
 * contacto.
 *
 * Sustituye a v2/ejecutar_compra.php. PÚBLICO: no pide credenciales, porque el
 * webhook de SalesManago no puede enviarlas. Solo valida el contactId y lo
 * encola; el trabajo lo hace el worker en ManejadorCompra:
 *
 *   1. Pide a SalesManago la ficha del contacto (contact/listById).
 *   2. Toma sus compras recientes que aún no estén en sm_compras.
 *   3. Pide a Shopify el precio de cada producto.
 *   4. Registra cada compra en Voucherify: sin código de descuento como
 *      pedido (POST /v1/orders); con código, como canje (POST /v1/redemptions).
 *
 * POR QUÉ PUEDE SER PÚBLICO
 * -------------------------
 * - De la petición solo se toma el contactId. Los datos de la compra (importe,
 *   productos, cupón) se leen de SalesManago, no de quien llama: no se puede
 *   inventar una compra.
 * - Cada compra se registra una sola vez (sm_compras). Llamar mil veces con el
 *   mismo contactId no suma puntos de más. El original registraba la compra en
 *   cada llamada.
 * - Responde enseguida y no llama a ninguna API: las llamadas las hace el
 *   worker, a su ritmo. Repetir la llamada no multiplica el gasto de cuota de
 *   las APIs más allá de una consulta por evento encolado.
 * - nginx limita las peticiones por IP a este fichero
 *   (despliegue/nginx-salesmanago.conf).
 *
 * Lo que sí puede hacer cualquiera es adelantar el registro de la compra de un
 * contacto cuyo contactId conozca. No tiene efecto: esa compra se habría
 * registrado igualmente.
 *
 * ENTRADA
 * -------
 *   POST /webhooks/ejecutar_compra.php
 *   { "contactId": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" }
 *
 * o, para pruebas, GET /webhooks/ejecutar_compra.php?contactId=…
 *
 * SALIDA
 * ------
 *   200 {"success": true, "message": "Compra encolada"}
 *   400 {"success": false, "message": "contactId no válido"}
 *   503 {"success": false, "message": "…"}   la base no acepta el evento
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\Auth;
use SalesManago\Db;
use SalesManago\Log;

// Sin Auth::exigir() a propósito: ver POR QUÉ PUEDE SER PÚBLICO.
$metodo = $_SERVER['REQUEST_METHOD'] ?? '';

if ($metodo !== 'POST' && $metodo !== 'GET') {
    responder(405, false, 'Método no permitido');
}

$contactId = $metodo === 'GET'
    ? ($_GET['contactId'] ?? '')
    : (Auth::cuerpoJson(obligatorio: false)['contactId'] ?? '');

$contactId = mb_strtolower(trim(is_string($contactId) ? $contactId : ''));

// Los contactId de SalesManago son UUID. Cualquier otra cosa se rechaza antes
// de tocar la base.
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $contactId) !== 1) {
    Log::warning('ejecutar_compra: contactId no válido; se rechaza');

    responder(400, false, 'contactId no válido');
}

// Un evento por contacto y segundo: frena las repeticiones sin perder
// compras, porque el worker registra todas las compras pendientes del
// contacto, no solo la última.
try {
    $encolado = Db::encolar(
        'salesmanago.purchase',
        'compra:' . $contactId . ':' . time(),
        ['contactId' => $contactId]
    );
} catch (Throwable $e) {
    Log::error('ejecutar_compra: no se pudo encolar', ['mensaje' => $e->getMessage()]);

    responder(503, false, 'El servicio no puede aceptar la compra en este momento.');
}

Log::info($encolado ? 'ejecutar_compra: compra encolada' : 'ejecutar_compra: repetida en el mismo segundo; se ignora', [
    'contacto' => $contactId,
]);

responder(200, true, 'Compra encolada');

// -----------------------------------------------------------------------------

/**
 * @return never
 */
function responder(int $codigo, bool $exito, string $mensaje): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(['success' => $exito, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);

    exit;
}
