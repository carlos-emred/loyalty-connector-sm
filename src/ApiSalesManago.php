<?php

declare(strict_types=1);

namespace SalesManago;

use RuntimeException;

/**
 * Cliente de la API de SalesManago.
 *
 * Solo cubre contact/upsert, que es lo que usan registro_club.php y el alta de
 * cliente de Voucherify. Sustituye a las dos copias de curl sin timeout de los
 * scripts originales y saca las credenciales del código al .env.
 *
 * AUTENTICACIÓN
 * -------------
 * SalesManago no usa cabecera: las credenciales viajan en el propio cuerpo
 * (clientId, apiKey, sha, owner). Por eso el cuerpo completo nunca se escribe
 * en el log, a diferencia de los scripts originales, que lo volcaban entero en
 * un .txt del docroot.
 *
 * ÉXITO Y FALLO
 * -------------
 * SalesManago responde 200 también cuando rechaza la petición, con
 * «success»: false y el motivo en «message». Un 2xx no basta: se comprueba el
 * campo.
 */
final class ApiSalesManago
{
    /**
     * Crea o actualiza un contacto.
     *
     * @param array<string,mixed> $datos Cuerpo de la petición sin las credenciales:
     *                                   contact, properties, birthday, forceOptIn...
     * @param bool $reintentarAmbiguos   Solo si repetir la llamada no tiene efectos
     *                                   visibles para el contacto (ver llamantes)
     *
     * @return string|null contactId devuelto por SalesManago, si lo devuelve
     *
     * @throws RuntimeException si SalesManago no confirma el alta
     */
    public static function upsertContacto(array $datos, bool $reintentarAmbiguos = false): ?string
    {
        $payload = [
            'clientId'    => Config::requerir('SALESMANAGO_CLIENT_ID'),
            'apiKey'      => Config::requerir('SALESMANAGO_API_KEY'),
            'requestTime' => (int) (microtime(true) * 1000),
            'sha'         => Config::requerir('SALESMANAGO_SHA'),
            'owner'       => Config::requerir('SALESMANAGO_OWNER'),
        ] + $datos;

        $respuesta = Http::postJson(
            url: self::base() . '/api/contact/upsert',
            payload: $payload,
            cabeceras: ['Accept: application/json'],
            reintentarAmbiguos: $reintentarAmbiguos,
        );

        if (!$respuesta->ok() || $respuesta->valor('success') !== true) {
            throw new RuntimeException(
                'SalesManago rechazó el upsert del contacto: ' . $respuesta->resumen()
                . self::motivo($respuesta->valor('message'))
            );
        }

        $contactId = $respuesta->valor('contactId');

        Log::info('SalesManago: contacto actualizado', [
            'contacto'    => is_string($contactId) ? $contactId : null,
            'propiedades' => implode(',', array_keys(is_array($datos['properties'] ?? null) ? $datos['properties'] : [])),
        ]);

        return is_string($contactId) && $contactId !== '' ? $contactId : null;
    }

    /**
     * Motivo del rechazo, recortado. SalesManago lo envía como lista de textos.
     */
    private static function motivo(mixed $mensaje): string
    {
        if (is_array($mensaje)) {
            $mensaje = implode('; ', array_filter($mensaje, 'is_string'));
        }

        if (!is_string($mensaje) || $mensaje === '') {
            return '';
        }

        return ' — ' . mb_substr($mensaje, 0, 300);
    }

    private static function base(): string
    {
        return rtrim(Config::requerir('SALESMANAGO_API_BASE'), '/');
    }
}
