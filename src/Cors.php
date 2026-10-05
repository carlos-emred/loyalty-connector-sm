<?php

declare(strict_types=1);

namespace SalesManago;

/**
 * Cabeceras CORS y respuesta al preflight.
 *
 * Sustituye a los 7 bloques duplicados que hoy declaran cada uno su propio
 * array $allowed_origins, con listas distintas entre sí: obtener_usuario.php
 * admite cuatro orígenes y comprobar_codigo_verificacion.php solo dos, sin que
 * haya razón aparente para la diferencia. Aquí la lista es única y vive en el
 * .env, de modo que añadir el dominio definitivo de Shopify es cambiar una
 * línea de configuración y no siete ficheros.
 *
 * DETALLE QUE ROMPE EL STOREFRONT SI SE OLVIDA
 * --------------------------------------------
 * Los scripts actuales publican «Access-Control-Allow-Headers: Content-Type».
 * Al mover el secreto de la query string a la cabecera X-Loyalty-Key, el
 * navegador enviará un preflight preguntando si esa cabecera está permitida;
 * si no aparece en la lista, bloquea la petición antes de emitirla y el
 * error que se ve en consola no menciona el secreto por ningún lado. Por eso
 * Auth::CABECERA se añade aquí de forma automática y no depende de que alguien
 * se acuerde de incluirla en la configuración.
 *
 * QUÉ NO ES CORS
 * --------------
 * CORS no es un control de acceso. Es una norma que respetan los navegadores
 * para decidir si el JavaScript de una página puede leer la respuesta de otro
 * dominio. Una petición hecha con curl, Postman o cualquier cliente que no sea
 * un navegador ignora estas cabeceras por completo y recibe la respuesta igual.
 * La lista de orígenes evita que otra web incruste vuestros endpoints; no
 * impide que alguien los llame directamente.
 */
final class Cors
{
    /** @var string[] */
    private static array $origenesPermitidos = [];

    /** @var string[] */
    private static array $metodos = ['POST', 'OPTIONS'];

    /** @var string[] */
    private static array $cabeceras = ['Content-Type'];

    private static int $maxAge = 86400;

    /**
     * @param string[] $origenesPermitidos Orígenes exactos, con esquema y sin barra final
     * @param string[] $metodos
     * @param string[] $cabecerasAdicionales
     */
    public static function configurar(
        array $origenesPermitidos,
        array $metodos = ['POST', 'OPTIONS'],
        array $cabecerasAdicionales = [],
        int $maxAge = 86400,
    ): void {
        self::$origenesPermitidos = array_values(array_filter(
            array_map(static fn ($o): string => rtrim(trim((string) $o), '/'), $origenesPermitidos),
            static fn (string $o): bool => $o !== ''
        ));

        self::$metodos = $metodos;
        self::$maxAge = $maxAge;

        // Content-Type y el secreto van siempre; el resto se añade encima.
        self::$cabeceras = array_values(array_unique(array_merge(
            ['Content-Type', Auth::CABECERA],
            $cabecerasAdicionales
        )));
    }

    /**
     * Emite las cabeceras CORS y, si la petición es un preflight OPTIONS,
     * responde 204 y termina la ejecución.
     *
     * Debe invocarse antes de escribir nada en la salida.
     */
    public static function aplicar(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $origen = self::origenDeLaPeticion();

        // Vary: Origin es obligatorio cuando la respuesta depende del origen.
        // Sin él, un proxy o una CDN puede servir a un dominio la respuesta
        // cacheada de otro, con la cabecera Allow-Origin equivocada.
        header('Vary: Origin', false);

        if ($origen !== null && self::estaPermitido($origen)) {
            header('Access-Control-Allow-Origin: ' . $origen);
        } elseif ($origen !== null) {
            Log::warning('Cors: origen no permitido', ['origen' => $origen]);
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', self::$metodos));
        header('Access-Control-Allow-Headers: ' . implode(', ', self::$cabeceras));
        header('Access-Control-Max-Age: ' . self::$maxAge);

        // Deliberadamente NO se emite Access-Control-Allow-Credentials: estos
        // endpoints no usan cookies ni sesión, y activarlo impediría además
        // usar el comodín en caso de necesitarlo.

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            // 204 en lugar de 200: el preflight no lleva cuerpo.
            http_response_code(204);
            exit;
        }
    }

    /**
     * Indica si la petición viene de un origen permitido. Útil para los
     * endpoints que quieran rechazar activamente y no limitarse a que el
     * navegador bloquee la lectura de la respuesta.
     */
    public static function origenValido(): bool
    {
        $origen = self::origenDeLaPeticion();

        // Sin cabecera Origin no hay nada que validar: es una petición que no
        // procede de un navegador. Se deja pasar y que decida Auth.
        if ($origen === null) {
            return true;
        }

        return self::estaPermitido($origen);
    }

    private static function origenDeLaPeticion(): ?string
    {
        $origen = $_SERVER['HTTP_ORIGIN'] ?? null;

        if (!is_string($origen) || trim($origen) === '') {
            return null;
        }

        return rtrim(trim($origen), '/');
    }

    /**
     * Comparación exacta, sin comodines ni coincidencias por sufijo.
     *
     * Permitir sufijos («termina en .emred.com») es un error clásico: un
     * dominio como «emred.com.atacante.net» también termina así.
     */
    private static function estaPermitido(string $origen): bool
    {
        return in_array($origen, self::$origenesPermitidos, true);
    }

    /** @return string[] */
    public static function origenesPermitidos(): array
    {
        return self::$origenesPermitidos;
    }
}
