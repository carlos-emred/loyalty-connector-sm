<?php

declare(strict_types=1);

/**
 * Formulario para crear un cupón genérico nuevo a partir de su JSON.
 *
 * Genera public/webhooks/cupones_genericos/<nombre>.php con la misma forma que
 * la plantilla verano26.php y devuelve la URL que hay que poner en el webhook
 * de la regla de automatización de SalesManago.
 *
 *   GET  /admin/clonar_cupon_generico.php   muestra el formulario
 *   POST /admin/clonar_cupon_generico.php   crea el fichero
 *
 * SEGURIDAD
 * ---------
 * Escribe ficheros .php en la carpeta pública, así que:
 *
 * - Pide CUPONES_GENERICOS_SECRETO del .env, que no es el X-Loyalty-Key (ese es
 *   público). Sin el secreto configurado, no funciona. nginx limita además las
 *   peticiones por IP, para que no se pueda probar secretos a ciegas.
 * - El JSON recibido no se copia tal cual: se valida igual que al recibir el
 *   webhook (RecepcionCuponGenerico::cuponValido) y se vuelve a codificar con
 *   json_encode. Así no puede contener la línea que cierra el nowdoc ni nada
 *   que se ejecute como PHP.
 * - El nombre solo admite minúsculas, números, «_» y «-».
 * - No sobrescribe un cupón existente salvo que se marque la casilla, y nunca
 *   la plantilla verano26.php.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\Config;
use SalesManago\Log;
use SalesManago\RecepcionCuponGenerico;

const DIRECTORIO_CUPONES = __DIR__ . '/../webhooks/cupones_genericos';
const PLANTILLA = 'verano26';
const NOMBRE_VALIDO = '/^[a-z0-9_-]{1,60}$/';

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';

if ($metodo === 'GET') {
    pagina(200);
}

if ($metodo !== 'POST') {
    header('Allow: GET, POST');
    pagina(405, error: 'Método no permitido.');
}

$nombre = mb_strtolower(trim((string) ($_POST['nombre'] ?? '')));
$nombre = (string) preg_replace('/\.php$/', '', $nombre);
$json = (string) ($_POST['configuracion'] ?? '');
$sobrescribir = ($_POST['sobrescribir'] ?? '') === '1';
$secreto = Config::get('CUPONES_GENERICOS_SECRETO', '') ?? '';
$formulario = ['nombre' => $nombre, 'configuracion' => $json, 'sobrescribir' => $sobrescribir];

if ($secreto === '' || !hash_equals($secreto, (string) ($_POST['secreto'] ?? ''))) {
    Log::warning('clonar_cupon_generico: secreto incorrecto o sin configurar', [
        'configurado' => $secreto !== '',
        'origen'      => $_SERVER['REMOTE_ADDR'] ?? '?',
    ]);

    pagina(403, $formulario, 'Secreto incorrecto.');
}

if (preg_match(NOMBRE_VALIDO, $nombre) !== 1) {
    pagina(400, $formulario, 'El nombre solo puede tener minúsculas, números, «_» y «-» (máximo 60).');
}

if ($nombre === PLANTILLA) {
    pagina(400, $formulario, 'Ese nombre es el de la plantilla; elige otro.');
}

$cupon = RecepcionCuponGenerico::cuponValido($json, $nombre);

if ($cupon === null) {
    pagina(400, $formulario, 'La configuración no es válida: tiene que ser el JSON del cupón de Voucherify, '
        . 'con voucher.id, voucher.code y un tipo de descuento conocido.');
}

$ruta = DIRECTORIO_CUPONES . '/' . $nombre . '.php';

if (is_file($ruta) && !$sobrescribir) {
    pagina(409, $formulario, 'Ya existe un cupón con ese nombre. Marca «Sobrescribir» si quieres reemplazarlo.');
}

if (!escribir($ruta, contenido($nombre, $cupon))) {
    Log::error('clonar_cupon_generico: no se pudo escribir el fichero', ['archivo' => $nombre]);

    pagina(500, $formulario, 'No se pudo crear el fichero en el servidor. Revisa el log.');
}

$url = 'https://' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . '/webhooks/cupones_genericos/' . $nombre . '.php';

Log::info('clonar_cupon_generico: cupón genérico creado', [
    'archivo' => $nombre,
    'voucher' => $cupon['voucher']['id'],
    'codigo'  => $cupon['voucher']['code'],
]);

pagina(201, exito: $url);

// -----------------------------------------------------------------------------

/**
 * El fichero del cupón: lo mismo que la plantilla, con la configuración
 * re-codificada.
 *
 * @param array<string,mixed> $cupon
 */
function contenido(string $nombre, array $cupon): string
{
    $json = json_encode($cupon, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $codigo = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $cupon['voucher']['code']);
    $fecha = gmdate('Y-m-d H:i') . ' UTC';

    return <<<PHP
        <?php

        declare(strict_types=1);

        /**
         * Cupón genérico {$codigo}. Generado por admin/clonar_cupon_generico.php
         * el {$fecha}. Ver la plantilla verano26.php.
         *
         *   https://<dominio>/webhooks/cupones_genericos/{$nombre}.php
         */

        require_once __DIR__ . '/../../../bootstrap.php';

        use SalesManago\RecepcionCuponGenerico;

        RecepcionCuponGenerico::atender(<<<'CONFIGURACION'
        {$json}
        CONFIGURACION);

        PHP;
}

/**
 * Escribe en un temporal y lo renombra: el webhook nunca ejecuta un fichero a
 * medias.
 */
function escribir(string $ruta, string $contenido): bool
{
    $temporal = @tempnam(DIRECTORIO_CUPONES, '.cupon-');

    if ($temporal === false || realpath(dirname($temporal)) !== realpath(DIRECTORIO_CUPONES)) {
        if (is_string($temporal)) {
            @unlink($temporal);
        }

        return false;
    }

    if (@file_put_contents($temporal, $contenido) === false || !@chmod($temporal, 0644) || !@rename($temporal, $ruta)) {
        @unlink($temporal);

        return false;
    }

    // Que PHP no siga sirviendo la versión anterior desde OPcache.
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($ruta, true);
    }

    return true;
}

/**
 * Pinta la página y termina.
 *
 * @param array{nombre?: string, configuracion?: string, sobrescribir?: bool} $formulario
 * @return never
 */
function pagina(int $codigo, array $formulario = [], ?string $error = null, ?string $exito = null): void
{
    $e = static fn (string $texto): string => htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');

    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
    }

    $nombre = $e($formulario['nombre'] ?? '');
    $configuracion = $e($formulario['configuracion'] ?? '');
    $marcado = !empty($formulario['sobrescribir']) ? ' checked' : '';
    $aviso = '';

    if ($error !== null) {
        $aviso = '<p class="error">' . $e($error) . '</p>';
    }

    if ($exito !== null) {
        $aviso = '<div class="exito"><p>Cupón creado. URL para el webhook de SalesManago:</p>'
            . '<p><input type="text" readonly value="' . $e($exito) . '" onclick="this.select()"></p></div>';
    }

    echo <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">
        <title>Nuevo cupón genérico</title>
        <style>
          body { font-family: system-ui, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; color: #222; }
          label { display: block; margin-top: 1rem; font-weight: 600; }
          input[type=text], input[type=password], textarea { width: 100%; box-sizing: border-box; padding: .5rem; font: inherit; }
          textarea { height: 22rem; font-family: ui-monospace, monospace; font-size: .85rem; }
          .check { font-weight: normal; }
          button { margin-top: 1.2rem; padding: .6rem 1.2rem; font: inherit; cursor: pointer; }
          .error { background: #fde8e8; border: 1px solid #e0a0a0; padding: .7rem; }
          .exito { background: #e6f6ea; border: 1px solid #9fd0ab; padding: .7rem; }
          small { color: #666; }
        </style>
        </head>
        <body>
        <h1>Nuevo cupón genérico</h1>
        {$aviso}
        <form method="post" autocomplete="off">
          <label for="nombre">Nombre del archivo</label>
          <input type="text" id="nombre" name="nombre" value="{$nombre}" required pattern="[a-z0-9_\-]{1,60}" placeholder="navidad26">
          <small>Minúsculas, números, «_» y «-». Será parte de la URL.</small>

          <label for="configuracion">Configuración del cupón (JSON de Voucherify)</label>
          <textarea id="configuracion" name="configuracion" required placeholder='{"voucher": {...}, "campaign": {...}}'>{$configuracion}</textarea>

          <label class="check"><input type="checkbox" name="sobrescribir" value="1"{$marcado}> Sobrescribir si ya existe</label>

          <label for="secreto">Secreto</label>
          <input type="password" id="secreto" name="secreto" required>

          <button type="submit">Crear cupón</button>
        </form>
        </body>
        </html>
        HTML;

    exit;
}
