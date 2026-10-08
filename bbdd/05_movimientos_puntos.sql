-- ---------------------------------------------------------------------------
-- Migración 05: tabla sm_movimientos_puntos
--
-- Historial de movimientos de puntos, que lee obtener_datos_usuario.php.
-- Todavía no la rellena ningún flujo.
--
-- 01_esquema.sql ya la incluye para instalaciones nuevas. Esta migración es
-- para la base que ya está desplegada y se puede ejecutar más de una vez sin
-- efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/05_movimientos_puntos.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

CREATE TABLE IF NOT EXISTS sm_movimientos_puntos (
    id              bigserial   PRIMARY KEY,
    cliente         text        NOT NULL,
    transaction_id  text        NOT NULL,
    customer_id     text        NOT NULL,
    voucher_id      text,

    tipo            text,
    puntos          integer     NOT NULL,
    saldo           integer     NOT NULL,
    total           integer     NOT NULL,
    tier            text,

    reward_id       text,
    reward_name     text,

    fecha           timestamptz NOT NULL,
    payload         jsonb,
    creado_en       timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT uq_sm_movimientos_transaccion UNIQUE (cliente, transaction_id)
);

COMMENT ON TABLE  sm_movimientos_puntos IS 'Historial de movimientos de puntos; sustituye a puntos/*.json';
COMMENT ON COLUMN sm_movimientos_puntos.transaction_id IS 'Identificador de Voucherify (vtx_...); clave de idempotencia';
COMMENT ON COLUMN sm_movimientos_puntos.customer_id IS 'Identificador del cliente en Voucherify (cust_...)';
COMMENT ON COLUMN sm_movimientos_puntos.puntos IS 'Variación del movimiento, negativa si es un gasto';
COMMENT ON COLUMN sm_movimientos_puntos.saldo IS 'Saldo resultante tras el movimiento';
COMMENT ON COLUMN sm_movimientos_puntos.total IS 'Acumulado histórico tras el movimiento; determina el tier';
COMMENT ON COLUMN sm_movimientos_puntos.tipo IS 'POINTS_ACCRUAL, POINTS_REDEMPTION, etc.';

-- Consulta de obtener_datos_usuario.php: el historial de un cliente por fecha.
CREATE INDEX IF NOT EXISTS ix_sm_movimientos_cliente_fecha
    ON sm_movimientos_puntos (cliente, customer_id, fecha DESC);

GRANT SELECT, INSERT, UPDATE ON sm_movimientos_puntos TO loyalty_app;
GRANT USAGE ON SEQUENCE sm_movimientos_puntos_id_seq TO loyalty_app;
