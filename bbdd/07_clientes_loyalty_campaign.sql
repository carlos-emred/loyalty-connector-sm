-- ---------------------------------------------------------------------------
-- Migración 07: campaña de la tarjeta de fidelización en sm_clientes
--
-- ManejadorCuponAsignado guarda en loyalty_campaign el campaign_id del
-- voucher cuando voucher.published trae una LOYALTY_CARD, junto al código que
-- ya guardaba en loyalty_card. obtener_datos_usuario.php lo devuelve.
--
-- Nullable: las tarjetas recibidas antes de esta migración no lo tienen.
--
-- 01_esquema.sql ya la incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/07_clientes_loyalty_campaign.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

ALTER TABLE sm_clientes
    ADD COLUMN IF NOT EXISTS loyalty_campaign text;

COMMENT ON COLUMN sm_clientes.loyalty_campaign IS 'campaign_id de la campaña de fidelización de la tarjeta; llega con ella';
