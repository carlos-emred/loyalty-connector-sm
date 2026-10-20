-- ---------------------------------------------------------------------------
-- Migración 08: tabla sm_compras
--
-- Compras de SalesManago ya enviadas a Voucherify por ejecutar_compra.php.
-- Evita registrar dos veces la misma compra. Ver el comentario de la tabla en
-- 01_esquema.sql.
--
-- 01_esquema.sql ya la incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/08_compras.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

CREATE TABLE IF NOT EXISTS sm_compras (
    id              bigserial   PRIMARY KEY,
    cliente         text        NOT NULL,
    clave           text        NOT NULL,
    contact_id      text        NOT NULL,
    fecha           timestamptz NOT NULL,
    importe         integer     NOT NULL,
    codigo_cupon    text,
    estado          text        NOT NULL,
    voucherify_id   text,
    motivo          text,
    creado_en       timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT uq_sm_compras_clave UNIQUE (cliente, clave),
    CONSTRAINT ck_sm_compras_estado CHECK (estado IN ('registrada', 'rechazada'))
);

COMMENT ON TABLE  sm_compras IS 'Compras de SalesManago enviadas a Voucherify; evita registrarlas dos veces';
COMMENT ON COLUMN sm_compras.clave IS 'Identificador de la compra en SalesManago (eventId, externalId o hash)';
COMMENT ON COLUMN sm_compras.contact_id IS 'contactId de SalesManago';
COMMENT ON COLUMN sm_compras.importe IS 'Importe de la compra en céntimos';
COMMENT ON COLUMN sm_compras.codigo_cupon IS 'Código de descuento de la compra (detail5); NULL si no llevaba';
COMMENT ON COLUMN sm_compras.voucherify_id IS 'Id del pedido (ord_...) o del canje (r_...) en Voucherify';

GRANT SELECT, INSERT, UPDATE ON sm_compras TO loyalty_app;
GRANT USAGE ON SEQUENCE sm_compras_id_seq TO loyalty_app;
