<?php

declare(strict_types=1);

/**
 * Cupón genérico VERANO26: lo asigna al contacto una regla de automatización
 * de SalesManago (acción «webhook»).
 *
 * UN FICHERO POR CUPÓN
 * --------------------
 * Este fichero es una plantilla: para otro cupón se copia con otro nombre en
 * esta misma carpeta y se sustituye SOLO el JSON entre las líneas
 * «<<<'CONFIGURACION'» y «CONFIGURACION;». El resto no se toca.
 *
 * - Nombre del fichero: letras, números, «_» o «-», terminado en .php. Es lo
 *   que aparece en la URL y en el log.
 * - La configuración es el JSON del cupón tal como lo da Voucherify:
 *   {"voucher": {...}, "campaign": {...}}. Se usan voucher.id, voucher.code,
 *   voucher.discount, voucher.metadata, voucher.redemption, las fechas y
 *   campaign.name/description. Los campos de metadata que se leen están en
 *   CLAUDE.md («Metadata de los cupones»); el resto se ignora.
 * - Va como texto (nowdoc) y no como array de PHP a propósito: lo que suba el
 *   cliente nunca se ejecuta como código. Lo único que no puede contener es
 *   una línea que sea exactamente «CONFIGURACION;».
 *
 * Si la configuración no es válida, la llamada responde 500 y el motivo queda
 * en el log, sin encolar nada.
 *
 * CONFIGURACIÓN EN SALESMANAGO
 * ----------------------------
 *   https://<dominio>/webhooks/cupones_genericos/verano26.php
 *
 * Sin credenciales: es público (ver RecepcionCuponGenerico). De lo que envía
 * SalesManago solo se usa contactId.
 */

require_once __DIR__ . '/../../../bootstrap.php';

use SalesManago\RecepcionCuponGenerico;

RecepcionCuponGenerico::atender(<<<'CONFIGURACION'
{
  "voucher": {
    "id": "v_131511471490031a86QuJceX",
    "code": "VERANO26",
    "gift": null,
    "type": "DISCOUNT_VOUCHER",
    "active": true,
    "assets": {
      "qr": {
        "id": "5937603f-e1e9-46dc-adae-a1bfc4f7efd2",
        "url": "https://dl.voucherify.io/api/v1/assets/qr/5937603f-e1e9-46dc-adae-a1bfc4f7efd2"
      },
      "barcode": {
        "id": "c1254825-fee4-4660-9179-d3bf644dbb2e",
        "url": "https://dl.voucherify.io/api/v1/assets/barcode/c1254825-fee4-4660-9179-d3bf644dbb2e"
      }
    },
    "object": "voucher",
    "publish": {
      "url": "/v1/vouchers/VERANO26/publications?page=1&limit=10",
      "count": 0,
      "object": "list"
    },
    "campaign": "Verano 2026",
    "category": null,
    "discount": {
      "type": "PERCENT",
      "effect": "APPLY_TO_ORDER",
      "percent_off": 20
    },
    "metadata": {
      "image_url": null,
      "description": "Celebra el verano con un 20% de descuento en todas tus compras. Utiliza el código SUMMER20 al finalizar tu pedido. Promoción válida del 1 de julio al 31 de agosto de 2026 para pedidos superiores a 30€",
      "minimum_purchase_quantity": "30"
    },
    "categories": [],
    "created_at": "2026-09-23T08:15:29.875Z",
    "redemption": {
      "url": "/v1/vouchers/VERANO26/redemptions?page=1&limit=10",
      "object": "list",
      "quantity": null,
      "redeemed_quantity": 0
    },
    "start_date": "2026-06-01T00:00:00.000Z",
    "updated_at": null,
    "campaign_id": "camp_aOjfQ8A9vR7SzTJfwoQXuPRU",
    "category_id": null,
    "loyalty_card": null,
    "validity_hours": null,
    "additional_info": null,
    "expiration_date": "2026-10-31T23:59:00.000Z",
    "is_referral_code": false,
    "validity_timeframe": null,
    "validity_day_of_week": null
  },
  "campaign": {
    "id": "camp_aOjfQ8A9vR7SzTJfwoQXuPRU",
    "name": "Verano 2026",
    "type": "STANDALONE",
    "active": true,
    "object": "campaign",
    "voucher": {
      "gift": null,
      "type": "DISCOUNT_VOUCHER",
      "discount": {
        "type": "PERCENT",
        "units": null,
        "effect": "APPLY_TO_ORDER",
        "percent_off": 20
      },
      "redemption": {
        "quantity": null,
        "redeemed_quantity": 0
      },
      "code_config": {
        "length": 8,
        "prefix": "",
        "charset": "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ",
        "pattern": "########",
        "postfix": "",
        "min_desired": 1
      },
      "loyalty_card": null
    },
    "category": null,
    "metadata": {
      "image_url": null,
      "description": "Celebra el verano con un 20% de descuento en todas tus compras. Utiliza el código SUMMER20 al finalizar tu pedido. Promoción válida del 1 de julio al 31 de agosto de 2026 para pedidos superiores a 30€",
      "minimum_purchase_quantity": "30"
    },
    "auto_join": false,
    "join_once": false,
    "created_at": "2026-06-24T10:23:58.984Z",
    "start_date": "2026-06-01T00:00:00.000Z",
    "updated_at": "2026-09-23T08:15:04.552Z",
    "category_id": null,
    "description": "20% de descuento en tus compras con el código SUMMER20. Válido del 1 de julio al 31 de agosto de 2026",
    "campaign_type": "DISCOUNT_COUPONS",
    "expiration_date": "2026-10-31T23:59:00.000Z",
    "is_referral_code": false
  },
  "event_source": {
    "user": {
      "id": "user_GuT2Tlg2Iyb2D7Ams5a046Sm8J5Xjo6T"
    },
    "channel": "USER_PORTAL"
  }
}
CONFIGURACION);
