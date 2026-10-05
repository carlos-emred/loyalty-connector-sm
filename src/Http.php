<?php

declare(strict_types=1);

namespace SalesManago;

use CurlHandle;
use RuntimeException;

/**
 * Resultado de una llamada HTTP.
 *
 * Nunca se lanza excepción por un código de error HTTP: un 404 o un 500 de la
 * API remota son respuestas legítimas que el llamante debe poder inspeccionar.
 * Solo se lanza excepción ante errores de programación (URL vacía, payload no
 * serializable).
 */
final class Respuesta
{
    public function __construct(
        /** Código HTTP. 0 si no se llegó a obtener respuesta. */
        public readonly int $codigo,
        public readonly string $cuerpo,
        /** Cuerpo decodificado si era JSON válido; null en caso contrario. */
        public readonly ?array $json,
        /** Descripción del error de transporte, si lo hubo. */
        public readonly ?string $error,
        /** Número de intentos realizados, incluido el primero. */
        public readonly int $intentos,
        public readonly float $duracionMs,
        /** @var array<string,string> Cabeceras de respuesta, nombres en minúsculas. */
        public readonly array $cabeceras = [],
    ) {
    }

    /** Cabecera de respuesta por nombre, sin distinguir mayúsculas. */
    public function cabecera(string $nombre): ?string
    {
        return $this->cabeceras[strtolower($nombre)] ?? null;
    }

    /** Éxito: respuesta recibida con código 2xx. */
    public function ok(): bool
    {
        return $this->codigo >= 200 && $this->codigo < 300;
    }

    /** No se obtuvo respuesta: DNS, conexión rechazada, timeout, TLS. */
    public function fallóTransporte(): bool
    {
        return $this->codigo === 0;
    }

    /**
     * Acceso seguro a una clave del JSON de respuesta mediante ruta con puntos.
     * Ej.: $r->valor('data.voucher.code')
     */
    public function valor(string $ruta, mixed $porDefecto = null): mixed
    {
        if ($this->json === null) {
            return $porDefecto;
        }

        $actual = $this->json;

        foreach (explode('.', $ruta) as $tramo) {
            if (!is_array($actual) || !array_key_exists($tramo, $actual)) {
                return $porDefecto;
            }
            $actual = $actual[$tramo];
        }

        return $actual;
    }

    /** Resumen apto para log: nunca incluye el cuerpo completo. */
    public function resumen(): string
    {
        if ($this->fallóTransporte()) {
            return sprintf(
                'sin respuesta (%s) tras %d intento(s) en %.0f ms',
                $this->error ?? 'error desconocido',
                $this->intentos,
                $this->duracionMs
            );
        }

        return sprintf(
            'HTTP %d tras %d intento(s) en %.0f ms (%d bytes)',
            $this->codigo,
            $this->intentos,
            $this->duracionMs,
            strlen($this->cuerpo)
        );
    }
}

/**
 * Cliente HTTP con timeouts obligatorios.
 *
 * Existe para corregir el problema principal del lote actual: 13 de los 15
 * scripts hacen curl_exec() sin fijar CURLOPT_TIMEOUT, cuyo valor por defecto
 * es infinito. Una respuesta lenta de Blueshift o Voucherify retiene un worker
 * de PHP-FPM indefinidamente; con el pool agotado, el endpoint devuelve 502,
 * Voucherify reintenta y el problema se amplifica.
 *
 * Aquí los timeouts no son opcionales: hay valores por defecto y no existe
 * forma de desactivarlos.
 *
 * POLÍTICA DE REINTENTOS
 * ----------------------
 * Reintentar un POST puede duplicar un efecto (crear dos cupones, sumar dos
 * veces los mismos puntos). Por eso los reintentos son deliberadamente
 * conservadores y solo se producen cuando hay razón para creer que la petición
 * NO llegó a procesarse:
 *
 *   - Error de resolución DNS o de conexión rechazada: no llegó. Se reintenta.
 *   - Timeout SIN conexión establecida: no llegó. Se reintenta.
 *   - Timeout CON conexión establecida: pudo procesarse. NO se reintenta en
 *     métodos con efectos (POST, PUT, PATCH, DELETE); sí en GET.
 *   - HTTP 429 y 503: el servidor declara explícitamente que no atendió la
 *     petición. Se reintenta respetando Retry-After.
 *   - HTTP 500, 502, 504: ambiguo. Se reintenta solo si $reintentarAmbiguos.
 *     Actívalo únicamente contra endpoints idempotentes.
 *
 * El resto de códigos 4xx no se reintentan nunca: reintentar un 400 o un 401
 * solo consume presupuesto.
 */
final class Http
{
    /** Segundos para establecer la conexión. */
    public const TIMEOUT_CONEXION = 5;

    /** Segundos para la operación completa, conexión incluida. */
    public const TIMEOUT_TOTAL = 15;

    /** Reintentos adicionales por defecto (0 = un único intento). */
    public const REINTENTOS = 2;

    /** Espera base del backoff exponencial, en milisegundos. */
    private const BACKOFF_BASE_MS = 400;

    /** Techo de espera entre reintentos, en milisegundos. */
    private const BACKOFF_MAX_MS = 8000;

    /** Handle reutilizado para aprovechar keep-alive en el worker. */
    private static ?CurlHandle $handle = null;

    /** @var callable|null Logger opcional: fn(string $nivel, string $mensaje, array $ctx) */
    private static $logger = null;

    /**
     * Inyecta el logger. Se hace por callable y no por dependencia directa de
     * Log.php para que esta clase sea utilizable de forma aislada en pruebas.
     */
    public static function usarLogger(?callable $logger): void
    {
        self::$logger = $logger;
    }

    public static function postJson(
        string $url,
        array $payload,
        array $cabeceras = [],
        int $reintentos = self::REINTENTOS,
        int $timeoutTotal = self::TIMEOUT_TOTAL,
        bool $reintentarAmbiguos = false,
    ): Respuesta {
        $cuerpo = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($cuerpo === false) {
            throw new RuntimeException(
                'No se pudo serializar el payload a JSON: ' . json_last_error_msg()
            );
        }

        return self::ejecutar(
            metodo: 'POST',
            url: $url,
            cuerpo: $cuerpo,
            cabeceras: array_merge(['Content-Type: application/json'], $cabeceras),
            reintentos: $reintentos,
            timeoutTotal: $timeoutTotal,
            reintentarAmbiguos: $reintentarAmbiguos,
        );
    }

    public static function getJson(
        string $url,
        array $cabeceras = [],
        int $reintentos = self::REINTENTOS,
        int $timeoutTotal = self::TIMEOUT_TOTAL,
    ): Respuesta {
        return self::ejecutar(
            metodo: 'GET',
            url: $url,
            cuerpo: null,
            cabeceras: array_merge(['Accept: application/json'], $cabeceras),
            reintentos: $reintentos,
            timeoutTotal: $timeoutTotal,
            // GET es idempotente por definición: los ambiguos son seguros.
            reintentarAmbiguos: true,
        );
    }

    public static function delete(
        string $url,
        array $cabeceras = [],
        int $reintentos = self::REINTENTOS,
        int $timeoutTotal = self::TIMEOUT_TOTAL,
    ): Respuesta {
        return self::ejecutar(
            metodo: 'DELETE',
            url: $url,
            cuerpo: null,
            cabeceras: $cabeceras,
            reintentos: $reintentos,
            timeoutTotal: $timeoutTotal,
            reintentarAmbiguos: false,
        );
    }

    private static function ejecutar(
        string $metodo,
        string $url,
        ?string $cuerpo,
        array $cabeceras,
        int $reintentos,
        int $timeoutTotal,
        bool $reintentarAmbiguos,
    ): Respuesta {
        if (trim($url) === '') {
            throw new RuntimeException('Http: la URL no puede estar vacía.');
        }

        if ($reintentos < 0) {
            $reintentos = 0;
        }

        $tieneEfectos = in_array($metodo, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        $inicio = microtime(true);
        $intento = 0;
        $ultimo = null;

        while (true) {
            $intento++;
            $ultimo = self::intentoUnico($metodo, $url, $cuerpo, $cabeceras, $timeoutTotal);

            $decision = self::debeReintentar(
                resultado: $ultimo,
                tieneEfectos: $tieneEfectos,
                reintentarAmbiguos: $reintentarAmbiguos,
            );

            if (!$decision || $intento > $reintentos) {
                break;
            }

            $esperaMs = self::calcularEspera($intento, $ultimo['retryAfter']);

            self::log('warning', 'Http: reintentando llamada', [
                'metodo'   => $metodo,
                'host'     => parse_url($url, PHP_URL_HOST) ?: '(desconocido)',
                'intento'  => $intento,
                'codigo'   => $ultimo['codigo'],
                'error'    => $ultimo['error'],
                'espera_ms' => $esperaMs,
            ]);

            usleep($esperaMs * 1000);
        }

        $duracionMs = (microtime(true) - $inicio) * 1000;
        $json = self::decodificar($ultimo['cuerpo']);

        $respuesta = new Respuesta(
            codigo: $ultimo['codigo'],
            cuerpo: $ultimo['cuerpo'],
            json: $json,
            error: $ultimo['error'],
            intentos: $intento,
            duracionMs: $duracionMs,
            cabeceras: $ultimo['cabeceras'] ?? [],
        );

        if (!$respuesta->ok()) {
            self::log('error', 'Http: llamada sin éxito', [
                'metodo'  => $metodo,
                'host'    => parse_url($url, PHP_URL_HOST) ?: '(desconocido)',
                'resumen' => $respuesta->resumen(),
            ]);
        }

        return $respuesta;
    }

    /**
     * @return array{codigo:int, cuerpo:string, error:?string, errno:int, conectado:bool, retryAfter:?int, cabeceras:array<string,string>}
     */
    private static function intentoUnico(
        string $metodo,
        string $url,
        ?string $cuerpo,
        array $cabeceras,
        int $timeoutTotal,
    ): array {
        $ch = self::handle();
        curl_reset($ch);

        $cabecerasRespuesta = [];

        $opciones = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_RETURNTRANSFER => true,

            // Los dos timeouts que faltan hoy en 13 de los 15 scripts.
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_CONEXION,
            CURLOPT_TIMEOUT        => max($timeoutTotal, self::TIMEOUT_CONEXION + 1),

            // Necesario para que los timeouts sean fiables sin depender de señales.
            CURLOPT_NOSIGNAL       => true,

            // Verificación TLS explícita: no se hereda de la configuración del sistema.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            // Desactivado a propósito: seguir redirecciones desde un webhook
            // permitiría que un tercero desviase la petición a otro destino.
            CURLOPT_FOLLOWLOCATION => false,

            // FAILONERROR desactivado: queremos el cuerpo también en los 4xx/5xx,
            // porque las APIs de Blueshift y Voucherify explican ahí el motivo.
            CURLOPT_FAILONERROR    => false,

            CURLOPT_USERAGENT      => 'emred-salesmanago/1.0',
            CURLOPT_ENCODING       => '',

            CURLOPT_HEADERFUNCTION => static function ($ch, string $linea) use (&$cabecerasRespuesta): int {
                $partes = explode(':', $linea, 2);
                if (count($partes) === 2) {
                    $cabecerasRespuesta[strtolower(trim($partes[0]))] = trim($partes[1]);
                }
                return strlen($linea);
            },
        ];

        if ($cabeceras !== []) {
            $opciones[CURLOPT_HTTPHEADER] = $cabeceras;
        }

        if ($cuerpo !== null) {
            $opciones[CURLOPT_POSTFIELDS] = $cuerpo;
        }

        curl_setopt_array($ch, $opciones);

        $respuesta = curl_exec($ch);
        $errno = curl_errno($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $tiempoConexion = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);

        return [
            'codigo'     => $errno === 0 ? $codigo : 0,
            'cuerpo'     => is_string($respuesta) ? $respuesta : '',
            'error'      => $errno === 0 ? null : curl_error($ch),
            'errno'      => $errno,
            // Si el tiempo de conexión es 0, nunca se estableció la conexión y
            // por tanto la petición no pudo llegar al servidor.
            'conectado'  => $tiempoConexion > 0.0,
            'retryAfter' => self::leerRetryAfter($cabecerasRespuesta),
            'cabeceras'  => $cabecerasRespuesta,
        ];
    }

    /**
     * @param array{codigo:int, error:?string, errno:int, conectado:bool, retryAfter:?int} $resultado
     */
    private static function debeReintentar(
        array $resultado,
        bool $tieneEfectos,
        bool $reintentarAmbiguos,
    ): bool {
        // Error de transporte
        if ($resultado['codigo'] === 0) {
            return match ($resultado['errno']) {
                // DNS y conexión: la petición no llegó a salir. Siempre seguro.
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_RESOLVE_PROXY,
                CURLE_COULDNT_CONNECT => true,

                // Timeout: seguro solo si no llegó a establecerse la conexión.
                // Si se estableció, el servidor pudo haber procesado la petición.
                CURLE_OPERATION_TIMEDOUT => !$resultado['conectado'] || !$tieneEfectos,

                // Errores de TLS o de protocolo: reintentar no cambiará nada.
                default => false,
            };
        }

        // El servidor declara explícitamente que no atendió la petición.
        if ($resultado['codigo'] === 429 || $resultado['codigo'] === 503) {
            return true;
        }

        // Ambiguos: pudieron procesarse parcialmente.
        if (in_array($resultado['codigo'], [500, 502, 504], true)) {
            return $reintentarAmbiguos;
        }

        return false;
    }

    /**
     * Backoff exponencial con jitter, salvo que el servidor indique Retry-After.
     */
    private static function calcularEspera(int $intento, ?int $retryAfter): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter * 1000, self::BACKOFF_MAX_MS);
        }

        $base = self::BACKOFF_BASE_MS * (2 ** ($intento - 1));

        // El jitter evita que, tras un pico de 40.000 eventos, todos los
        // reintentos vuelvan a caer simultáneamente sobre el mismo endpoint.
        $jitter = random_int(0, (int) ($base * 0.3));

        return (int) min($base + $jitter, self::BACKOFF_MAX_MS);
    }

    /**
     * Admite las dos formas del estándar: segundos o fecha HTTP.
     *
     * @param array<string,string> $cabeceras
     */
    private static function leerRetryAfter(array $cabeceras): ?int
    {
        $valor = $cabeceras['retry-after'] ?? null;

        if ($valor === null) {
            return null;
        }

        if (preg_match('/^\d+$/', trim($valor))) {
            return (int) trim($valor);
        }

        $fecha = strtotime($valor);
        if ($fecha === false) {
            return null;
        }

        $segundos = $fecha - time();

        return $segundos > 0 ? $segundos : null;
    }

    private static function decodificar(string $cuerpo): ?array
    {
        if (trim($cuerpo) === '') {
            return null;
        }

        $decodificado = json_decode($cuerpo, true);

        return is_array($decodificado) ? $decodificado : null;
    }

    private static function handle(): CurlHandle
    {
        if (self::$handle === null) {
            $ch = curl_init();
            if ($ch === false) {
                throw new RuntimeException('Http: no se pudo inicializar cURL.');
            }
            self::$handle = $ch;
        }

        return self::$handle;
    }

    /**
     * Cierra el handle compartido. Solo necesario en el worker si se quiere
     * forzar la reapertura de conexiones; PHP lo libera al terminar el proceso.
     */
    public static function cerrar(): void
    {
        if (self::$handle !== null) {
            curl_close(self::$handle);
            self::$handle = null;
        }
    }

    private static function log(string $nivel, string $mensaje, array $contexto = []): void
    {
        if (self::$logger === null) {
            return;
        }

        (self::$logger)($nivel, $mensaje, $contexto);
    }
}
