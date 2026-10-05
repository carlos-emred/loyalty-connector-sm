<?php

declare(strict_types=1);

/**
 * Punto de entrada único de la aplicación.
 *
 * Cada script empieza con:
 *
 *     require_once __DIR__ . '/../bootstrap.php';
 *
 * y a partir de ahí tiene disponibles Config, Http, Log, Auth y Cors ya
 * configurados. Es una copia del bootstrap del conector de Blueshift con las
 * claves obligatorias de SalesManago en lugar de las de Blueshift.
 *
 * UBICACIÓN
 * ---------
 * Este fichero vive en /opt/salesmanago/repo/, FUERA del docroot. El docroot
 * de nginx es /opt/salesmanago/repo/public/. El conector de Blueshift sigue en
 * /opt/loyalty/ en la misma instancia; cada uno tiene su propio .env, su pool
 * de PHP-FPM y su worker, y solo comparten la base de datos.
 *
 * ORDEN DE ARRANQUE
 * -----------------
 * El orden importa: Log se configura antes que nada para que cualquier fallo
 * posterior quede registrado, y Config antes que Auth y Cors porque ambos
 * leen de él.
 */

// Evita efectos raros si algún script lo incluye dos veces.
if (defined('SALESMANAGO_BOOTSTRAP')) {
    return;
}

define('SALESMANAGO_BOOTSTRAP', true);

/** Raíz del proyecto. Todas las rutas se construyen a partir de aquí. */
define('BASE_PATH', __DIR__);

// -----------------------------------------------------------------------------
// 1. Autocargador
// -----------------------------------------------------------------------------
// No hay Composer: el proyecto no tiene dependencias externas y añadirlo
// obligaría a gestionar vendor/ en el despliegue. Este autocargador resuelve
// el espacio de nombres SalesManago\ contra src/ y es todo lo que hace falta.

spl_autoload_register(static function (string $clase): void {
    $prefijo = 'SalesManago\\';

    if (!str_starts_with($clase, $prefijo)) {
        return;
    }

    $relativa = substr($clase, strlen($prefijo));
    $ruta = BASE_PATH . '/src/' . str_replace('\\', '/', $relativa) . '.php';

    if (is_file($ruta)) {
        require_once $ruta;
    }
});

use SalesManago\Auth;
use SalesManago\Config;
use SalesManago\Cors;
use SalesManago\Http;
use SalesManago\Log;

// -----------------------------------------------------------------------------
// 2. Comportamiento base de PHP
// -----------------------------------------------------------------------------

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

// Los errores se registran, nunca se muestran: un aviso de PHP impreso en la
// respuesta puede revelar rutas del sistema de ficheros y, en el peor caso,
// fragmentos de configuración.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// -----------------------------------------------------------------------------
// 3. Configuración
// -----------------------------------------------------------------------------

try {
    Config::cargar(dirname(BASE_PATH) . '/.env');
} catch (Throwable $e) {
    // Aquí Log todavía no está configurado, así que se usa error_log directo.
    error_log('[ERROR] Bootstrap: no se pudo cargar la configuración. ' . $e->getMessage());

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'message' => 'Error de configuración del servicio.'],
            JSON_UNESCAPED_UNICODE
        );
    }

    exit(1);
}

// -----------------------------------------------------------------------------
// 4. Registro
// -----------------------------------------------------------------------------

Log::configurar(
    nivelMinimo: Config::get('LOG_NIVEL', 'info') ?? 'info',
    formato: Config::get('LOG_FORMATO', 'texto') ?? 'texto',
    enmascararEmails: Config::getBool('LOG_ENMASCARAR_EMAILS', true),
);

// Identificador de correlación: permite seguir una misma petición a lo largo
// de varias líneas de log. Si el llamante envía X-Request-Id se reutiliza, lo
// que encadena la traza desde nginx hasta el worker.
$idExterno = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
Log::fijarCorrelacion(is_string($idExterno) && $idExterno !== '' ? substr($idExterno, 0, 64) : null);

// Nombre del script que atiende, en sustitución de los antiguos ficheros de
// log separados por endpoint.
if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_NAME'])) {
    Log::fijarCanal(basename((string) $_SERVER['SCRIPT_NAME'], '.php'));
}

// Http emite avisos de reintento y errores a través de este callable, sin
// depender directamente de Log, para poder probarse de forma aislada.
Http::usarLogger(Log::comoCallable());

// -----------------------------------------------------------------------------
// 5. Manejadores de error
// -----------------------------------------------------------------------------

set_error_handler(static function (int $nivel, string $mensaje, string $fichero, int $linea): bool {
    if ((error_reporting() & $nivel) === 0) {
        return false;
    }

    Log::warning('PHP: aviso', [
        'mensaje'  => $mensaje,
        'fichero'  => basename($fichero),
        'linea'    => $linea,
    ]);

    // false deja que PHP siga con su tratamiento habitual (registro interno).
    return false;
});

set_exception_handler(static function (Throwable $e): void {
    Log::error('Excepción no capturada', [
        'tipo'    => $e::class,
        'mensaje' => $e->getMessage(),
        'fichero' => basename($e->getFile()),
        'linea'   => $e->getLine(),
    ]);

    if (PHP_SAPI === 'cli') {
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    // El mensaje al cliente es deliberadamente genérico. El detalle queda en
    // el log, localizable por el identificador de correlación.
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servicio.',
        'id'      => Log::correlacion(),
    ], JSON_UNESCAPED_UNICODE);

    exit(1);
});

// Los errores fatales no pasan por set_error_handler; se recogen al cerrar.
register_shutdown_function(static function (): void {
    $ultimo = error_get_last();

    if ($ultimo === null) {
        return;
    }

    if (!in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    Log::error('PHP: error fatal', [
        'mensaje' => $ultimo['message'],
        'fichero' => basename($ultimo['file']),
        'linea'   => $ultimo['line'],
    ]);
});

// -----------------------------------------------------------------------------
// 6. Claves obligatorias
// -----------------------------------------------------------------------------
// Se comprueban todas de golpe al arrancar. Si falta alguna, el proceso falla
// aquí con la lista completa, en lugar de descubrirlo llamada a llamada y
// recibir un 401 opaco de la API de destino.

Config::verificarObligatorias([
    'CONECTOR_SECRETO',
    'SALESMANAGO_API_BASE',
    'SALESMANAGO_CLIENT_ID',
    'SALESMANAGO_API_KEY',
    'SALESMANAGO_SHA',
    'SALESMANAGO_OWNER',
]);

// -----------------------------------------------------------------------------
// 7. Autenticación y CORS
// -----------------------------------------------------------------------------

Auth::configurar(
    secreto: Config::requerir('CONECTOR_SECRETO'),
    admitirQueryString: Config::getBool('ADMITIR_KEY_QUERYSTRING', false),
);

// CORS solo tiene sentido en peticiones HTTP. El worker no lo necesita.
if (PHP_SAPI !== 'cli') {
    Cors::configurar(
        origenesPermitidos: Config::getLista('ORIGENES_CORS_PERMITIDOS'),
        metodos: Config::getLista('METODOS_CORS_PERMITIDOS', ['POST', 'OPTIONS']),
    );
}

Log::debug('Bootstrap completado', [
    'entorno' => Config::get('ENTORNO', 'desconocido'),
    'sapi'    => PHP_SAPI,
]);
