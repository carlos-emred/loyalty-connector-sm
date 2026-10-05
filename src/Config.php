<?php

declare(strict_types=1);

namespace SalesManago;

use RuntimeException;

/**
 * Carga y acceso a la configuración del proyecto.
 *
 * Sustituye a los 40+ define() repartidos por los 15 scripts. Las credenciales
 * pasan a vivir en un único fichero .env situado FUERA del docroot.
 *
 * Orden de precedencia en la resolución de una clave:
 *
 *   1. Variable de entorno del proceso (getenv)
 *   2. Valor leído del fichero .env
 *   3. Valor por defecto pasado como argumento
 *
 * El motivo del punto 1 es que el worker se ejecuta bajo systemd con
 * EnvironmentFile=/opt/salesmanago/.env, de modo que sus variables ya están en el
 * entorno del proceso y no hace falta volver a parsear el fichero. Los scripts
 * bajo PHP-FPM no reciben ese entorno, así que para ellos actúa el punto 2.
 * La misma llamada funciona en ambos contextos sin condicionales.
 *
 * Los valores nunca se incluyen en mensajes de excepción ni en volcados.
 */
final class Config
{
    /** @var array<string,string>|null Valores parseados del .env */
    private static ?array $valores = null;

    /** Ruta del .env efectivamente cargado, para diagnóstico */
    private static ?string $rutaCargada = null;

    /**
     * Carga el fichero .env. Idempotente: la segunda llamada no hace nada
     * salvo que se fuerce con $recargar.
     *
     * @param string $ruta       Ruta absoluta al .env
     * @param bool   $exigirPermisos  Si true, rechaza ficheros legibles por «otros»
     * @param bool   $recargar   Fuerza releer el fichero
     *
     * @throws RuntimeException si el fichero no existe, no se puede leer o
     *                          tiene permisos inseguros
     */
    public static function cargar(
        string $ruta,
        bool $exigirPermisos = true,
        bool $recargar = false
    ): void {
        if (self::$valores !== null && !$recargar) {
            return;
        }

        if (!is_file($ruta)) {
            throw new RuntimeException("No existe el fichero de configuración: {$ruta}");
        }

        if (!is_readable($ruta)) {
            throw new RuntimeException("El fichero de configuración no es legible: {$ruta}");
        }

        if ($exigirPermisos) {
            self::verificarPermisos($ruta);
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false) {
            throw new RuntimeException("Fallo al leer el fichero de configuración: {$ruta}");
        }

        self::$valores = self::parsear($contenido);
        self::$rutaCargada = $ruta;
    }

    /**
     * Rechaza el fichero si es legible por «otros» (bit o+r).
     *
     * Se permite lectura por grupo, para que nginx/php-fpm y el worker puedan
     * compartirlo si están en el mismo grupo. Lo que no se tolera es 0644.
     */
    private static function verificarPermisos(string $ruta): void
    {
        $perms = @fileperms($ruta);
        if ($perms === false) {
            // No poder consultar los permisos no debe tumbar el arranque.
            return;
        }

        if (($perms & 0o004) !== 0) {
            $octal = substr(sprintf('%o', $perms), -4);
            throw new RuntimeException(
                "El fichero de configuración {$ruta} tiene permisos {$octal} y es "
                . "legible por cualquier usuario del sistema. Corrige con: chmod 640 {$ruta}"
            );
        }
    }

    /**
     * Parsea el contenido de un .env.
     *
     * Formato admitido:
     *
     *   CLAVE=valor
     *   CLAVE="valor con espacios"        (admite \n, \t, \" y \\)
     *   CLAVE='valor literal'             (sin interpretación de escapes)
     *   # comentario de línea completa
     *   CLAVE=valor  # comentario al final (solo si va precedido de espacio)
     *
     * La última condición importa: hay credenciales que contienen «#» y no
     * deben truncarse. Solo se corta si el «#» va precedido de un espacio y el
     * valor no está entrecomillado. En caso de duda, entrecomillar.
     *
     * @return array<string,string>
     */
    private static function parsear(string $contenido): array
    {
        $valores = [];
        $lineas = preg_split('/\r\n|\r|\n/', $contenido) ?: [];

        foreach ($lineas as $numero => $linea) {
            $linea = trim($linea);

            if ($linea === '' || str_starts_with($linea, '#')) {
                continue;
            }

            // Tolerar el prefijo "export " habitual en ficheros compartidos con bash
            if (str_starts_with($linea, 'export ')) {
                $linea = trim(substr($linea, 7));
            }

            $pos = strpos($linea, '=');
            if ($pos === false) {
                // Línea sin «=»: se ignora en lugar de abortar, pero se deja
                // constancia en el log de errores de PHP.
                error_log(sprintf(
                    'Config: línea %d ignorada en el .env (no contiene «=»)',
                    $numero + 1
                ));
                continue;
            }

            $clave = trim(substr($linea, 0, $pos));
            $valor = trim(substr($linea, $pos + 1));

            if ($clave === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $clave)) {
                error_log(sprintf(
                    'Config: línea %d ignorada en el .env (nombre de clave no válido)',
                    $numero + 1
                ));
                continue;
            }

            $valores[$clave] = self::normalizarValor($valor);
        }

        return $valores;
    }

    private static function normalizarValor(string $valor): string
    {
        if ($valor === '') {
            return '';
        }

        $primero = $valor[0];
        $ultimo = $valor[strlen($valor) - 1];

        // Comillas dobles: se interpretan los escapes
        if ($primero === '"' && $ultimo === '"' && strlen($valor) >= 2) {
            $interior = substr($valor, 1, -1);
            return strtr($interior, [
                '\\n'  => "\n",
                '\\r'  => "\r",
                '\\t'  => "\t",
                '\\"'  => '"',
                '\\\\' => '\\',
            ]);
        }

        // Comillas simples: literal
        if ($primero === "'" && $ultimo === "'" && strlen($valor) >= 2) {
            return substr($valor, 1, -1);
        }

        // Sin comillas: se corta un posible comentario en línea, pero solo si
        // el «#» va precedido de espacio en blanco.
        $valor = preg_replace('/\s+#.*$/', '', $valor) ?? $valor;

        return rtrim($valor);
    }

    /**
     * Devuelve el valor de una clave, o $porDefecto si no está definida.
     */
    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        $delEntorno = getenv($clave);
        if ($delEntorno !== false && $delEntorno !== '') {
            return $delEntorno;
        }

        if (self::$valores === null) {
            throw new RuntimeException(
                'Config::cargar() no se ha invocado. Debe llamarse desde bootstrap.php '
                . 'antes de cualquier acceso a la configuración.'
            );
        }

        return self::$valores[$clave] ?? $porDefecto;
    }

    /**
     * Igual que get(), pero aborta si la clave no está definida o está vacía.
     *
     * Es la forma correcta de pedir credenciales: mejor fallar en el arranque
     * con un mensaje claro que enviar una petición sin autenticar y recibir un
     * 401 opaco de la API de destino.
     *
     * El mensaje de error nunca contiene el valor, solo el nombre de la clave.
     */
    public static function requerir(string $clave): string
    {
        $valor = self::get($clave);

        if ($valor === null || $valor === '') {
            $origen = self::$rutaCargada ?? '(sin fichero cargado)';
            throw new RuntimeException(
                "Falta la clave de configuración obligatoria «{$clave}». "
                . "Revisa {$origen} o el entorno del proceso."
            );
        }

        return $valor;
    }

    public static function getInt(string $clave, ?int $porDefecto = null): ?int
    {
        $valor = self::get($clave);

        if ($valor === null || $valor === '') {
            return $porDefecto;
        }

        if (!preg_match('/^-?\d+$/', trim($valor))) {
            throw new RuntimeException(
                "La clave de configuración «{$clave}» debería ser un número entero."
            );
        }

        return (int) trim($valor);
    }

    /**
     * Se consideran verdaderos: 1, true, yes, on (sin distinguir mayúsculas).
     * Se consideran falsos: 0, false, no, off, cadena vacía.
     */
    public static function getBool(string $clave, bool $porDefecto = false): bool
    {
        $valor = self::get($clave);

        if ($valor === null || $valor === '') {
            return $porDefecto;
        }

        return match (strtolower(trim($valor))) {
            '1', 'true', 'yes', 'on'   => true,
            '0', 'false', 'no', 'off'  => false,
            default => throw new RuntimeException(
                "La clave de configuración «{$clave}» debería ser un booleano."
            ),
        };
    }

    /**
     * Devuelve una lista a partir de un valor separado por comas.
     * Se usa para ORIGENES_CORS_PERMITIDOS, que hoy está duplicado en 7 ficheros.
     *
     * @return string[]
     */
    public static function getLista(string $clave, array $porDefecto = []): array
    {
        $valor = self::get($clave);

        if ($valor === null || trim($valor) === '') {
            return $porDefecto;
        }

        $partes = array_map('trim', explode(',', $valor));

        return array_values(array_filter($partes, static fn (string $p): bool => $p !== ''));
    }

    public static function tiene(string $clave): bool
    {
        return self::get($clave) !== null;
    }

    /**
     * Comprueba de una vez que están todas las claves obligatorias.
     *
     * Pensado para invocarse al final de bootstrap.php: si falta algo, el
     * proceso falla en el arranque con la lista completa de lo que falta, en
     * lugar de ir descubriéndolo clave a clave en tiempo de ejecución.
     *
     * @param string[] $claves
     * @throws RuntimeException con el listado de todas las claves ausentes
     */
    public static function verificarObligatorias(array $claves): void
    {
        $ausentes = [];

        foreach ($claves as $clave) {
            $valor = self::get($clave);
            if ($valor === null || $valor === '') {
                $ausentes[] = $clave;
            }
        }

        if ($ausentes !== []) {
            throw new RuntimeException(
                'Faltan claves de configuración obligatorias: ' . implode(', ', $ausentes)
            );
        }
    }

    /**
     * Nombres de las claves cargadas, sin sus valores. Para diagnóstico.
     *
     * @return string[]
     */
    public static function clavesDefinidas(): array
    {
        return array_keys(self::$valores ?? []);
    }
}
