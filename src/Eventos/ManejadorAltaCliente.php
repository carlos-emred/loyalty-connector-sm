<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende customer.created: Voucherify ha dado de alta a un cliente.
 *
 * Sustituye a v2/webhooks/completar_usuario.php. Hace dos cosas, en este
 * orden:
 *
 *   1. Guarda el id de Voucherify en sm_clientes.
 *   2. Lo añade al contacto de SalesManago como propiedad «voucherifyId».
 *
 * La base va primero porque no depende de nadie. Si SalesManago falla, se
 * lanza excepción y el worker reintenta; el segundo intento vuelve a escribir
 * el mismo valor en la base, sin efecto.
 *
 * CÓMO SE ENCUENTRA AL CONTACTO
 * -----------------------------
 * Por el correo de data.customer.email, como el original. Si el cliente de
 * Voucherify no trae correo, se busca en sm_clientes por el DNI, que en
 * Voucherify va en source_id. Sin ninguno de los dos no hay contacto al que
 * asociar el id, y reintentarlo no lo va a arreglar: se registra y se termina
 * sin lanzar.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * - Antes se llamaba a SalesManago de forma síncrona mientras Voucherify
 *   esperaba, sin timeout. Ahora pasa por la cola, como el resto de webhooks.
 * - El original abría el log en modo «w», que lo vaciaba en cada petición, y
 *   escribía «Array» en lugar del payload. Ahora va a Log, sin credenciales.
 * - El original no guardaba nada en local.
 *
 * SIN MARCA DE NOTIFICACIÓN
 * -------------------------
 * El upsert fija un valor absoluto y estable (el id del cliente en
 * Voucherify), así que repetirlo deja el contacto igual. Por eso aquí sí se
 * reintentan las respuestas ambiguas, a diferencia de registro_club.php: este
 * upsert no lleva doble opt-in ni envía nada al contacto.
 */
final class ManejadorAltaCliente implements ManejadorEvento
{
    public function tipos(): array
    {
        return ['customer.created'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $cliente = is_array($payload['data']['customer'] ?? null) ? $payload['data']['customer'] : [];
        $voucherifyId = trim((string) ($cliente['id'] ?? ''));

        if ($voucherifyId === '') {
            Log::error('customer.created sin data.customer.id; se ignora', ['evento' => $idEvento]);

            return;
        }

        $dni = trim((string) ($cliente['source_id'] ?? ''));
        $email = mb_strtolower(trim((string) ($cliente['email'] ?? '')));

        if ($email === '' && $dni !== '') {
            $email = (string) Db::valor(
                'SELECT email FROM sm_clientes WHERE cliente = :cli AND dni = :dni
                  ORDER BY actualizado_en DESC LIMIT 1',
                ['cli' => Db::cliente(), 'dni' => $dni]
            );
        }

        if ($email === '') {
            Log::warning('Alta de cliente sin correo ni registro previo por DNI; no se avisa a SalesManago', [
                'evento'   => $idEvento,
                'customer' => $voucherifyId,
            ]);

            return;
        }

        $idFila = $this->guardar($email, $dni, $voucherifyId);

        $contactId = ApiSalesManago::upsertContacto([
            'contact'    => ['email' => $email],
            'properties' => ['voucherifyId' => $voucherifyId],
        ], reintentarAmbiguos: true);

        Db::ejecutar(
            'UPDATE sm_clientes
                SET salesmanago_contact_id = COALESCE(:contacto, salesmanago_contact_id),
                    sincronizado_en = now(),
                    actualizado_en = now()
              WHERE id = :id',
            ['contacto' => $contactId, 'id' => $idFila]
        );

        Log::info('Id de Voucherify añadido al contacto', [
            'evento'   => $idEvento,
            'customer' => $voucherifyId,
            'fila'     => $idFila,
        ]);
    }

    /**
     * Guarda el id de Voucherify en la fila del usuario, creándola si el
     * usuario no pasó por registro_club.php.
     *
     * El DNI del registro manda sobre el source_id: es el que escribió el
     * usuario y el que se envió a SalesManago.
     */
    private function guardar(string $email, string $dni, string $voucherifyId): int
    {
        return (int) Db::valor(
            'INSERT INTO sm_clientes (cliente, email, dni, voucherify_id)
             VALUES (:cli, :email, :dni, :voucherify)
             ON CONFLICT (cliente, email) DO UPDATE
                SET voucherify_id = EXCLUDED.voucherify_id,
                    dni = COALESCE(sm_clientes.dni, EXCLUDED.dni),
                    actualizado_en = now()
             RETURNING id',
            [
                'cli'        => Db::cliente(),
                'email'      => $email,
                'dni'        => $dni !== '' ? $dni : null,
                'voucherify' => $voucherifyId,
            ]
        );
    }
}
