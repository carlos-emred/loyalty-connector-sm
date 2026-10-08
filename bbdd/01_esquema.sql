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
    loyalty_card            text,
    tier                    text,
    saldo_puntos            integer,
    total_puntos            integer,
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
COMMENT ON COLUMN sm_clientes.loyalty_card IS 'Código de la tarjeta de fidelización de Voucherify; llega con voucher.published (LOYALTY_CARD)';
COMMENT ON COLUMN sm_clientes.tier IS 'Nivel del socio en el programa de fidelización';
COMMENT ON COLUMN sm_clientes.saldo_puntos IS 'Puntos disponibles para canjear; NULL si aún no se conoce';
COMMENT ON COLUMN sm_clientes.total_puntos IS 'Puntos acumulados desde el alta; NULL si aún no se conoce';
COMMENT ON COLUMN sm_clientes.registrado_en IS 'Último paso por registro_club.php; NULL si la fila la creó un webhook de Voucherify';
COMMENT ON COLUMN sm_clientes.sincronizado_en IS 'Último upsert confirmado por SalesManago; NULL si nunca se confirmó';

CREATE INDEX IF NOT EXISTS ix_sm_clientes_dni
    ON sm_clientes (cliente, dni);

CREATE INDEX IF NOT EXISTS ix_sm_clientes_voucherify
    ON sm_clientes (voucherify_id);


-- ---------------------------------------------------------------------------
-- sm_eventos_pendientes
--
-- La cola de webhooks de Voucherify, igual que eventos_pendientes del
-- conector de Blueshift pero separada: los dos reciben los mismos eventos
-- (voucher.published, por ejemplo), con el mismo event.id, y cada uno tiene
-- que procesarlo.
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
-- sm_cupones
--
-- Cupones asignados a cada cliente, uno por voucher.published. Sustituye al
-- fichero cupones/{customer_id}.json de v2/webhooks/añadir_cupon.php.
--
-- customer_id es el id de Voucherify (cust_...), el mismo que guarda
-- sm_clientes.voucherify_id. Sin clave ajena: el cupón puede llegar para un
-- cliente que no pasó por registro_club.php.
--
-- notificado_en evita mandar dos veces el correo: se escribe justo después de
-- lanzar el evento externo en SalesManago y se consulta antes.
-- ---------------------------------------------------------------------------

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


-- ---------------------------------------------------------------------------
-- sm_movimientos_puntos
--
-- Historial de movimientos de puntos de cada cliente. Sustituye a los ficheros
-- puntos/{customer_id}.json de los scripts originales. Lo lee
-- obtener_datos_usuario.php.
--
-- customer_id es el id de Voucherify (cust_...), el mismo que guarda
-- sm_clientes.voucherify_id.
--
-- transaction_id es único: si el mismo movimiento llega dos veces, el INSERT
-- choca y no se duplica.
-- ---------------------------------------------------------------------------

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


-- ---------------------------------------------------------------------------
-- Permisos
--
-- Mismo usuario que el conector de Blueshift. Se conceden explícitamente
-- porque los ALTER DEFAULT PRIVILEGES de 01_esquema.sql de aquel proyecto solo
-- cubren las tablas que cree el mismo rol que los ejecutó.
-- ---------------------------------------------------------------------------

GRANT USAGE ON SCHEMA loyalty TO loyalty_app;
GRANT SELECT, INSERT, UPDATE ON sm_clientes, sm_eventos_pendientes, sm_cupones, sm_movimientos_puntos TO loyalty_app;
GRANT USAGE ON SEQUENCE sm_clientes_id_seq, sm_eventos_pendientes_id_seq, sm_movimientos_puntos_id_seq TO loyalty_app;
