-- ---------------------------------------------------------------------------
-- Migración 04: datos de fidelización en sm_clientes
--
--   loyalty_card   Código de la tarjeta de fidelización. Lo escribe
--                  ManejadorCuponAsignado cuando voucher.published trae una
--                  LOYALTY_CARD.
--   tier           Nivel del socio.
--   saldo_puntos   Puntos disponibles.
--   total_puntos   Puntos acumulados.
--
-- tier, saldo_puntos y total_puntos aún no los rellena ningún flujo.
--
-- Todas nullable y sin valor por defecto: NULL es «no se sabe todavía», que no
-- es lo mismo que 0 puntos.
--
-- 01_esquema.sql ya las incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/04_clientes_fidelizacion.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

ALTER TABLE sm_clientes
    ADD COLUMN IF NOT EXISTS loyalty_card text,
    ADD COLUMN IF NOT EXISTS tier         text,
    ADD COLUMN IF NOT EXISTS saldo_puntos integer,
    ADD COLUMN IF NOT EXISTS total_puntos integer;

COMMENT ON COLUMN sm_clientes.loyalty_card IS 'Código de la tarjeta de fidelización de Voucherify; llega con voucher.published (LOYALTY_CARD)';
COMMENT ON COLUMN sm_clientes.tier IS 'Nivel del socio en el programa de fidelización';
COMMENT ON COLUMN sm_clientes.saldo_puntos IS 'Puntos disponibles para canjear; NULL si aún no se conoce';
COMMENT ON COLUMN sm_clientes.total_puntos IS 'Puntos acumulados desde el alta; NULL si aún no se conoce';
COMMENT ON COLUMN sm_clientes.registrado_en IS 'Último paso por registro_club.php; NULL si la fila la creó un webhook de Voucherify';
