<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: voucher.loyalty_card.transaction.created (movimiento
 * de puntos en la tarjeta de fidelización de un cliente).
 *
 * Sustituye a v2/webhooks/anadir_puntos.php. Guarda el payload en la cola y
 * responde 200 (ver RecepcionWebhook). El trabajo lo hace después el worker,
 * en ManejadorMovimientoPuntos:
 *
 *   1. Registra el movimiento en sm_movimientos_puntos.
 *   2. Actualiza saldo, total y tier del socio en sm_clientes.
 *   3. Actualiza esas propiedades en su contacto de SalesManago.
 *
 * Solo encola voucher.loyalty_card.transaction.created. Cualquier otro tipo se
 * contesta con 200 y se descarta.
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/anadir_puntos.php
 *
 * evento voucher.loyalty_card.transaction.created, con la cabecera
 * X-Loyalty-Key y el secreto de ESTE conector (CONECTOR_SECRETO), no el del de
 * Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\RecepcionWebhook;

RecepcionWebhook::encolar('voucher.loyalty_card.transaction.created');
