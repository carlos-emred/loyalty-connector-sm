<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use JsonException;
use RuntimeException;
use SalesManago\Config;
use SalesManago\Log;

/**
 * Atiende campaign.loyalty_tier.updated: ha cambiado la definición de un tramo
 * del programa de fidelización.
 *
 * Sustituye a v2/webhooks/modificar_tiers.php. Copia adaptada de
 * Loyalty\Eventos\ManejadorTierActualizado del conector de Blueshift.
 *
 * El evento no identifica a ningún cliente: lo que cambia es el nombre o los
 * límites de puntos de un tramo, no el tier de nadie. Por eso no se toca la
 * base de datos. El tier de cada socio sigue llegando en holder_loyalty_tier
 * de los movimientos de puntos (ManejadorMovimientoPuntos).
 *
 * DÓNDE VIVE LA LISTA
 * -------------------
 * En el fichero JSON de RUTA_TIERS_JSON, el mismo que devuelve
 * obtener_loyalty.php. Contiene la lista completa:
 *
 *   [{"id":"ltr_…","name":"Plata","points":{"from":250,"to":799}}, …]
 *
 * Esos nombres de campo los consume el storefront y no se tocan. points.to
 * puede ser null: el tramo superior no tiene límite.
 *
 * Se lee la lista, se sustituye el tramo por su id o se añade al final, como
 * el original, y se reescribe entera.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - La lista se guarda en el fichero y no en el usuario ficticio
 *   tiers@tiers.com de Blueshift.
 * - Si no podía leer la lista, el original partía de una vacía y la
 *   sobrescribía con un solo tramo. Aquí, si el fichero existe pero no se
 *   entiende, no se toca.
 * - El fichero se escribe en uno temporal y se renombra: obtener_loyalty.php
 *   nunca lee uno a medio escribir.
 *
 * LEER, MODIFICAR Y ESCRIBIR: RIESGO ACEPTADO
 * -------------------------------------------
 * Si dos eventos de tramo se procesan a la vez, el último en escribir borra el
 * cambio del otro. Se acepta: es configuración que cambia pocas veces y el
 * worker procesa los eventos de uno en uno.
 *
 * CUÁNDO SE LANZA EXCEPCIÓN
 * -------------------------
 * Si falla la lectura o la escritura del fichero, sí: perder un cambio de
 * configuración es peor que reintentarlo, y reescribir dos veces la misma
 * lista es inofensivo. También si el contenido no es una lista de tramos.
 *
 * Si el tramo del payload no tiene id o nombre, o sus límites no son enteros,
 * no se lanza: reintentarlo no lo va a arreglar. Se registra y se termina.
 */
final class ManejadorTierActualizado implements ManejadorEvento
{
    /** La misma que usa obtener_loyalty.php si el .env no dice otra. */
    private const RUTA_POR_DEFECTO = '/opt/salesmanago/datos/tiers.json';

    public function tipos(): array
    {
        return ['campaign.loyalty_tier.updated'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $datos = $payload['data']['loyalty_tier'] ?? null;
        $tramo = self::tramoDelPayload($datos);

        if ($tramo === null) {
            Log::error('campaign.loyalty_tier.updated con un tramo incompleto; se ignora', [
                'tramo'  => is_array($datos) && is_scalar($datos['id'] ?? null) ? (string) $datos['id'] : null,
                'evento' => $idEvento,
            ]);

            return;
        }

        $ruta = Config::get('RUTA_TIERS_JSON', self::RUTA_POR_DEFECTO) ?? self::RUTA_POR_DEFECTO;

        $tramos = self::tramosGuardados($ruta);
        [$tramos, $existia] = self::aplicarTramo($tramos, $tramo);

        self::escribirTramos($ruta, $tramos);

        Log::info(
            $existia
                ? 'Tramo de fidelización actualizado en el fichero de tiers'
                : 'Tramo de fidelización añadido en el fichero de tiers',
            [
                'tramo'  => $tramo['id'],
                'nombre' => $tramo['name'],
                'tramos' => count($tramos),
                'evento' => $idEvento,
            ]
        );
    }

    /**
     * Construye el tramo con la misma estructura que se guarda.
     *
     * @return array{id:string, name:string, points:array{from:int, to:?int}}|null
     *         null si el tramo no se puede usar
     */
    private static function tramoDelPayload(mixed $datos): ?array
    {
        if (!is_array($datos)) {
            return null;
        }

        $id = $datos['id'] ?? null;
        $nombre = $datos['name'] ?? null;
        $puntos = $datos['points'] ?? null;
        $desde = is_array($puntos) ? ($puntos['from'] ?? null) : null;
        $hasta = is_array($puntos) ? ($puntos['to'] ?? null) : null;

        if (!is_string($id) || $id === ''
            || !is_string($nombre) || $nombre === ''
            || !is_int($desde)
            || ($hasta !== null && !is_int($hasta))) {
            return null;
        }

        return [
            'id'     => $id,
            'name'   => $nombre,
            'points' => ['from' => $desde, 'to' => $hasta],
        ];
    }

    /**
     * Lee la lista de tramos del fichero JSON.
     *
     * Si el fichero no existe o está vacío, se parte de una lista vacía: es el
     * estado inicial antes de que llegue ningún evento de tramo.
     *
     * @return list<array<string,mixed>>
     *
     * @throws RuntimeException si el fichero existe pero no se puede leer o no
     *                          contiene una lista de tramos válida
     */
    private static function tramosGuardados(string $ruta): array
    {
        if (!is_file($ruta)) {
            return [];
        }

        $contenido = @file_get_contents($ruta);

        if ($contenido === false) {
            throw new RuntimeException("No se puede leer el fichero de tiers: {$ruta}");
        }

        // Un editor de Windows puede guardar el JSON con BOM, y json_decode no
        // lo admite. Igual que en obtener_loyalty.php.
        if (str_starts_with($contenido, "\xEF\xBB\xBF")) {
            $contenido = substr($contenido, 3);
        }

        $contenido = trim($contenido);

        if ($contenido === '') {
            return [];
        }

        $tramos = json_decode($contenido, true);

        $esLista = is_array($tramos)
            && array_is_list($tramos)
            && array_filter($tramos, static fn (mixed $t): bool => !is_array($t)) === [];

        if (!$esLista) {
            throw new RuntimeException(
                'El fichero de tiers no contiene una lista de tramos válida; no se sobrescribe: ' . $ruta
            );
        }

        return $tramos;
    }

    /**
     * Escribe la lista en un temporal del mismo directorio y lo renombra
     * sobre el fichero. rename() es atómico dentro de un mismo sistema de
     * ficheros: quien lea ve la lista anterior o la nueva, nunca media.
     *
     * @param list<array<string,mixed>> $tramos
     *
     * @throws RuntimeException si no se puede escribir
     */
    private static function escribirTramos(string $ruta, array $tramos): void
    {
        $directorio = dirname($ruta);

        if (!is_dir($directorio)) {
            throw new RuntimeException("El directorio del fichero de tiers no existe: {$directorio}");
        }

        try {
            $json = json_encode($tramos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new RuntimeException('No se pudo generar el JSON de tiers: ' . $e->getMessage(), 0, $e);
        }

        $temporal = @tempnam($directorio, '.tiers-');

        if ($temporal === false || realpath(dirname($temporal)) !== realpath($directorio)) {
            // tempnam() cae al directorio temporal del sistema si no puede
            // escribir en el pedido; desde allí rename() ya no sería atómico.
            if (is_string($temporal)) {
                @unlink($temporal);
            }

            throw new RuntimeException("No se puede escribir en el directorio del fichero de tiers: {$directorio}");
        }

        // tempnam() crea el fichero con permisos 0600. Se deja legible como
        // un fichero normal de configuración.
        if (@file_put_contents($temporal, $json) === false || !@chmod($temporal, 0644) || !@rename($temporal, $ruta)) {
            @unlink($temporal);

            throw new RuntimeException("No se puede escribir en el fichero de tiers: {$ruta}");
        }
    }

    /**
     * Sustituye el tramo por su id, o lo añade al final si no estaba.
     *
     * @param list<array<string,mixed>>                                      $tramos
     * @param array{id:string, name:string, points:array{from:int, to:?int}} $tramo
     *
     * @return array{0:list<array<string,mixed>>, 1:bool} La lista y si el tramo ya existía
     */
    private static function aplicarTramo(array $tramos, array $tramo): array
    {
        $existia = false;

        foreach ($tramos as $i => $guardado) {
            if (($guardado['id'] ?? null) === $tramo['id']) {
                $tramos[$i]['name'] = $tramo['name'];
                $tramos[$i]['points'] = $tramo['points'];
                $existia = true;
            }
        }

        if (!$existia) {
            $tramos[] = $tramo;
        }

        return [$tramos, $existia];
    }
}
