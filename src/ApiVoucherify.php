<?php

declare(strict_types=1);

namespace SalesManago;

use RuntimeException;

/**
 * Cliente de la API de Voucherify.
 *
 * Solo cubre el alta de clientes (POST /v1/customers), que es lo que usa
 * registro_club.php. Mismas credenciales que el conector de Blueshift
 * (X-App-Id y X-App-Token en cabecera), pero leídas del .env de este proyecto.
 *
 * IDEMPOTENCIA
 * ------------
 * POST /v1/customers es un upsert por source_id: si ya existe un cliente con
 * ese source_id, Voucherify lo actualiza y devuelve el mismo id. Repetir la
 * llamada no crea duplicados, así que aquí sí se reintentan las respuestas
 * ambiguas.
 *
 * Voucherify solo emite customer.created la primera vez; las actualizaciones
 * no pasan por completar_usuario.php. Por eso el llamante guarda el id que
 * devuelve esta llamada en lugar de esperar al webhook.
 */
final class ApiVoucherify
{
    /**
     * Crea o actualiza un cliente.
     *
     * @param array<string,mixed> $datos Cuerpo de la petición: source_id, email,
     *                                   name, phone, birthdate, metadata...
     *
     * @return string id del cliente en Voucherify (cust_...)
     *
     * @throws RuntimeException si Voucherify no confirma el alta
     */
    public static function upsertCliente(array $datos): string
    {
        $respuesta = Http::postJson(
            url: self::base() . '/v1/customers',
            payload: $datos,
            cabeceras: [
                'Accept: application/json',
                'X-App-Id: '    . Config::requerir('VOUCHERIFY_APP_ID'),
                'X-App-Token: ' . Config::requerir('VOUCHERIFY_SECRET_KEY'),
            ],
            reintentarAmbiguos: true,
        );

        $id = $respuesta->valor('id');

        if (!$respuesta->ok() || !is_string($id) || $id === '') {
            throw new RuntimeException(
                'Voucherify rechazó el alta del cliente: ' . $respuesta->resumen()
                . self::motivo($respuesta)
            );
        }

        Log::info('Voucherify: cliente actualizado', [
            'customer' => $id,
            'metadata' => implode(',', array_keys(is_array($datos['metadata'] ?? null) ? $datos['metadata'] : [])),
        ]);

        return $id;
    }

    /**
     * Motivo del rechazo, recortado. Se omite «details»: a veces repite el
     * valor rechazado, que puede ser un dato personal.
     */
    private static function motivo(Respuesta $respuesta): string
    {
        $partes = array_filter(
            [$respuesta->valor('key'), $respuesta->valor('message')],
            static fn (mixed $valor): bool => is_string($valor) && $valor !== ''
        );

        if ($partes === []) {
            return '';
        }

        return ' — ' . mb_substr(implode(': ', $partes), 0, 300);
    }

    private static function base(): string
    {
        return rtrim(Config::requerir('VOUCHERIFY_API'), '/');
    }
}
