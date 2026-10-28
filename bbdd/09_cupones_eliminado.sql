-- ---------------------------------------------------------------------------
-- Migración 09: estado 'eliminado' en sm_cupones
--
-- Lo usa webhooks/eliminar_cupon.php (ManejadorCuponEliminado) cuando un cupón
-- se borra en Voucherify. La fila no se borra: cambia de estado y deja de
-- ofrecerse.
--
-- 01_esquema.sql ya lo incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/09_cupones_eliminado.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

BEGIN;

ALTER TABLE sm_cupones DROP CONSTRAINT IF EXISTS ck_sm_cupones_estado;
ALTER TABLE sm_cupones ADD CONSTRAINT ck_sm_cupones_estado
    CHECK (estado IN ('disponible', 'canjeado', 'caducado', 'eliminado'));

COMMENT ON COLUMN sm_cupones.estado IS 'disponible, canjeado, caducado o eliminado; el cupón solo se ofrece si está disponible';

COMMIT;
