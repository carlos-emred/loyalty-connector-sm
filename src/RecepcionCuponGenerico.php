<?php

declare(strict_types=1);

namespace SalesManago;

use SalesManago\Eventos\RegistroCupon;
use Throwable;

/**
 * Recepción común de los webhooks de cupón genérico de SalesManago.
 *
 * Cada fichero de public/webhooks/cupones_genericos/ es un cupón: contiene su
 * configuración (el JSON del cupón tal como lo da Voucherify) y llama a
 * atender(). Esto valida la configuración y el contactId, encola el evento
 * salesmanago.cupon_generico y responde 200. El trabajo lo hace después el
 * worker, en ManejadorCuponGenerico.
 *
 * PÚBLICO, COMO ejecutar_compra.php
 * ---------------------------------
 * El webhook de una regla de automatización de SalesManago no puede enviar
 * credenciales. Cumple lo que CLAUDE.md exige a un endpoint sin credenciales:
 *
 * - De la petición solo se toma el contactId. El correo que también envía
 *   SalesManago se ignora: el worker lo lee de SalesManago con el contactId.
 *   Así nadie puede asignar el cupón a un correo inventado.
 * - No se puede repetir el efecto: un cliente tiene como mucho una fila por
 *   cupón en sm_cupones.
 * - Responde sin llamar a ninguna API y sin datos personales. nginx limita las
 *   peticiones por IP a la carpeta (despliegue/nginx-salesmanago.conf).
 *
 * Lo único que puede hacer quien conozca la URL y el contactId de un socio es
 * añadir a su cuenta un cupón cuyo código es el mismo para todo el mundo.
 *
 * LA CONFIGURACIÓN VIAJA CON EL EVENTO
 * ------------------------------------
 * Se encola la configuración del fichero en el momento de la llamada. Si
 * después se sube otra versión del fichero, los eventos ya encolados usan la
 * que había cuando SalesManago llamó.
 */
final class RecepcionCuponGenerico
{
    /**
     * Encola la asignación del cupón al contacto de la petición y termina.
     *
     * @param string $configuracion JSON del cupón: {"voucher": {...}, "campaign": {...}}
     * @return never
     */
    public static function atender(string $configuracion): void
    {
        $metodo = $_SERVER['REQUEST_METHOD'] ?? '';

        if ($metodo !== 'POST' && $metodo !== 'GET') {
            self::responder(405, false, 'Método no permitido');
        }

        $archivo = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php');
        $cupon = self::cuponValido($configuracion, $archivo);

        if ($cupon === null) {
            // Error de quien subió el fichero, no de SalesManago. 500 para que
            // se vea en el panel de la regla.
            self::responder(500, false, 'La configuración del cupón no es válida.');
        }

        $contactId = $metodo === 'GET'
            ? ($_GET['contactId'] ?? '')
            : (Auth::cuerpoJson(obligatorio: false)['contactId'] ?? '');

        $contactId = mb_strtolower(trim(is_string($contactId) ? $contactId : ''));

        // Los contactId de SalesManago son UUID. Cualquier otra cosa se
        // rechaza antes de tocar la base.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $contactId) !== 1) {
            Log::warning('Cupón genérico: contactId no válido; se rechaza', ['archivo' => $archivo]);

            self::responder(400, false, 'contactId no válido');
        }

        $voucherId = (string) $cupon['voucher']['id'];

        // Un evento por contacto, cupón y segundo, como ejecutar_compra.php:
        // frena las repeticiones seguidas. Lo que impide asignar el cupón dos
        // veces es la clave de sm_cupones, no esto.
        try {
            $encolado = Db::encolar(
                'salesmanago.cupon_generico',
                'cupon_generico:' . $voucherId . ':' . $contactId . ':' . time(),
                ['contactId' => $contactId, 'archivo' => $archivo, 'cupon' => $cupon]
            );
        } catch (Throwable $e) {
            Log::error('Cupón genérico: no se pudo encolar', ['archivo' => $archivo, 'mensaje' => $e->getMessage()]);

            self::responder(503, false, 'El servicio no puede aceptar la petición en este momento.');
        }

        Log::info($encolado ? 'Cupón genérico encolado' : 'Cupón genérico repetido en el mismo segundo; se ignora', [
            'archivo'  => $archivo,
            'voucher'  => $voucherId,
            'contacto' => $contactId,
        ]);

        self::responder(200, true, 'Cupón encolado');
    }

    /**
     * La configuración decodificada si sirve para crear el cupón, o null.
     * ManejadorCuponGenerico vuelve a comprobar lo mismo.
     *
     * @return array<string,mixed>|null
     */
    public static function cuponValido(string $configuracion, string $archivo): ?array
    {
        $cupon = json_decode($configuracion, true);
        $voucher = is_array($cupon) && is_array($cupon['voucher'] ?? null) ? $cupon['voucher'] : null;

        $motivo = match (true) {
            $voucher === null                                       => 'no es JSON o no tiene "voucher"',
            !is_string($voucher['id'] ?? null) || $voucher['id'] === ''     => 'falta voucher.id',
            !is_string($voucher['code'] ?? null) || $voucher['code'] === '' => 'falta voucher.code',
            ($voucher['type'] ?? null) === 'LOYALTY_CARD'           => 'es una tarjeta de fidelización',
            RegistroCupon::clasificarDescuento($voucher) === null  => 'tipo de descuento desconocido',
            default                                                 => null,
        };

        if ($motivo !== null) {
            Log::error('Cupón genérico: configuración no válida', [
                'archivo' => $archivo,
                'motivo'  => $motivo,
                'error'   => json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : null,
            ]);

            return null;
        }

        return $cupon;
    }

    /**
     * @return never
     */
    private static function responder(int $codigo, bool $exito, string $mensaje): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(['success' => $exito, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);

        exit;
    }
}
