-- ---------------------------------------------------------------------------
-- Migración 10: cupones genéricos en sm_cupones
--
-- Un cupón genérico (webhooks/cupones_genericos/*.php, ManejadorCuponGenerico)
-- tiene el mismo voucher_id y el mismo código para todos los clientes. Hasta
-- ahora la clave era voucher_id y el código era único, así que solo cabía una
-- fila por cupón. Pasa a ser una fila por cliente y cupón:
--
--   * clave principal (cliente, customer_id, voucher_id), antes voucher_id.
--   * uq_sm_cupones_codigo pasa a (cliente, customer_id, codigo).
--   * generico marca las filas de cupones genéricos.
--
-- Los cupones propios no cambian: tienen un solo titular, así que siguen
-- siendo una fila por cupón.
--
-- 01_esquema.sql ya lo incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/10_cupones_genericos.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

BEGIN;

ALTER TABLE sm_cupones ADD COLUMN IF NOT EXISTS generico boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN sm_cupones.generico IS 'Cupón genérico (mismo código para todos) asignado desde SalesManago; una fila por cliente';

ALTER TABLE sm_cupones DROP CONSTRAINT IF EXISTS sm_cupones_pkey;
ALTER TABLE sm_cupones ADD CONSTRAINT sm_cupones_pkey PRIMARY KEY (cliente, customer_id, voucher_id);

ALTER TABLE sm_cupones DROP CONSTRAINT IF EXISTS uq_sm_cupones_codigo;
ALTER TABLE sm_cupones ADD CONSTRAINT uq_sm_cupones_codigo UNIQUE (cliente, customer_id, codigo);

-- voucher.deleted y redemption.succeeded buscan por voucher_id, que ya no
-- encabeza ningún índice.
CREATE INDEX IF NOT EXISTS ix_sm_cupones_voucher
    ON sm_cupones (voucher_id);

COMMENT ON COLUMN sm_cupones.voucher_id IS 'Identificador de Voucherify (v_...); compartido por todos los clientes en los genéricos';

COMMIT;
