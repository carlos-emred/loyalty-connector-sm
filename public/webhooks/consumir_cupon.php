<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: redemption.succeeded (un cliente ha canjeado un
 * cupón).
 *
 * Sustituye a v2/webhooks/consumir_cupon.php. Guarda el payload en la cola y
 * responde 200 (ver RecepcionWebhook). El trabajo lo hace después el worker,
 * en ManejadorCuponConsumido: actualiza los usos del cupón en sm_cupones.
 *
 * Solo encola redemption.succeeded. Cualquier otro tipo se contesta con 200 y
 * se descarta.
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/consumir_cupon.php
 *
 * evento redemption.succeeded, con la cabecera X-Loyalty-Key y el secreto de
 * ESTE conector (CONECTOR_SECRETO), no el del de Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\RecepcionWebhook;

RecepcionWebhook::encolar('redemption.succeeded');
