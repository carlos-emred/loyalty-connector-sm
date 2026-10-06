-- ---------------------------------------------------------------------------
-- Migración 03: tabla sm_cupones
--
-- Para el webhook anadir_cupon.php (voucher.published). 01_esquema.sql ya la
-- incluye para instalaciones nuevas; esta migración es para la base que ya
-- está desplegada y se puede ejecutar más de una vez sin efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/03_cupones.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

CREATE TABLE IF NOT EXISTS sm_cupones (
    voucher_id              text        PRIMARY KEY,
    cliente                 text        NOT NULL,
    customer_id             text        NOT NULL,
    codigo                  text        NOT NULL,
    campaign_id             text,
    campaign_name           text,

    tipo                    text        NOT NULL,
    valor                   integer,
    producto                text,
    importe_maximo          integer,

    quantity                integer,
    redeemed_quantity       integer     NOT NULL DEFAULT 0,

    descripcion             text,
    descripcion_corta       text,
    image_url               text,
    minimo_compra           integer,
    solo_uno                text,
    product_id              text,
    category_id             text,
    category_name           text,

    fecha_inicio            timestamptz,
    fecha_caducidad         timestamptz,
    creado_voucherify_en    timestamptz,

    metadata                jsonb,
    notificado_en           timestamptz,
    creado_en               timestamptz NOT NULL DEFAULT now(),
    actualizado_en          timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT uq_sm_cupones_codigo UNIQUE (cliente, codigo),
    CONSTRAINT ck_sm_cupones_tipo CHECK (tipo IN ('AMOUNT', 'PERCENT', 'SHIPPING', 'UNIT'))
);

COMMENT ON TABLE  sm_cupones IS 'Cupones asignados a cada cliente; sustituye a cupones/*.json';
COMMENT ON COLUMN sm_cupones.voucher_id IS 'Identificador de Voucherify (v_...); clave principal';
COMMENT ON COLUMN sm_cupones.customer_id IS 'Identificador del cliente en Voucherify (cust_...)';
COMMENT ON COLUMN sm_cupones.valor IS 'Céntimos si AMOUNT, porcentaje si PERCENT, 100 si SHIPPING, NULL si UNIT';
COMMENT ON COLUMN sm_cupones.producto IS 'Nombre del producto regalado si UNIT';
COMMENT ON COLUMN sm_cupones.importe_maximo IS 'discount.amount_limit de Voucherify, en céntimos';
COMMENT ON COLUMN sm_cupones.quantity IS 'Usos permitidos. NULL en Voucherify significa ilimitado';
COMMENT ON COLUMN sm_cupones.descripcion_corta IS 'Descripción de la campaña (data.campaign.description)';
COMMENT ON COLUMN sm_cupones.notificado_en IS 'Momento en que se lanzó el evento externo en SalesManago; NULL si aún no';

CREATE INDEX IF NOT EXISTS ix_sm_cupones_customer
    ON sm_cupones (cliente, customer_id);

GRANT SELECT, INSERT, UPDATE ON sm_cupones TO loyalty_app;
