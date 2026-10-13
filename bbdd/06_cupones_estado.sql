-- ---------------------------------------------------------------------------
-- Migración 06: estado de los cupones y retirada de metadata
--
-- 1. Añade sm_cupones.estado: disponible, canjeado o caducado. Un cupón solo
--    se ofrece si está disponible. Se rellena para las filas existentes con
--    las mismas reglas que aplica el código:
--      canjeado  si redeemed_quantity llegó a quantity,
--      caducado  si pasó su fecha_caducidad,
--      disponible en otro caso.
--
-- 2. Elimina la columna metadata. Sus datos útiles ya están en columnas
--    propias (descripcion, image_url, product_id, category_id, category_name,
--    solo_uno, minimo_compra). ATENCIÓN: esto borra el JSON original de
--    Voucherify de cada cupón y no se puede deshacer. Si se quiere conservar,
--    hacer antes la copia que se indica abajo.
--
-- 01_esquema.sql ya refleja el resultado para instalaciones nuevas. Esta
-- migración se puede ejecutar más de una vez sin efecto.
--
-- Copia previa opcional de metadata:
--
--   sudo -u postgres psql -d loyalty -c "\copy (SELECT voucher_id, metadata FROM loyalty.sm_cupones) TO '/tmp/sm_cupones_metadata.csv' CSV HEADER"
--
-- Ejecución:
--
--   sudo cat /opt/salesmanago/repo/bbdd/06_cupones_estado.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

BEGIN;

ALTER TABLE sm_cupones
    ADD COLUMN IF NOT EXISTS estado text NOT NULL DEFAULT 'disponible';

ALTER TABLE sm_cupones DROP CONSTRAINT IF EXISTS ck_sm_cupones_estado;
ALTER TABLE sm_cupones ADD CONSTRAINT ck_sm_cupones_estado
    CHECK (estado IN ('disponible', 'canjeado', 'caducado'));

-- Estado de las filas que ya existían. Solo se tocan las disponibles: si se
-- vuelve a ejecutar, no cambia nada.
UPDATE sm_cupones
   SET estado = 'canjeado', actualizado_en = now()
 WHERE estado = 'disponible'
   AND quantity IS NOT NULL
   AND redeemed_quantity >= quantity;

UPDATE sm_cupones
   SET estado = 'caducado', actualizado_en = now()
 WHERE estado = 'disponible'
   AND fecha_caducidad IS NOT NULL
   AND fecha_caducidad <= now();

ALTER TABLE sm_cupones DROP COLUMN IF EXISTS metadata;

COMMENT ON COLUMN sm_cupones.quantity IS 'Veces que se puede consumir. NULL en Voucherify significa ilimitado';
COMMENT ON COLUMN sm_cupones.redeemed_quantity IS 'Veces que se ha consumido. Igual a quantity: no se puede consumir más';
COMMENT ON COLUMN sm_cupones.estado IS 'disponible, canjeado o caducado; el cupón solo se ofrece si está disponible';

COMMIT;
