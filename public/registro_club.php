<?php

declare(strict_types=1);

/**
 * Registro de un usuario en el club de fidelización, contra Voucherify y
 * SalesManago.
 *
 * Recibe los datos del formulario del storefront, los guarda en sm_clientes,
 * crea o actualiza el cliente en Voucherify y después el contacto en
 * SalesManago. No busca perfiles previos ni distingue registro de login: los
 * dos upserts ya resuelven el caso de un usuario existente.
 *
 * ORDEN
 * -----
 * 1. La base. Un fallo posterior deja rastro local de lo que se intentó.
 * 2. Voucherify. Su upsert por source_id es idempotente: si falla aquí, el
 *    usuario recibe un error y al reintentar no se duplica nada.
 * 3. SalesManago, ya con el voucherifyId en las propiedades. Va el último
 *    porque su upsert puede enviar el correo de doble opt-in: si fuese antes
 *    y fallase Voucherify, el reintento del usuario lo enviaría otra vez.
 *
 * Si SalesManago falla, la fila queda con sincronizado_en a NULL y el usuario
 * recibe un error; al reintentar el registro se vuelve a enviar todo.
 *
 * El id de Voucherify sale de la respuesta del alta, así que no hace falta
 * esperar a su webhook customer.created: este script es el único que escribe
 * voucherify_id en sm_clientes y voucherifyId en SalesManago.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL (v2/registro_club.php)
 * ---------------------------------------------------------
 * - El original definía registrarUsuario() pero nunca la llamaba: validaba los
 *   datos y terminaba sin enviar nada. Aquí se envía.
 * - Las credenciales salen del código al .env, y el payload ya no se escribe
 *   en un .txt del docroot: llevaba la apiKey y los datos personales.
 * - La fecha de nacimiento se normaliza al formato que espera SalesManago
 *   (AAAAMMDD). El original la pasaba tal como llegaba.
 * - Se admite «cumpleanos» además de «cumpleaños», que es la clave que envía
 *   hoy el formulario.
 * - Los indicadores de consentimiento (forceOptIn, forceOptOut...) se envían
 *   exactamente como en el original. Ver la nota en registrarEnSalesManago().
 * - El alta en Voucherify del borrador v2 usaba el correo como source_id. Aquí
 *   va el DNI, que es el convenio de los dos conectores (ver
 *   registrarEnVoucherify()).
 * - La contraseña se guarda en sm_clientes como hash (password_hash), nunca en
 *   claro, y no se envía a SalesManago ni a Voucherify. El borrador v2 la
 *   mandaba en claro como propiedad «pass» del contacto, donde la vería
 *   cualquiera con acceso al CRM o a sus exportaciones.
 *
 * CONTRASEÑA
 * ----------
 * Obligatoria. Debe coincidir con password_repetida y tener entre
 * PASSWORD_MIN_CARACTERES caracteres y PASSWORD_MAX_BYTES bytes: bcrypt ignora
 * lo que pase de 72 bytes, y aceptarlo daría por buena cualquier contraseña
 * que compartiese ese prefijo.
 *
 * Un segundo registro con el mismo correo no cambia la contraseña ya
 * guardada: si lo hiciera, cualquiera que conociese el correo de un socio
 * podría sustituírsela. Cambiarla es tarea de un flujo propio, con el usuario
 * identificado.
 *
 * ENTRADA
 * -------
 *   POST /registro_club.php
 *   X-Loyalty-Key: <secreto>
 *
 *   {
 *     "dni":        "12345678A",
 *     "email":      "cliente@…",
 *     "password":          "…",
 *     "password_repetida": "…",
 *     "nombre":     "…",
 *     "apellido":   "…",
 *     "telefono":   "…",
 *     "genero":     "…",
 *     "cumpleaños": "1990-05-17"
 *   }
 *
 * SALIDA
 * ------
 *   200 {"success": true,  "message": "Registro correcto"}
 *   400 {"success": false, "message": "Datos necesarios incompletos"}
 *   400 {"success": false, "message": "Las contraseñas no coinciden"}
 *   400 {"success": false, "message": "La contraseña debe tener entre 8 y 72 caracteres"}
 *   502 {"success": false, "message": "No se pudo completar el registro"}
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\ApiSalesManago;
use SalesManago\ApiVoucherify;
use SalesManago\Auth;
use SalesManago\Cors;
use SalesManago\Db;
use SalesManago\Log;

/** Longitud mínima de la contraseña, en caracteres. */
const PASSWORD_MIN_CARACTERES = 8;

/** Límite de bcrypt: lo que pase de aquí no interviene en el hash. */
const PASSWORD_MAX_BYTES = 72;

Cors::aplicar();
Auth::exigirMetodo('POST');
Auth::exigir();

$entrada = Auth::cuerpoJson();

$dni        = trim((string) ($entrada['dni'] ?? ''));
$email      = mb_strtolower(trim((string) ($entrada['email'] ?? '')));
$nombre     = trim((string) ($entrada['nombre'] ?? ''));
$apellido   = trim((string) ($entrada['apellido'] ?? ''));
$telefono   = trim((string) ($entrada['telefono'] ?? ''));
$genero     = trim((string) ($entrada['genero'] ?? ''));
$cumpleanos = trim((string) ($entrada['cumpleaños'] ?? $entrada['cumpleanos'] ?? ''));

// Sin trim: los espacios forman parte de la contraseña.
$password         = (string) ($entrada['password'] ?? '');
$passwordRepetida = (string) ($entrada['password_repetida'] ?? '');

if ($email === '' || $telefono === '' || $nombre === '' || $password === '') {
    responder(400, false, 'Datos necesarios incompletos');
}

if (!hash_equals($password, $passwordRepetida)) {
    responder(400, false, 'Las contraseñas no coinciden');
}

if (mb_strlen($password) < PASSWORD_MIN_CARACTERES || strlen($password) > PASSWORD_MAX_BYTES) {
    responder(400, false, sprintf(
        'La contraseña debe tener entre %d y %d caracteres',
        PASSWORD_MIN_CARACTERES,
        PASSWORD_MAX_BYTES
    ));
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// A partir de aquí la contraseña en claro no se necesita.
unset($password, $passwordRepetida, $entrada['password'], $entrada['password_repetida']);

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    responder(400, false, 'El email no es válido');
}

$fechaNacimiento = $cumpleanos !== '' ? normalizarFecha($cumpleanos) : null;

if ($cumpleanos !== '' && $fechaNacimiento === null) {
    // No se rechaza el registro por esto: es un dato opcional. Se registra sin
    // fecha y queda constancia para revisar el formato del formulario.
    Log::warning('Registro: fecha de nacimiento con formato no reconocido; se ignora');
}

$idFila = guardarEnBbdd($email, $dni, $nombre, $apellido, $telefono, $genero, $fechaNacimiento, $passwordHash);

try {
    $voucherifyId = registrarEnVoucherify($email, $dni, $nombre, $apellido, $telefono, $genero, $fechaNacimiento);
} catch (Throwable $e) {
    Log::error('Registro: Voucherify no confirmó el alta', [
        'fila'    => $idFila,
        'mensaje' => $e->getMessage(),
    ]);

    responder(502, false, 'No se pudo completar el registro');
}

Db::ejecutar(
    'UPDATE sm_clientes
        SET voucherify_id = :voucherify,
            actualizado_en = now()
      WHERE id = :id',
    ['voucherify' => $voucherifyId, 'id' => $idFila]
);

try {
    $contactId = registrarEnSalesManago($email, $dni, $nombre, $apellido, $telefono, $genero, $voucherifyId, $fechaNacimiento);
} catch (Throwable $e) {
    Log::error('Registro: SalesManago no confirmó el alta', [
        'fila'    => $idFila,
        'mensaje' => $e->getMessage(),
    ]);

    responder(502, false, 'No se pudo completar el registro');
}

Db::ejecutar(
    'UPDATE sm_clientes
        SET salesmanago_contact_id = COALESCE(:contacto, salesmanago_contact_id),
            sincronizado_en = now(),
            actualizado_en = now()
      WHERE id = :id',
    ['contacto' => $contactId, 'id' => $idFila]
);

Log::info('Usuario registrado en el club', ['fila' => $idFila, 'customer' => $voucherifyId]);

responder(200, true, 'Registro correcto');

// -----------------------------------------------------------------------------

/**
 * Crea o actualiza la fila del usuario y devuelve su id.
 *
 * Un segundo registro con el mismo correo actualiza los datos en lugar de
 * fallar: es el mismo usuario corrigiendo el formulario. Los opcionales que
 * llegan vacíos no borran los que ya se conocían. voucherify_id no se toca
 * aquí: se escribe cuando Voucherify confirma el alta.
 *
 * password_hash solo se escribe si la fila no tenía uno: un nuevo registro con
 * el mismo correo no sustituye la contraseña (ver CONTRASEÑA, arriba).
 */
function guardarEnBbdd(
    string $email,
    string $dni,
    string $nombre,
    string $apellido,
    string $telefono,
    string $genero,
    ?DateTimeImmutable $fechaNacimiento,
    string $passwordHash,
): int {
    return (int) Db::valor(
        'INSERT INTO sm_clientes
                (cliente, email, dni, nombre, apellido, telefono, genero, fecha_nacimiento, password_hash, registrado_en)
         VALUES (:cli, :email, :dni, :nombre, :apellido, :telefono, :genero, :fecha, :hash, now())
         ON CONFLICT (cliente, email) DO UPDATE
            SET dni = EXCLUDED.dni,
                nombre = EXCLUDED.nombre,
                apellido = COALESCE(EXCLUDED.apellido, sm_clientes.apellido),
                telefono = EXCLUDED.telefono,
                genero = COALESCE(EXCLUDED.genero, sm_clientes.genero),
                fecha_nacimiento = COALESCE(EXCLUDED.fecha_nacimiento, sm_clientes.fecha_nacimiento),
                password_hash = COALESCE(sm_clientes.password_hash, EXCLUDED.password_hash),
                registrado_en = now(),
                actualizado_en = now()
         RETURNING id',
        [
            'cli'      => Db::cliente(),
            'email'    => $email,
            'dni'      => $dni,
            'nombre'   => $nombre,
            'apellido' => $apellido !== '' ? $apellido : null,
            'telefono' => $telefono,
            'genero'   => $genero !== '' ? $genero : null,
            'fecha'    => $fechaNacimiento?->format('Y-m-d'),
            'hash'     => $passwordHash,
        ]
    );
}

/**
 * Crea o actualiza el cliente en Voucherify y devuelve su id (cust_...).
 *
 * SOURCE_ID
 * ---------
 * Va el DNI, no el correo como en el borrador v2: es el convenio de los
 * conectores (el de Blueshift publica el source_id como «dni»). Con el correo,
 * un usuario dado de alta por otra vía con su DNI quedaría duplicado.
 *
 * Los nombres de «metadata» (last_name, genero, dni) son los del borrador.
 * Los opcionales vacíos no se envían, para no vaciar los que ya tenga el
 * cliente.
 *
 * @throws RuntimeException si Voucherify no confirma
 */
function registrarEnVoucherify(
    string $email,
    string $dni,
    string $nombre,
    string $apellido,
    string $telefono,
    string $genero,
    ?DateTimeImmutable $fechaNacimiento,
): string {
    $datos = [
        'source_id' => $dni,
        'email'     => $email,
        'phone'     => $telefono,
        'name'      => $nombre,
        'metadata'  => array_filter(
            ['last_name' => $apellido, 'genero' => $genero, 'dni' => $dni],
            static fn (string $valor): bool => $valor !== ''
        ),
    ];

    if ($fechaNacimiento !== null) {
        $datos['birthdate'] = $fechaNacimiento->format('Y-m-d');
    }

    return ApiVoucherify::upsertCliente($datos);
}

/**
 * Envía el contacto a SalesManago y devuelve su contactId.
 *
 * Los nombres de «properties» (genero, dni, voucherifyId) son los de los
 * scripts originales: las segmentaciones de SalesManago se apoyan en ellos.
 *
 * CONSENTIMIENTOS
 * ---------------
 * forceOptIn y forceOptOut van los dos a true, igual que los de teléfono,
 * porque así estaban en el original. Son contradictorios entre sí y conviene
 * confirmar con marketing qué estado debe quedar antes de producción. No se
 * han cambiado para no alterar el comportamiento acordado sin decirlo.
 *
 * SIN REINTENTOS AMBIGUOS
 * -----------------------
 * Con useApiDoubleOptIn, cada upsert puede enviar el correo de confirmación.
 * Repetir una llamada que quizá ya llegó podría mandarlo dos veces.
 *
 * @throws RuntimeException si SalesManago no confirma
 */
function registrarEnSalesManago(
    string $email,
    string $dni,
    string $nombre,
    string $apellido,
    string $telefono,
    string $genero,
    string $voucherifyId,
    ?DateTimeImmutable $fechaNacimiento,
): ?string {
    $datos = [
        'contact' => [
            'email' => $email,
            'phone' => $telefono,
            'name'  => trim($nombre . ' ' . $apellido),
        ],
        // Sin género no se envía la propiedad, para no vaciar la que ya tenga
        // el contacto.
        'properties' => array_filter(
            ['genero' => $genero, 'dni' => $dni, 'voucherifyId' => $voucherifyId],
            static fn (string $valor): bool => $valor !== ''
        ),
        'forceOptIn'          => true,
        'forceOptOut'         => true,
        'forcePhoneOptIn'     => true,
        'forcePhoneOptOut'    => true,
        'whatsAppOptStatus'   => true,
        'useApiDoubleOptIn'   => true,
        'doubleOptInLanguage' => 'ES',
    ];

    if ($fechaNacimiento !== null) {
        $datos['birthday'] = $fechaNacimiento->format('Ymd');
    }

    return ApiSalesManago::upsertContacto($datos, reintentarAmbiguos: false);
}

/**
 * Interpreta la fecha del formulario. Admite los formatos que puede enviar un
 * input de tipo date o un campo de texto en España.
 */
function normalizarFecha(string $valor): ?DateTimeImmutable
{
    foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'Ymd'] as $formato) {
        $fecha = DateTimeImmutable::createFromFormat('!' . $formato, $valor);

        // createFromFormat acepta desbordamientos («31/02» pasa a marzo). La
        // comprobación de ida y vuelta los descarta.
        if ($fecha !== false && $fecha->format($formato) === $valor) {
            return $fecha;
        }
    }

    return null;
}

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
