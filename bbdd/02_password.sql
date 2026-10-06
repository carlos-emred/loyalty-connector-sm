-- ---------------------------------------------------------------------------
-- Migración 02: contraseña del socio en sm_clientes
--
-- registro_club.php pide ahora contraseña. Se guarda el hash que genera
-- password_hash() de PHP (bcrypt), nunca la contraseña en claro.
--
-- Nullable a propósito: las filas anteriores a esta migración no tienen
-- contraseña.
--
-- 01_esquema.sql ya incluye la columna para instalaciones nuevas. Esta
-- migración es para la base que ya está desplegada y se puede ejecutar más de
-- una vez sin efecto.
--
--   sudo cat /opt/salesmanago/repo/bbdd/02_password.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

SET search_path TO loyalty, public;

ALTER TABLE sm_clientes
    ADD COLUMN IF NOT EXISTS password_hash text;

COMMENT ON COLUMN sm_clientes.password_hash IS 'Hash de password_hash() de PHP; nunca la contraseña en claro';
