<?php

declare(strict_types=1);

/**
 * Formulario para crear un cupón genérico nuevo a partir de su JSON.
 *
 * Genera public/webhooks/cupones_genericos/<nombre>.php con la misma forma que
 * la plantilla verano26.php y devuelve la URL que hay que poner en el webhook
 * de la regla de automatización de SalesManago.
 *
 *   GET  /admin/clonar_cupon_generico.php   login o, con sesión, el formulario
 *   POST accion=entrar                      inicia sesión
 *   POST accion=salir                       cierra la sesión
 *   POST accion=crear                       crea el fichero
 *
 * ACCESO
 * ------
 * Usuario y contraseña del .env: CUPONES_GENERICOS_USUARIO y
 * CUPONES_GENERICOS_PASSWORD_HASH (la contraseña nunca está en claro, solo su
 * password_hash). Si falta alguno, no entra nadie.
 *
 * La sesión es una cookie firmada con CUPONES_GENERICOS_SECRETO, sin estado en
 * el servidor: «caducidad.firma», válida DURACION_SESION segundos. La firma
 * incluye el hash de la contraseña, así que cambiar la contraseña (o el
 * secreto) cierra todas las sesiones abiertas. La cookie es HttpOnly, Secure,
 * SameSite=Strict y solo para /admin/.
 *
 * SEGURIDAD
 * ---------
 * Escribe ficheros .php en la carpeta pública, así que:
 *
 * - Sin sesión válida no se crea nada. nginx limita las peticiones por IP, para
 *   que no se puedan probar contraseñas a ciegas.
 * - Cada POST de creación lleva un token derivado de la sesión (CSRF).
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
const COOKIE_SESION = 'sm_admin_sesion';
const DURACION_SESION = 4 * 3600;
// Hash de una contraseña aleatoria: se comprueba contra él cuando no hay
// credenciales, para que el tiempo de respuesta no lo delate.
const HASH_FALSO = '$2y$10$Cte7hkDgK0wybIN5MX9AP.vydmvAAUvztSgO8Gj7RGz90N05eGwZG';

$metodo = $_SERVER['REQUEST_METHOD'] ?? '';
$sesion = sesionValida();

if ($metodo === 'GET') {
    $sesion === null ? paginaLogin(200) : pagina(200);
}

if ($metodo !== 'POST') {
    header('Allow: GET, POST');
    $sesion === null ? paginaLogin(405, 'Método no permitido.') : pagina(405, error: 'Método no permitido.');
}

$accion = (string) ($_POST['accion'] ?? '');

if ($accion === 'entrar') {
    entrar();
}

if ($accion === 'salir') {
    guardarCookie('', time() - 3600);
    redirigir();
}

if ($sesion === null) {
    paginaLogin(401, 'La sesión ha caducado. Vuelve a entrar.');
}

$nombre = mb_strtolower(trim((string) ($_POST['nombre'] ?? '')));
$nombre = (string) preg_replace('/\.php$/', '', $nombre);
$json = (string) ($_POST['configuracion'] ?? '');
$sobrescribir = ($_POST['sobrescribir'] ?? '') === '1';
$formulario = ['nombre' => $nombre, 'configuracion' => $json, 'sobrescribir' => $sobrescribir];

if ($accion !== 'crear' || !hash_equals(tokenCsrf($sesion), (string) ($_POST['csrf'] ?? ''))) {
    Log::warning('clonar_cupon_generico: token CSRF incorrecto', ['origen' => $_SERVER['REMOTE_ADDR'] ?? '?']);

    pagina(403, $formulario, 'La página ha caducado. Vuelve a enviar el formulario.');
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
// Sesión

/**
 * Usuario, hash de la contraseña y secreto de firma, o null si falta alguno.
 *
 * @return array{usuario: string, hash: string, secreto: string}|null
 */
function credenciales(): ?array
{
    $credenciales = [
        'usuario' => Config::get('CUPONES_GENERICOS_USUARIO', '') ?? '',
        'hash'    => Config::get('CUPONES_GENERICOS_PASSWORD_HASH', '') ?? '',
        'secreto' => Config::get('CUPONES_GENERICOS_SECRETO', '') ?? '',
    ];

    return in_array('', $credenciales, true) ? null : $credenciales;
}

/**
 * Comprueba usuario y contraseña, abre la sesión y vuelve al formulario.
 *
 * @return never
 */
function entrar(): void
{
    $credenciales = credenciales();
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // password_verify siempre, aunque no haya credenciales o el usuario no
    // coincida: que el tiempo de respuesta no diga cuál de los dos falla.
    $passwordCorrecta = password_verify($password, $credenciales['hash'] ?? HASH_FALSO);

    if ($credenciales === null || !hash_equals($credenciales['usuario'], $usuario) || !$passwordCorrecta) {
        Log::warning('clonar_cupon_generico: acceso denegado', [
            'configurado' => $credenciales !== null,
            'origen'      => $_SERVER['REMOTE_ADDR'] ?? '?',
        ]);

        paginaLogin(401, 'Usuario o contraseña incorrectos.', $usuario);
    }

    $caduca = time() + DURACION_SESION;
    guardarCookie($caduca . '.' . firma($credenciales, $caduca), $caduca);

    Log::info('clonar_cupon_generico: sesión iniciada', ['origen' => $_SERVER['REMOTE_ADDR'] ?? '?']);

    redirigir();
}

/** El valor de la cookie si la sesión es válida y no ha caducado; si no, null. */
function sesionValida(): ?string
{
    $credenciales = credenciales();
    $valor = $_COOKIE[COOKIE_SESION] ?? null;

    if ($credenciales === null || !is_string($valor)
        || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $valor, $partes) !== 1) {
        return null;
    }

    $caduca = (int) $partes[1];

    if ($caduca < time() || $caduca > time() + DURACION_SESION) {
        return null;
    }

    return hash_equals(firma($credenciales, $caduca), $partes[2]) ? $valor : null;
}

/** @param array{usuario: string, hash: string, secreto: string} $credenciales */
function firma(array $credenciales, int $caduca): string
{
    return hash_hmac(
        'sha256',
        'sesion|' . $credenciales['usuario'] . '|' . $caduca . '|' . $credenciales['hash'],
        $credenciales['secreto']
    );
}

/** Token del formulario: solo lo puede calcular quien tiene la sesión. */
function tokenCsrf(string $sesion): string
{
    return hash_hmac('sha256', 'csrf|' . $sesion, (string) (credenciales()['secreto'] ?? ''));
}

function guardarCookie(string $valor, int $caduca): void
{
    setcookie(COOKIE_SESION, $valor, [
        'expires'  => $caduca,
        'path'     => '/admin/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

/**
 * Vuelve a la página con un GET, para que recargar no reenvíe el login.
 *
 * @return never
 */
function redirigir(): void
{
    header('Cache-Control: no-store');
    header('Location: clonar_cupon_generico.php', true, 303);
    exit;
}

// -----------------------------------------------------------------------------
// Fichero del cupón

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

// -----------------------------------------------------------------------------
// Páginas

function escapar(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

function aviso(string $titulo, string $texto): string
{
    return '<div class="aviso aviso-error" role="alert"><strong>' . escapar($titulo) . '</strong> '
        . escapar($texto) . '</div>';
}

/**
 * Pantalla de acceso.
 *
 * @return never
 */
function paginaLogin(int $codigo, ?string $error = null, string $usuario = ''): void
{
    $aviso = $error !== null ? aviso('No se ha podido entrar.', $error) : '';
    $usuario = escapar($usuario);

    documento($codigo, 'Acceso', '', <<<HTML
        <main class="estrecho">
          <h1>Acceso</h1>
          <p class="intro">Entra con tu usuario para crear cupones genéricos.</p>

          {$aviso}

          <form method="post" class="tarjeta">
            <input type="hidden" name="accion" value="entrar">

            <div class="campo">
              <label for="usuario">Usuario</label>
              <input type="text" id="usuario" name="usuario" value="{$usuario}" required
                     autocomplete="username" autocapitalize="none" spellcheck="false" autofocus>
            </div>

            <div class="campo">
              <label for="password">Contraseña</label>
              <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>

            <div class="acciones">
              <button type="submit" class="boton boton-ancho">Entrar</button>
            </div>
          </form>
        </main>
        HTML);
}

/**
 * Formulario de creación. Solo se muestra con sesión.
 *
 * @param array{nombre?: string, configuracion?: string, sobrescribir?: bool} $formulario
 * @return never
 */
function pagina(int $codigo, array $formulario = [], ?string $error = null, ?string $exito = null): void
{
    $csrf = escapar(tokenCsrf((string) sesionValida()));
    $nombre = escapar($formulario['nombre'] ?? '');
    $configuracion = escapar($formulario['configuracion'] ?? '');
    $marcado = !empty($formulario['sobrescribir']) ? ' checked' : '';
    $aviso = $error !== null ? aviso('No se ha creado el cupón.', $error) : '';

    if ($exito !== null) {
        $aviso = '<div class="aviso aviso-ok" role="status">'
            . '<strong>Cupón creado.</strong> Usa esta URL en el webhook de la regla de automatización de SalesManago:'
            . '<div class="url"><input type="text" id="url" readonly value="' . escapar($exito) . '">'
            . '<button type="button" class="boton boton-secundario" data-copiar="url">Copiar</button></div></div>';
    }

    $salir = <<<HTML
        <form method="post">
          <input type="hidden" name="accion" value="salir">
          <button type="submit" class="boton boton-secundario">Cerrar sesión</button>
        </form>
        HTML;

    documento($codigo, 'Nuevo cupón genérico', $salir, <<<HTML
        <main>
          <h1>Nuevo cupón genérico</h1>
          <p class="intro">Crea el webhook de un cupón genérico a partir de su configuración en Voucherify.
            Al terminar obtendrás la URL que debes poner en la regla de automatización de SalesManago.</p>

          {$aviso}

          <form method="post" autocomplete="off" class="tarjeta">
            <input type="hidden" name="accion" value="crear">
            <input type="hidden" name="csrf" value="{$csrf}">

            <div class="campo">
              <label for="nombre">Nombre del archivo</label>
              <div class="prefijo">
                <span>/webhooks/cupones_genericos/</span>
                <input type="text" id="nombre" name="nombre" value="{$nombre}" required
                       pattern="[a-z0-9_\-]{1,60}" maxlength="60" placeholder="navidad26">
              </div>
              <span class="ayuda">Minúsculas, números, <code>_</code> y <code>-</code>. Forma parte de la URL; sin <code>.php</code>.</span>
            </div>

            <div class="campo">
              <label for="configuracion">Configuración del cupón</label>
              <textarea id="configuracion" name="configuracion" required spellcheck="false"
                        placeholder='{"voucher": {...}, "campaign": {...}}'>{$configuracion}</textarea>
              <div class="barra-json">
                <span class="estado-json" id="estado-json">JSON del cupón tal como lo da Voucherify.</span>
                <button type="button" class="boton boton-secundario" id="formatear">Validar y formatear</button>
              </div>
            </div>

            <div class="campo">
              <label class="casilla"><input type="checkbox" name="sobrescribir" value="1"{$marcado}>
                <span>Sobrescribir si ya existe un cupón con ese nombre</span></label>
            </div>

            <div class="acciones">
              <button type="submit" class="boton">Crear cupón</button>
            </div>
          </form>
        </main>

        <script>
          // Solo comodidad: el servidor vuelve a validar todo.
          (function () {
            var area = document.getElementById('configuracion');
            var estado = document.getElementById('estado-json');

            document.getElementById('formatear').addEventListener('click', function () {
              try {
                var datos = JSON.parse(area.value);
                area.value = JSON.stringify(datos, null, 2);
                var v = datos && datos.voucher;
                if (!v || !v.id || !v.code) {
                  estado.textContent = 'JSON válido, pero falta voucher.id o voucher.code.';
                  estado.className = 'estado-json mal';
                } else {
                  estado.textContent = 'JSON válido · código ' + v.code;
                  estado.className = 'estado-json bien';
                }
              } catch (e) {
                estado.textContent = 'JSON no válido: ' + e.message;
                estado.className = 'estado-json mal';
              }
            });

            document.querySelectorAll('[data-copiar]').forEach(function (boton) {
              boton.addEventListener('click', function () {
                var campo = document.getElementById(boton.getAttribute('data-copiar'));
                campo.select();
                (navigator.clipboard ? navigator.clipboard.writeText(campo.value) : Promise.reject())
                  .catch(function () { document.execCommand('copy'); })
                  .finally(function () { boton.textContent = 'Copiada'; });
              });
            });
          })();
        </script>
        HTML);
}

/**
 * Pinta el documento completo (cabecera, estilos y pie) y termina.
 *
 * @param string $cabecera HTML ya escapado para la derecha de la cabecera
 * @param string $cuerpo   HTML ya escapado del contenido
 * @return never
 */
function documento(int $codigo, string $titulo, string $cabecera, string $cuerpo): void
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Frame-Options: DENY');
    }

    $titulo = escapar($titulo);

    echo <<<HTML
        <!doctype html>
        <html lang="es">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{$titulo} · EMRED</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Lato:wght@900&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
          /* Colores y tipografías de www.emred.com */
          :root {
            --rojo: #fc200d;
            --rojo-oscuro: #d81a09;
            --texto: #383737;
            --texto-suave: #555353;
            --negro: #1b1c20;
            --fondo: #f6f6f6;
            --borde: #e2e2e2;
            --blanco: #ffffff;
            --ok: #1e7b3c;
            --ok-fondo: #eaf6ee;
            --error-fondo: #fff0ee;
          }
          * { box-sizing: border-box; }
          body {
            margin: 0;
            background: var(--fondo);
            color: var(--texto);
            font: 400 15px/1.6 Montserrat, Arial, Helvetica, sans-serif;
          }
          .cabecera { background: var(--blanco); border-bottom: 1px solid var(--borde); }
          .cabecera-interior, main, .pie { max-width: 820px; margin: 0 auto; padding: 0 24px; }
          main.estrecho { max-width: 460px; }
          .cabecera-interior { display: flex; align-items: center; justify-content: space-between; gap: 16px; height: 72px; }
          .cabecera-derecha { display: flex; align-items: center; gap: 20px; }
          .cabecera-derecha form { margin: 0; }
          .logo { font: 900 30px/1 Lato, sans-serif; letter-spacing: -0.5px; color: var(--negro); }
          .logo span { color: var(--rojo); }
          .etiqueta { font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; color: var(--texto-suave); }
          main { padding-top: 40px; padding-bottom: 32px; }
          h1 { font: 900 34px/1.15 Lato, sans-serif; color: var(--negro); margin: 0 0 8px; }
          h1::after { content: ""; display: block; width: 48px; height: 4px; background: var(--rojo); margin-top: 14px; }
          .intro { margin: 0 0 28px; color: var(--texto-suave); max-width: 640px; }
          .tarjeta {
            background: var(--blanco);
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .06), 0 8px 24px rgba(0, 0, 0, .05);
            padding: 32px;
          }
          .campo + .campo { margin-top: 24px; }
          label { display: block; font-weight: 600; color: var(--negro); margin-bottom: 6px; }
          .ayuda { display: block; font-size: 13px; color: var(--texto-suave); margin-top: 6px; }
          .ayuda code { background: var(--fondo); padding: 1px 5px; border-radius: 4px; }
          input[type=text], input[type=password], textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--borde);
            border-radius: 6px;
            background: var(--blanco);
            color: var(--texto);
            font: inherit;
            transition: border-color .15s, box-shadow .15s;
          }
          input:focus, textarea:focus { outline: none; border-color: var(--rojo); box-shadow: 0 0 0 3px rgba(252, 32, 13, .15); }
          .prefijo { display: flex; align-items: stretch; }
          .prefijo span {
            display: flex; align-items: center; padding: 0 12px;
            background: var(--fondo); border: 1px solid var(--borde); border-right: 0;
            border-radius: 6px 0 0 6px; font-size: 13px; color: var(--texto-suave); white-space: nowrap;
          }
          .prefijo input { border-radius: 0 6px 6px 0; }
          textarea { min-height: 340px; resize: vertical; font: 13px/1.5 ui-monospace, SFMono-Regular, Consolas, monospace; }
          .barra-json { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 8px; }
          .estado-json { font-size: 13px; color: var(--texto-suave); }
          .estado-json.mal { color: var(--rojo-oscuro); }
          .estado-json.bien { color: var(--ok); }
          .casilla { display: flex; gap: 10px; align-items: flex-start; font-weight: 500; color: var(--texto); cursor: pointer; margin: 0; }
          .casilla input { margin-top: 5px; accent-color: var(--rojo); }
          .acciones { display: flex; justify-content: flex-end; margin-top: 28px; }
          .boton {
            display: inline-block;
            padding: 12px 24px;
            border: 0;
            border-radius: 6px;
            background: var(--rojo);
            color: var(--blanco);
            font: 600 15px/1.2 Montserrat, sans-serif;
            cursor: pointer;
            transition: background .15s;
          }
          .boton:hover { background: var(--rojo-oscuro); }
          .boton:focus-visible { outline: 3px solid rgba(252, 32, 13, .35); outline-offset: 2px; }
          .boton-ancho { width: 100%; }
          .boton-secundario { background: var(--blanco); color: var(--negro); border: 1px solid var(--borde); padding: 8px 14px; font-size: 13px; white-space: nowrap; }
          .boton-secundario:hover { background: var(--fondo); }
          .aviso { border-radius: 8px; padding: 16px 18px; margin-bottom: 24px; border-left: 4px solid; }
          .aviso-error { background: var(--error-fondo); border-color: var(--rojo); }
          .aviso-ok { background: var(--ok-fondo); border-color: var(--ok); }
          .url { display: flex; gap: 8px; margin-top: 10px; }
          .url input { font: 13px ui-monospace, SFMono-Regular, Consolas, monospace; }
          .pie { font-size: 12px; color: var(--texto-suave); padding-bottom: 32px; }
          @media (max-width: 600px) {
            .tarjeta { padding: 20px; }
            h1 { font-size: 27px; }
            .etiqueta, .prefijo span { display: none; }
            .prefijo input { border-radius: 6px; }
            .url, .barra-json { flex-direction: column; align-items: stretch; }
          }
        </style>
        </head>
        <body>
        <header class="cabecera">
          <div class="cabecera-interior">
            <span class="logo">EMRED<span>.</span></span>
            <div class="cabecera-derecha">
              <span class="etiqueta">Loyalty · SalesManago</span>
              {$cabecera}
            </div>
          </div>
        </header>

        {$cuerpo}

        <footer class="pie">EMRED · Herramienta interna. No compartas esta página ni tu contraseña.</footer>
        </body>
        </html>
        HTML;

    exit;
}
