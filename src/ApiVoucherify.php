<?php

declare(strict_types=1);

namespace SalesManago;

use RuntimeException;

/**
 * Cliente de la API de Voucherify.
 *
 * Cubre el alta de clientes (POST /v1/customers, registro_club.php), las
 * recompensas de un miembro (GET /v1/loyalties/members/{id}/rewards,
 * obtener_loyalty.php), el registro de compras (POST /v1/orders y
 * POST /v1/redemptions, ManejadorCompra) y el canje de puntos por una
 * recompensa (POST /v1/loyalties/{campaña}/members/{miembro}/redemption,
 * adquirir_recompensa.php). Mismas credenciales que el conector
 * de Blueshift (X-App-Id y X-App-Token en cabecera), pero leídas del .env de
 * este proyecto.
 *
 * IDEMPOTENCIA
 * ------------
 * POST /v1/customers es un upsert por source_id: si ya existe un cliente con
 * ese source_id, Voucherify lo actualiza y devuelve el mismo id. Repetir la
 * llamada no crea duplicados, así que aquí sí se reintentan las respuestas
 * ambiguas.
 *
 * La respuesta trae el id del cliente (cust_...) tanto si lo crea como si lo
 * actualiza, así que el llamante lo guarda directamente sin depender del
 * webhook customer.created, que además solo se emite la primera vez.
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
            cabeceras: ['Accept: application/json', ...self::credenciales()],
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
     * Recompensas disponibles para un miembro del programa de fidelización,
     * tal como las devuelve Voucherify.
     *
     * @return array<string,mixed>|null null si Voucherify no conoce al miembro (404)
     *
     * @throws RuntimeException si Voucherify falla por otro motivo
     */
    public static function recompensasMiembro(string $memberId): ?array
    {
        $respuesta = Http::getJson(
            url: self::base() . '/v1/loyalties/members/' . rawurlencode($memberId) . '/rewards',
            cabeceras: self::credenciales(),
        );

        if ($respuesta->codigo === 404) {
            return null;
        }

        if (!$respuesta->ok() || $respuesta->json === null) {
            throw new RuntimeException(
                'Voucherify no devolvió las recompensas: ' . $respuesta->resumen() . self::motivo($respuesta)
            );
        }

        return $respuesta->json;
    }

    /**
     * Registra un pedido pagado sin cupón.
     *
     * Idempotente si el pedido lleva source_id: Voucherify actualiza el pedido
     * existente en lugar de crear otro, así que se reintentan también las
     * respuestas ambiguas.
     *
     * @param array<string,mixed> $pedido source_id, amount, items, customer, status
     * @return array{rechazado: bool, id: ?string, motivo: ?string}
     *
     * @throws RuntimeException si Voucherify no responde o falla (5xx, 429)
     */
    public static function registrarPedido(array $pedido): array
    {
        return self::resultadoCompra(
            Http::postJson(
                url: self::base() . '/v1/orders',
                payload: $pedido,
                cabeceras: ['Accept: application/json', ...self::credenciales()],
                reintentarAmbiguos: true,
            ),
            'id'
        );
    }

    /**
     * Canjea un cupón con su pedido.
     *
     * No es idempotente: un segundo canje gastaría otro uso del cupón. Por eso
     * no se reintentan las respuestas ambiguas.
     *
     * @param array<string,mixed> $canje redeemables, customer, order
     * @return array{rechazado: bool, id: ?string, motivo: ?string}
     *
     * @throws RuntimeException si Voucherify no responde o falla (5xx, 429)
     */
    public static function canjear(array $canje): array
    {
        return self::resultadoCompra(
            Http::postJson(
                url: self::base() . '/v1/redemptions',
                payload: $canje,
                cabeceras: ['Accept: application/json', ...self::credenciales()],
                reintentarAmbiguos: false,
            ),
            'redemptions.0.id'
        );
    }

    /**
     * Canjea puntos de un miembro por una recompensa.
     *
     * Devuelve la respuesta tal cual: adquirir_recompensa.php la reenvía a la
     * tienda, que lee de ahí el resultado (o el motivo del rechazo).
     *
     * Gasta puntos, así que no se reintentan las respuestas ambiguas: si la
     * petición llegó, repetirla podría canjear dos veces. Http solo reintenta
     * cuando la petición no llegó a salir (DNS, conexión) o Voucherify la
     * rechazó sin procesarla (429, 503).
     *
     * @param int|null $puntos Solo cuenta en las recompensas de «pagar con
     *                         puntos»; en las demás Voucherify usa el coste
     *                         configurado en la recompensa
     */
    public static function canjearRecompensa(string $campanaId, string $memberId, string $rewardId, ?int $puntos): Respuesta
    {
        $recompensa = ['id' => $rewardId];

        if ($puntos !== null) {
            $recompensa['points'] = $puntos;
        }

        return Http::postJson(
            url: self::base() . '/v1/loyalties/' . rawurlencode($campanaId)
                . '/members/' . rawurlencode($memberId) . '/redemption',
            payload: ['reward' => $recompensa],
            cabeceras: ['Accept: application/json', ...self::credenciales()],
            reintentarAmbiguos: false,
        );
    }

    /**
     * 2xx: registrado. 4xx (salvo 429): Voucherify lo rechaza y reintentar no
     * lo va a cambiar (cupón agotado, caducado, datos no válidos). Lo demás es
     * un fallo temporal y se lanza para que el worker reintente.
     *
     * @return array{rechazado: bool, id: ?string, motivo: ?string}
     */
    private static function resultadoCompra(Respuesta $respuesta, string $rutaId): array
    {
        if ($respuesta->ok()) {
            $id = $respuesta->valor($rutaId) ?? $respuesta->valor('id');

            return ['rechazado' => false, 'id' => is_string($id) ? $id : null, 'motivo' => null];
        }

        if ($respuesta->codigo >= 400 && $respuesta->codigo < 500 && $respuesta->codigo !== 429) {
            return [
                'rechazado' => true,
                'id'        => null,
                'motivo'    => preg_replace('/^ — /u', '', self::motivo($respuesta)) ?: $respuesta->resumen(),
            ];
        }

        throw new RuntimeException('Voucherify no registró la compra: ' . $respuesta->resumen() . self::motivo($respuesta));
    }

    /**
     * @return string[]
     */
    private static function credenciales(): array
    {
        return [
            'X-App-Id: '    . Config::requerir('VOUCHERIFY_APP_ID'),
            'X-App-Token: ' . Config::requerir('VOUCHERIFY_SECRET_KEY'),
        ];
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
