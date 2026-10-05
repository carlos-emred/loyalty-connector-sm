<?php

declare(strict_types=1);

/**
 * Registro de un usuario en el club de fidelización, contra SalesManago.
 *
 * Recibe los datos del formulario del storefront, los guarda en sm_clientes y
 * crea o actualiza el contacto en SalesManago. No busca perfiles previos ni
 * distingue registro de login: el upsert de SalesManago ya resuelve el caso
 * de un contacto existente.
 *
 * ORDEN
 * -----
 * Primero la base, después SalesManago. Si SalesManago falla, la fila queda
 * con sincronizado_en a NULL y el usuario recibe un error; al reintentar el
 * registro se vuelve a enviar. Al revés, un fallo de la base dejaría un
 * contacto en SalesManago sin rastro local.
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
 *
 * ENTRADA
 * -------
 *   POST /registro_club.php
 *   X-Loyalty-Key: <secreto>
 *
 *   {
 *     "dni":        "12345678A",
 *     "email":      "cliente@…",
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
 *   502 {"success": false, "message": "No se pudo completar el registro"}
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\ApiSalesManago;
use SalesManago\Auth;
use SalesManago\Cors;
use SalesManago\Db;
use SalesManago\Log;

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

if ($dni === '' || $email === '' || $telefono === '' || $nombre === '') {
    responder(400, false, 'Datos necesarios incompletos');
}

if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    responder(400, false, 'El email no es válido');
}

$fechaNacimiento = $cumpleanos !== '' ? normalizarFecha($cumpleanos) : null;

if ($cumpleanos !== '' && $fechaNacimiento === null) {
    // No se rechaza el registro por esto: es un dato opcional. Se registra sin
    // fecha y queda constancia para revisar el formato del formulario.
    Log::warning('Registro: fecha de nacimiento con formato no reconocido; se ignora');
}

$idFila = guardarEnBbdd($email, $dni, $nombre, $apellido, $telefono, $genero, $fechaNacimiento);

try {
    $contactId = registrarEnSalesManago($email, $dni, $nombre, $apellido, $telefono, $genero, $fechaNacimiento);
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

Log::info('Usuario registrado en el club', ['fila' => $idFila]);

responder(200, true, 'Registro correcto');

// -----------------------------------------------------------------------------

/**
 * Crea o actualiza la fila del usuario y devuelve su id.
 *
 * Un segundo registro con el mismo correo actualiza los datos en lugar de
 * fallar: es el mismo usuario corrigiendo el formulario. Los opcionales que
 * llegan vacíos no borran los que ya se conocían. voucherify_id no se toca:
 * lo escribe completar_usuario.php.
 */
function guardarEnBbdd(
    string $email,
    string $dni,
    string $nombre,
    string $apellido,
    string $telefono,
    string $genero,
    ?DateTimeImmutable $fechaNacimiento,
): int {
    return (int) Db::valor(
        'INSERT INTO sm_clientes
                (cliente, email, dni, nombre, apellido, telefono, genero, fecha_nacimiento, registrado_en)
         VALUES (:cli, :email, :dni, :nombre, :apellido, :telefono, :genero, :fecha, now())
         ON CONFLICT (cliente, email) DO UPDATE
            SET dni = EXCLUDED.dni,
                nombre = EXCLUDED.nombre,
                apellido = COALESCE(EXCLUDED.apellido, sm_clientes.apellido),
                telefono = EXCLUDED.telefono,
                genero = COALESCE(EXCLUDED.genero, sm_clientes.genero),
                fecha_nacimiento = COALESCE(EXCLUDED.fecha_nacimiento, sm_clientes.fecha_nacimiento),
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
        ]
    );
}

/**
 * Envía el contacto a SalesManago y devuelve su contactId.
 *
 * Los nombres de «properties» (genero, dni) son los del script original: las
 * segmentaciones de SalesManago se apoyan en ellos.
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
            ['genero' => $genero, 'dni' => $dni],
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
