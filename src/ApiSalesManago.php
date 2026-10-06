<?php

declare(strict_types=1);

namespace SalesManago;

use RuntimeException;

/**
 * Cliente de la API de SalesManago.
 *
 * Cubre contact/upsert (registro_club.php) y v2/contact/addContactExtEvent
 * (ManejadorCuponAsignado). Sustituye a las copias de curl sin timeout de los
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
        $payload = self::credenciales() + $datos;

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
     * Registra un evento externo en un contacto ya existente. Es lo que
     * dispara los correos automáticos configurados en SalesManago.
     *
     * Sin reintentos de respuestas ambiguas: si la petición llegó, el correo
     * puede haber salido, y repetirla lo enviaría dos veces.
     *
     * @param string               $email  Correo del contacto
     * @param array<string,mixed>  $evento contactEvent: date, contactExtEventType,
     *                                     value, description, detail1..detail20...
     *
     * @return string|null eventId devuelto por SalesManago, si lo devuelve
     *
     * @throws RuntimeException si SalesManago no confirma el evento
     */
    public static function anadirEventoExterno(string $email, array $evento): ?string
    {
        $payload = self::credenciales() + [
            'email'        => $email,
            'contactEvent' => $evento,
        ];

        $respuesta = Http::postJson(
            url: self::base() . '/api/v2/contact/addContactExtEvent',
            payload: $payload,
            cabeceras: ['Accept: application/json'],
            reintentarAmbiguos: false,
        );

        if (!$respuesta->ok() || $respuesta->valor('success') !== true) {
            throw new RuntimeException(
                'SalesManago rechazó el evento externo: ' . $respuesta->resumen()
                . self::motivo($respuesta->valor('message'))
            );
        }

        $eventId = $respuesta->valor('eventId');

        Log::info('SalesManago: evento externo registrado', [
            'evento'  => is_string($eventId) ? $eventId : null,
            'tipo'    => $evento['contactExtEventType'] ?? null,
            'detail1' => $evento['detail1'] ?? null,
        ]);

        return is_string($eventId) && $eventId !== '' ? $eventId : null;
    }

    /**
     * Autenticación de SalesManago: va en el cuerpo, no en cabecera.
     *
     * @return array<string,mixed>
     */
    private static function credenciales(): array
    {
        return [
            'clientId'    => Config::requerir('SALESMANAGO_CLIENT_ID'),
            'apiKey'      => Config::requerir('SALESMANAGO_API_KEY'),
            'requestTime' => (int) (microtime(true) * 1000),
            'sha'         => Config::requerir('SALESMANAGO_SHA'),
            'owner'       => Config::requerir('SALESMANAGO_OWNER'),
        ];
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
