<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: voucher.published (cupón asignado a un cliente).
 *
 * Sustituye a v2/webhooks/añadir_cupon.php. Mismo esquema que
 * webhooks/voucherify.php del conector de Blueshift: guarda el payload en la
 * cola (sm_eventos_pendientes) y responde 200. El trabajo lo hace después el
 * worker, en ManejadorCuponAsignado:
 *
 *   1. Registra el cupón en sm_cupones.
 *   2. Lanza un evento externo en el contacto de SalesManago, que dispara el
 *      correo al cliente.
 *
 * Por qué cola y no síncrono como el original: el original llamaba a
 * SalesManago sin timeout mientras Voucherify esperaba. Una respuesta lenta
 * hacía que Voucherify diera el webhook por fallido y lo reenviase, y cada
 * reenvío era un correo más al cliente.
 *
 * Solo encola voucher.published. Cualquier otro tipo se contesta con 200 y se
 * descarta (ver RecepcionWebhook).
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/anadir_cupon.php
 *
 * evento voucher.published, con la cabecera X-Loyalty-Key y el secreto de ESTE
 * conector (CONECTOR_SECRETO), no el del de Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\RecepcionWebhook;

RecepcionWebhook::encolar('voucher.published');
