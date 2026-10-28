<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: voucher.deleted (un cupón ha dejado de existir en
 * Voucherify).
 *
 * Sustituye a v2/webhooks/eliminar_cupon.php. Guarda el payload en la cola y
 * responde 200 (ver RecepcionWebhook). El trabajo lo hace después el worker,
 * en ManejadorCuponEliminado: pasa el cupón a estado 'eliminado' en
 * sm_cupones, para que obtener_datos_usuario.php deje de ofrecerlo. No avisa a
 * nadie.
 *
 * Solo encola voucher.deleted. Cualquier otro tipo se contesta con 200 y se
 * descarta.
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/eliminar_cupon.php
 *
 * evento voucher.deleted, con la cabecera X-Loyalty-Key y el secreto de ESTE
 * conector (CONECTOR_SECRETO), no el del de Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\RecepcionWebhook;

RecepcionWebhook::encolar('voucher.deleted');
