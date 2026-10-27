<?php

declare(strict_types=1);

/**
 * Webhook de Voucherify: campaign.loyalty_tier.updated (ha cambiado el nombre
 * o los límites de puntos de un tramo del programa de fidelización).
 *
 * Sustituye a v2/webhooks/modificar_tiers.php. Guarda el payload en la cola y
 * responde 200 (ver RecepcionWebhook). El trabajo lo hace después el worker,
 * en ManejadorTierActualizado: actualiza el tramo en el fichero de tiers
 * (RUTA_TIERS_JSON), el mismo que lee obtener_loyalty.php.
 *
 * Solo encola campaign.loyalty_tier.updated. Cualquier otro tipo se contesta
 * con 200 y se descarta.
 *
 * CONFIGURACIÓN EN VOUCHERIFY
 * ---------------------------
 *   https://<dominio>/webhooks/modificar_tiers.php
 *
 * evento campaign.loyalty_tier.updated, con la cabecera X-Loyalty-Key y el
 * secreto de ESTE conector (CONECTOR_SECRETO), no el del de Blueshift.
 */

require_once __DIR__ . '/../../bootstrap.php';

use SalesManago\RecepcionWebhook;

RecepcionWebhook::encolar('campaign.loyalty_tier.updated');
