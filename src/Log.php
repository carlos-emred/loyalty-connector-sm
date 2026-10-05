<?php

declare(strict_types=1);

namespace SalesManago;

/**
 * Registro de eventos de la aplicación.
 *
 * Sustituye al patrón actual, en el que cada uno de los 15 scripts abre su
 * propio fichero .txt con fopen() y ruta relativa. Ese enfoque tenía tres
 * problemas: cinco ficheros usaban modo «w», que vacía el log en cada
 * petición; las rutas relativas dependían del directorio de trabajo; y los
 * ficheros quedaban dentro del docroot, por tanto descargables desde internet.
 *
 * Aquí no se escribe en ningún fichero propio:
 *
 *   - En CLI (el worker) se escribe a STDERR, que systemd recoge en journald.
 *     Consulta con: journalctl -u salesmanago-worker -f
 *   - Bajo PHP-FPM se usa error_log(), que va al log de errores de FPM.
 *
 * En ninguno de los dos casos hay un fichero servible por nginx.
 *
 * REDACCIÓN
 * ---------
 * El contexto se depura antes de escribirse. Las claves cuyo nombre sugiere
 * credencial se sustituyen por «***», y los correos se enmascaran. Esto es
 * deliberadamente conservador: los logs de este proyecto contienen datos de
 * clientes y no deben convertirse en un almacén paralelo de datos personales.
 */
final class Log
{
    public const DEBUG   = 'debug';
    public const INFO    = 'info';
    public const WARNING = 'warning';
    public const ERROR   = 'error';

    /** Orden de severidad, para el filtrado por nivel mínimo. */
    private const SEVERIDAD = [
        self::DEBUG   => 10,
        self::INFO    => 20,
        self::WARNING => 30,
        self::ERROR   => 40,
    ];

    /**
     * Fragmentos que, si aparecen en el nombre de una clave del contexto,
     * provocan que su valor se sustituya por «***».
     */
    private const CLAVES_SENSIBLES = [
        'authorization', 'auth', 'api_key', 'apikey', 'access_token',
        'token', 'secret', 'password', 'passwd', 'clave', 'credential',
        'x-loyalty-key', 'signature',
    ];

    private static string $nivelMinimo = self::INFO;
    private static string $formato = 'texto';
    private static bool $enmascararEmails = true;
    private static ?string $correlacion = null;
    private static ?string $canal = null;

    /**
     * @param string $nivelMinimo       debug|info|warning|error
     * @param string $formato           texto|json
     * @param bool   $enmascararEmails  Si false, los correos se escriben enteros
     */
    public static function configurar(
        string $nivelMinimo = self::INFO,
        string $formato = 'texto',
        bool $enmascararEmails = true,
    ): void {
        if (!isset(self::SEVERIDAD[$nivelMinimo])) {
            $nivelMinimo = self::INFO;
        }

        self::$nivelMinimo = $nivelMinimo;
        self::$formato = $formato === 'json' ? 'json' : 'texto';
        self::$enmascararEmails = $enmascararEmails;
    }

    /**
     * Identificador que permite seguir una misma petición o evento a lo largo
     * de varias líneas de log, e incluso entre el endpoint que encola y el
     * worker que procesa. Conviene fijarlo al principio de cada petición.
     */
    public static function fijarCorrelacion(?string $id = null): string
    {
        self::$correlacion = $id ?? bin2hex(random_bytes(6));

        return self::$correlacion;
    }

    public static function correlacion(): ?string
    {
        return self::$correlacion;
    }

    /**
     * Etiqueta del componente que emite: «enviar_email», «worker», etc.
     * Sustituye a la función de los antiguos nombres de fichero de log.
     */
    public static function fijarCanal(?string $canal): void
    {
        self::$canal = $canal;
    }

    public static function debug(string $mensaje, array $contexto = []): void
    {
        self::escribir(self::DEBUG, $mensaje, $contexto);
    }

    public static function info(string $mensaje, array $contexto = []): void
    {
        self::escribir(self::INFO, $mensaje, $contexto);
    }

    public static function warning(string $mensaje, array $contexto = []): void
    {
        self::escribir(self::WARNING, $mensaje, $contexto);
    }

    public static function error(string $mensaje, array $contexto = []): void
    {
        self::escribir(self::ERROR, $mensaje, $contexto);
    }

    /**
     * Devuelve un callable compatible con Http::usarLogger().
     * Se conecta en bootstrap.php con: Http::usarLogger(Log::comoCallable());
     */
    public static function comoCallable(): callable
    {
        return static function (string $nivel, string $mensaje, array $contexto = []): void {
            self::escribir($nivel, $mensaje, $contexto);
        };
    }

    private static function escribir(string $nivel, string $mensaje, array $contexto): void
    {
        $severidad = self::SEVERIDAD[$nivel] ?? self::SEVERIDAD[self::INFO];

        if ($severidad < self::SEVERIDAD[self::$nivelMinimo]) {
            return;
        }

        $contexto = self::redactar($contexto);

        if (self::$canal !== null) {
            $contexto = ['canal' => self::$canal] + $contexto;
        }

        if (self::$correlacion !== null) {
            $contexto = ['id' => self::$correlacion] + $contexto;
        }

        $linea = self::$formato === 'json'
            ? self::formatoJson($nivel, $mensaje, $contexto)
            : self::formatoTexto($nivel, $mensaje, $contexto);

        self::emitir($linea);
    }

    private static function formatoTexto(string $nivel, string $mensaje, array $contexto): string
    {
        $linea = sprintf('[%s] %s', strtoupper($nivel), $mensaje);

        if ($contexto === []) {
            return $linea;
        }

        $pares = [];
        foreach ($contexto as $clave => $valor) {
            $pares[] = $clave . '=' . self::escalar($valor);
        }

        return $linea . ' ' . implode(' ', $pares);
    }

    private static function formatoJson(string $nivel, string $mensaje, array $contexto): string
    {
        $registro = [
            'ts'      => date('c'),
            'nivel'   => $nivel,
            'mensaje' => $mensaje,
        ] + $contexto;

        $json = json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json !== false ? $json : self::formatoTexto($nivel, $mensaje, $contexto);
    }

    private static function escalar(mixed $valor): string
    {
        return match (true) {
            $valor === null   => 'null',
            is_bool($valor)   => $valor ? 'true' : 'false',
            is_scalar($valor) => (string) $valor,
            default           => json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '(no serializable)',
        };
    }

    /**
     * Depura el contexto antes de escribirlo.
     *
     * @param array<string,mixed> $contexto
     * @return array<string,mixed>
     */
    private static function redactar(array $contexto): array
    {
        $limpio = [];

        foreach ($contexto as $clave => $valor) {
            $claveNormalizada = strtolower((string) $clave);

            foreach (self::CLAVES_SENSIBLES as $fragmento) {
                if (str_contains($claveNormalizada, $fragmento)) {
                    $limpio[$clave] = '***';
                    continue 2;
                }
            }

            if (is_array($valor)) {
                $limpio[$clave] = self::redactar($valor);
                continue;
            }

            if (self::$enmascararEmails && is_string($valor) && str_contains($valor, '@')) {
                $limpio[$clave] = self::enmascararEmail($valor);
                continue;
            }

            $limpio[$clave] = $valor;
        }

        return $limpio;
    }

    /**
     * carlos.murillo@emred.com -> c*************@emred.com
     *
     * Se conserva el dominio y la inicial: suficiente para depurar, insuficiente
     * para reconstruir la dirección.
     */
    private static function enmascararEmail(string $valor): string
    {
        $arroba = strpos($valor, '@');

        if ($arroba === false || $arroba === 0) {
            return $valor;
        }

        $local = substr($valor, 0, $arroba);
        $dominio = substr($valor, $arroba);

        if (strlen($local) <= 1) {
            return '*' . $dominio;
        }

        return $local[0] . str_repeat('*', strlen($local) - 1) . $dominio;
    }

    private static function emitir(string $linea): void
    {
        // En CLI escribimos a STDERR para que systemd lo recoja en journald.
        if (PHP_SAPI === 'cli' && defined('STDERR')) {
            fwrite(STDERR, $linea . PHP_EOL);
            return;
        }

        // Bajo PHP-FPM, error_log() va al log de errores configurado en el pool.
        error_log($linea);
    }
}
