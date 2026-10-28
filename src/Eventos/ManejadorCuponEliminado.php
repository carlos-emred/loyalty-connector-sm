<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\Db;
use SalesManago\Log;

/**
 * Atiende voucher.deleted: un cupón ha dejado de existir en Voucherify.
 *
 * Sustituye a v2/webhooks/eliminar_cupon.php. Copia adaptada de
 * Loyalty\Eventos\ManejadorCuponEliminado del conector de Blueshift. Hace una
 * sola cosa: quitar el cupón de los que se ofrecen al cliente. No avisa a
 * nadie.
 *
 * NO SE BORRA: PASA A ELIMINADO
 * -----------------------------
 * Nada se borra de la base (loyalty_app no tiene permiso de DELETE). El cupón
 * pasa a estado 'eliminado' y obtener_datos_usuario.php, que solo devuelve los
 * 'disponible', deja de ofrecerlo. Como con los demás estados, no vuelve a
 * 'disponible'.
 *
 * 'canjeado' se conserva: es el único estado que dice que el cupón llegó a
 * usarse, y borrarlo en Voucherify no deshace el canje. 'disponible' y
 * 'caducado' pasan a 'eliminado'.
 *
 * DIFERENCIAS CON EL SCRIPT ORIGINAL
 * ----------------------------------
 * El original no hacía nada con el evento: escribía el cuerpo en un .txt y
 * respondía 200 (el aviso a Blueshift estaba comentado). El cupón se seguía
 * ofreciendo.
 *
 * SIN EXCEPCIONES
 * ---------------
 * Un payload sin cupón, una tarjeta de fidelización o un cupón que no está en
 * sm_cupones no mejoran al reintentarlos: se registra y se termina.
 *
 * Solo voucher.deleted: voucher.disabled es reversible (Voucherify envía
 * voucher.enabled después) y 'eliminado' no tiene vuelta.
 */
final class ManejadorCuponEliminado implements ManejadorEvento
{
    public function tipos(): array
    {
        return ['voucher.deleted'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $voucher = is_array($payload['data']['voucher'] ?? null) ? $payload['data']['voucher'] : [];
        $voucherId = trim((string) ($voucher['id'] ?? ''));

        if ($voucherId === '') {
            Log::error('voucher.deleted sin identificador de cupón; se ignora', ['evento' => $idEvento]);

            return;
        }

        // Las tarjetas de fidelización no están en sm_cupones, sino en
        // sm_clientes.loyalty_card. No se limpia: si al socio se le emite otra,
        // su voucher.published la sobrescribe.
        if (($voucher['type'] ?? null) === 'LOYALTY_CARD') {
            Log::info('voucher.deleted de una tarjeta de fidelización; no hay cupón que marcar', [
                'voucher' => $voucherId,
                'evento'  => $idEvento,
            ]);

            return;
        }

        // Una sola sentencia con RETURNING: entre leer el estado y escribirlo
        // no cabe nada.
        $estado = Db::valor(
            "UPDATE sm_cupones
                SET estado = CASE WHEN estado = 'canjeado' THEN estado ELSE 'eliminado' END,
                    actualizado_en = now()
              WHERE voucher_id = :id
          RETURNING estado",
            ['id' => $voucherId]
        );

        $contexto = ['voucher' => $voucherId, 'evento' => $idEvento];

        if ($estado === null) {
            // Caso normal, no un fallo: un cupón anterior al conector o cuyo
            // voucher.published no llegó.
            Log::info('voucher.deleted de un cupón que no está en sm_cupones; no hay nada que marcar', $contexto);

            return;
        }

        if ($estado === 'canjeado') {
            Log::info('El cupón eliminado ya estaba canjeado; se conserva el estado', $contexto);

            return;
        }

        Log::info('Cupón marcado como eliminado', $contexto);
    }
}
