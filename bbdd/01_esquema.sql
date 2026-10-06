-- ---------------------------------------------------------------------------
-- Conector SalesManago: esquema de PostgreSQL
--
-- Se aplica sobre la MISMA base que el conector de Blueshift (loyalty) y en el
-- mismo esquema. Para no chocar con sus tablas, todo lo de este proyecto lleva
-- el prefijo sm_, incluidos índices y restricciones: en PostgreSQL los nombres
-- de índice son únicos por esquema, no por tabla.
--
-- No toca ninguna tabla del conector de Blueshift.
--
-- Convenios, los mismos que allí:
--
--   * Marcas de tiempo en timestamptz. La aplicación trabaja en UTC.
--   * Payloads originales en jsonb, para poder reprocesar.
--   * NADA se borra. loyalty_app no tiene permiso de DELETE.
--
-- Ejecutar como superusuario o como propietario de la base:
--
--   sudo cat /opt/salesmanago/repo/bbdd/01_esquema.sql | sudo -u postgres psql -d loyalty
-- ---------------------------------------------------------------------------

CREATE SCHEMA IF NOT EXISTS loyalty;

SET search_path TO loyalty, public;


-- ---------------------------------------------------------------------------
-- sm_clientes
--
-- Usuarios registrados en el club a través de registro_club.php.
--
-- La clave es el correo y no el id de Voucherify, al revés que en clientes:
-- la fila se crea antes de llamar a Voucherify, y el correo es también lo
-- que identifica el contacto en SalesManago. El id de Voucherify se rellena
-- en el mismo registro, con la respuesta del alta.
--
-- El correo se guarda siempre en minúsculas, desde el código, para que la
-- restricción de unicidad no admita el mismo usuario dos veces.
--
-- Guarda datos personales: acceso restringido y sin volcarla a logs.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sm_clientes (
    id                      bigserial   PRIMARY KEY,
    cliente                 text        NOT NULL,
    email                   text        NOT NULL,
    dni                     text,
    nombre                  text,
    apellido                text,
    telefono                text,
    genero                  text,
    fecha_nacimiento        date,
    password_hash           text,
    voucherify_id           text,
    salesmanago_contact_id  text,
    registrado_en           timestamptz,
    sincronizado_en         timestamptz,
    creado_en               timestamptz NOT NULL DEFAULT now(),
    actualizado_en          timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT uq_sm_clientes_email UNIQUE (cliente, email)
);

COMMENT ON TABLE  sm_clientes IS 'Socios del club registrados en SalesManago';
COMMENT ON COLUMN sm_clientes.cliente IS 'Cliente de EMRED al que pertenece la fila';
COMMENT ON COLUMN sm_clientes.email IS 'Siempre en minúsculas; identifica el contacto en SalesManago';
COMMENT ON COLUMN sm_clientes.password_hash IS 'Hash de password_hash() de PHP; nunca la contraseña en claro';
COMMENT ON COLUMN sm_clientes.voucherify_id IS 'Identificador de Voucherify (cust_...); lo devuelve el alta en registro_club.php';
COMMENT ON COLUMN sm_clientes.salesmanago_contact_id IS 'contactId devuelto por SalesManago en el último upsert';
COMMENT ON COLUMN sm_clientes.registrado_en IS 'Último paso por registro_club.php; NULL si solo llegó el alta de Voucherify';
COMMENT ON COLUMN sm_clientes.sincronizado_en IS 'Último upsert confirmado por SalesManago; NULL si nunca se confirmó';

CREATE INDEX IF NOT EXISTS ix_sm_clientes_dni
    ON sm_clientes (cliente, dni);

CREATE INDEX IF NOT EXISTS ix_sm_clientes_voucherify
    ON sm_clientes (voucherify_id);


-- ---------------------------------------------------------------------------
-- sm_eventos_pendientes
--
-- La cola de webhooks de Voucherify, igual que eventos_pendientes del
-- conector de Blueshift pero separada: los dos reciben el mismo
-- customer.created, con el mismo event.id, y cada uno tiene que procesarlo.
-- Con una sola tabla, el segundo en llegar chocaría con la restricción de
-- unicidad y se daría por duplicado.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sm_eventos_pendientes (
    id                  bigserial   PRIMARY KEY,
    cliente             text        NOT NULL,
    id_externo          text        NOT NULL,
    tipo_evento         text        NOT NULL,
    payload             jsonb       NOT NULL,
    estado              text        NOT NULL DEFAULT 'pendiente',
    intentos            smallint    NOT NULL DEFAULT 0,
    ultimo_error        text,
    id_correlacion      text,
    recibido_en         timestamptz NOT NULL DEFAULT now(),
    proximo_intento_en  timestamptz NOT NULL DEFAULT now(),
    procesado_en        timestamptz,

    CONSTRAINT uq_sm_eventos_id_externo UNIQUE (cliente, id_externo),
    CONSTRAINT ck_sm_eventos_estado CHECK (
        estado IN ('pendiente', 'procesando', 'procesado', 'fallido', 'descartado')
    )
);

COMMENT ON TABLE  sm_eventos_pendientes IS 'Cola de webhooks de Voucherify para el conector de SalesManago';
COMMENT ON COLUMN sm_eventos_pendientes.id_externo IS 'event.id del webhook; clave de idempotencia';
COMMENT ON COLUMN sm_eventos_pendientes.estado IS 'pendiente, procesando, procesado, fallido, descartado';

CREATE INDEX IF NOT EXISTS ix_sm_eventos_por_procesar
    ON sm_eventos_pendientes (cliente, proximo_intento_en)
    WHERE estado = 'pendiente';

CREATE INDEX IF NOT EXISTS ix_sm_eventos_fallidos
    ON sm_eventos_pendientes (cliente, recibido_en DESC)
    WHERE estado = 'fallido';


-- ---------------------------------------------------------------------------
-- Permisos
--
-- Mismo usuario que el conector de Blueshift. Se conceden explícitamente
-- porque los ALTER DEFAULT PRIVILEGES de 01_esquema.sql de aquel proyecto solo
-- cubren las tablas que cree el mismo rol que los ejecutó.
-- ---------------------------------------------------------------------------

GRANT USAGE ON SCHEMA loyalty TO loyalty_app;
GRANT SELECT, INSERT, UPDATE ON sm_clientes, sm_eventos_pendientes TO loyalty_app;
GRANT USAGE ON SEQUENCE sm_clientes_id_seq, sm_eventos_pendientes_id_seq TO loyalty_app;
