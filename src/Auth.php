<?php

declare(strict_types=1);

namespace SalesManago;

/**
 * Control de acceso a los endpoints.
 *
 * Sustituye a las 15 copias de este bloque:
 *
 *     if (!isset($_GET['key']) || $_GET['key'] !== API_KEY) { ... 403 ... }
 *
 * Cambios respecto a esa versión:
 *
 * 1. El secreto se lee de una cabecera HTTP, no de la query string. En la
 *    query string quedaba registrado en los logs de acceso de nginx, en
 *    cualquier proxy intermedio y en el historial del navegador.
 *
 * 2. La comparación usa hash_equals(), que tarda lo mismo acierte o falle.
 *    Con «!==» el tiempo de respuesta depende de cuántos caracteres
 *    coincidan, lo que permite deducir el secreto carácter a carácter.
 *
 * 3. Se admite temporalmente el parámetro ?key= para no romper integraciones
 *    durante la migración, pero desactivado por defecto y dejando aviso en el
 *    log cada vez que se usa.
 *
 * LIMITACIÓN IMPORTANTE, PENDIENTE DE DECISIÓN
 * --------------------------------------------
 * Este mecanismo protege razonablemente los 6 webhooks, que son llamadas de
 * servidor a servidor: el secreto vive en la configuración de Voucherify y
 * nadie más lo ve.
 *
 * No protege los 7 endpoints invocados desde el navegador. Ahí el secreto
 * viaja en el código JavaScript del storefront, así que cualquier visitante
 * puede leerlo abriendo las herramientas de desarrollo. Un secreto publicado
 * no es un secreto: para esos endpoints la única barrera real hoy es la lista
 * CORS, que el navegador respeta pero que no impide una petición hecha con
 * curl.
 *
 * Resolverlo requiere un modelo distinto (token de sesión emitido por el
 * servidor, firma por petición, o intermediar desde el backend de Shopify) y
 * es una decisión de diseño aparte. Se documenta aquí para que quede
 * constancia de que la protección de esos 7 endpoints es nominal.
 */
final class Auth
{
    /** Cabecera donde se espera el secreto. */
    public const CABECERA = 'X-Loyalty-Key';

    private static ?string $secreto = null;
    private static bool $admitirQueryString = false;

    /**
     * @param string $secreto             Valor esperado
     * @param bool   $admitirQueryString  Si true, se acepta también ?key=
     */
    public static function configurar(string $secreto, bool $admitirQueryString = false): void
    {
        self::$secreto = $secreto;
        self::$admitirQueryString = $admitirQueryString;
    }

    /**
     * Comprueba el secreto sin cortar la ejecución.
     */
    public static function verificar(): bool
    {
        if (self::$secreto === null || self::$secreto === '') {
            // Sin secreto configurado no se autoriza nada. Fallar cerrado es
            // preferible a que un despliegue con el .env incompleto deje los
            // endpoints abiertos.
            Log::error('Auth: no hay secreto configurado; se deniega el acceso.');
            return false;
        }

        $recibido = self::extraer();

        if ($recibido === null) {
            return false;
        }

        return hash_equals(self::$secreto, $recibido);
    }

    /**
     * Comprueba el secreto y, si no es válido, responde 403 y termina.
     *
     * La respuesta no distingue entre «falta el secreto» y «el secreto es
     * incorrecto»: dar ese detalle solo ayuda a quien está probando.
     */
    public static function exigir(): void
    {
        if (self::verificar()) {
            return;
        }

        Log::warning('Auth: acceso denegado', [
            'ruta'   => self::ruta(),
            'origen' => self::ipCliente(),
        ]);

        self::responderYSalir(403, 'Acceso no autorizado.');
    }

    /**
     * Restringe el método HTTP admitido.
     *
     * Los webhooks deben aceptar solo POST. Hoy ninguno lo comprueba, de modo
     * que una petición GET llega al cuerpo del script y falla más adelante de
     * forma poco clara.
     */
    public static function exigirMetodo(string ...$metodos): void
    {
        $actual = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
        $permitidos = array_map('strtoupper', $metodos);

        if (in_array($actual, $permitidos, true)) {
            return;
        }

        header('Allow: ' . implode(', ', $permitidos));

        self::responderYSalir(
            405,
            sprintf('Método no permitido. Se esperaba: %s.', implode(', ', $permitidos))
        );
    }

    /**
     * Lee y decodifica el cuerpo JSON de la petición.
     *
     * Los scripts actuales hacen json_decode(file_get_contents('php://input'))
     * sin comprobar el resultado, de modo que un cuerpo mal formado produce
     * null y el script sigue adelante accediendo a índices de null.
     *
     * @return array<string,mixed>
     */
    public static function cuerpoJson(bool $obligatorio = true): array
    {
        $bruto = file_get_contents('php://input');

        if ($bruto === false || trim($bruto) === '') {
            if ($obligatorio) {
                self::responderYSalir(400, 'El cuerpo de la petición está vacío.');
            }
            return [];
        }

        $datos = json_decode($bruto, true);

        if (!is_array($datos)) {
            Log::warning('Auth: cuerpo JSON no válido', [
                'error'  => json_last_error_msg(),
                'bytes'  => strlen($bruto),
            ]);

            self::responderYSalir(400, 'El cuerpo de la petición no es JSON válido.');
        }

        return $datos;
    }

    private static function extraer(): ?string
    {
        // Cabecera, vía normal
        $cabecera = self::leerCabecera(self::CABECERA);

        if ($cabecera !== null && $cabecera !== '') {
            return $cabecera;
        }

        // Query string, solo durante la migración
        if (self::$admitirQueryString) {
            $porQuery = $_GET['key'] ?? null;

            if (is_string($porQuery) && $porQuery !== '') {
                Log::warning(
                    'Auth: secreto recibido por query string. Esta vía se retirará; '
                    . 'migra el llamante a la cabecera ' . self::CABECERA . '.',
                    ['ruta' => self::ruta()]
                );

                return $porQuery;
            }
        }

        return null;
    }

    private static function leerCabecera(string $nombre): ?string
    {
        // Forma canónica: HTTP_X_LOYALTY_KEY
        $clave = 'HTTP_' . strtoupper(str_replace('-', '_', $nombre));

        if (isset($_SERVER[$clave]) && is_string($_SERVER[$clave])) {
            return trim($_SERVER[$clave]);
        }

        // Respaldo para configuraciones de FPM que no propagan todas las cabeceras
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, $nombre) === 0 && is_string($v)) {
                    return trim($v);
                }
            }
        }

        return null;
    }

    /**
     * Ruta de la petición, sin la query string.
     *
     * La query string puede llevar el secreto en ?key=, y el llamante lo envía
     * aunque esa vía esté desactivada. Log enmascara por el nombre de la clave
     * del contexto, no por su valor, así que registrar la URI entera dejaba el
     * secreto en claro.
     *
     * Se corta por el primer «?» en lugar de usar parse_url(), que devuelve
     * false con URI como «///salud.php?key=...». nginx las sirve, porque junta
     * las barras para elegir el fichero pero pasa a PHP la URI original.
     */
    private static function ruta(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $ruta = is_string($uri) ? explode('?', $uri, 2)[0] : '';

        return $ruta !== '' ? $ruta : '(desconocida)';
    }

    /**
     * IP del cliente. Solo se confía en X-Forwarded-For si se declara
     * explícitamente que hay un proxy delante, porque esa cabecera la puede
     * falsificar cualquiera si nginx no la sobrescribe.
     */
    private static function ipCliente(): string
    {
        if (Config::getBool('CONFIAR_EN_PROXY', false)) {
            $reenviada = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

            if (is_string($reenviada) && $reenviada !== '') {
                $primera = trim(explode(',', $reenviada)[0]);
                if (filter_var($primera, FILTER_VALIDATE_IP) !== false) {
                    return $primera;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '(desconocida)';
    }

    /**
     * @return never
     */
    private static function responderYSalir(int $codigo, string $mensaje): void
    {
        if (!headers_sent()) {
            http_response_code($codigo);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            ['success' => false, 'message' => $mensaje],
            JSON_UNESCAPED_UNICODE
        );

        exit;
    }
}
