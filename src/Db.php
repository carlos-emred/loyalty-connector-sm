<?php

declare(strict_types=1);

namespace SalesManago;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Acceso a PostgreSQL.
 *
 * Copia de Loyalty\Db del conector de Blueshift, con la cola apuntando a
 * sm_eventos_pendientes.
 *
 * BASE COMPARTIDA
 * ---------------
 * Los dos conectores viven en la misma instancia y usan la misma base y el
 * mismo esquema (loyalty). Para no pisarse, todas las tablas de este proyecto
 * llevan el prefijo sm_. Las del conector de Blueshift no se leen ni se
 * escriben desde aquí.
 *
 * DOS RESPONSABILIDADES
 * ---------------------
 * 1. Conexión y ejecución de consultas con sentencias preparadas.
 * 2. Las operaciones de la cola, que son las únicas con lógica propia y las
 *    que conviene tener en un solo sitio.
 *
 * CONEXIÓN PEREZOSA
 * -----------------
 * No se conecta al cargar la clase, sino la primera vez que se necesita.
 *
 * SOBRE LAS CONEXIONES PERSISTENTES
 * ---------------------------------
 * No se usa PDO::ATTR_PERSISTENT. Ahorraría el coste de conexión bajo
 * PHP-FPM, pero si una petición termina con una transacción abierta, la
 * siguiente que reutilice esa conexión la hereda. A este volumen el ahorro no
 * compensa el riesgo.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Identificador del cliente de EMRED al que pertenecen las filas. */
    private static ?string $cliente = null;

    public static function conexion(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::requerir('PG_HOST'),
            Config::get('PG_PORT', '5432'),
            Config::requerir('PG_DB'),
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                Config::requerir('PG_USER'),
                Config::requerir('PG_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                    // Sentencias preparadas reales, no emuladas por el driver.
                    // Es lo que garantiza que los valores nunca se interpolen
                    // en el texto de la consulta.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // El mensaje de PDO puede incluir la cadena de conexión. Se
            // registra solo el código de error, nunca el detalle completo.
            Log::error('Db: no se pudo conectar a PostgreSQL', [
                'sqlstate' => $e->getCode(),
                'host'     => Config::get('PG_HOST'),
            ]);

            throw new RuntimeException('No se pudo conectar a la base de datos.', 0, $e);
        }

        self::$pdo->exec('SET search_path TO loyalty, public');

        return self::$pdo;
    }

    public static function cliente(): string
    {
        self::$cliente ??= Config::requerir('CLIENTE_ID');

        return self::$cliente;
    }

    /**
     * Comprueba que la base responde. Para el endpoint de salud.
     */
    public static function disponible(): bool
    {
        try {
            self::conexion()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Consultas genéricas
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $parametros
     * @return array<int,array<string,mixed>>
     */
    public static function filas(string $sql, array $parametros = []): array
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->fetchAll();
    }

    /**
     * @param array<string,mixed> $parametros
     * @return array<string,mixed>|null
     */
    public static function fila(string $sql, array $parametros = []): ?array
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        $fila = $sentencia->fetch();

        return $fila === false ? null : $fila;
    }

    /**
     * @param array<string,mixed> $parametros
     */
    public static function valor(string $sql, array $parametros = []): mixed
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        $valor = $sentencia->fetchColumn();

        return $valor === false ? null : $valor;
    }

    /**
     * Devuelve el número de filas afectadas.
     *
     * @param array<string,mixed> $parametros
     */
    public static function ejecutar(string $sql, array $parametros = []): int
    {
        $sentencia = self::conexion()->prepare($sql);
        $sentencia->execute($parametros);

        return $sentencia->rowCount();
    }

    /**
     * Ejecuta la función dentro de una transacción. Si lanza excepción, se
     * deshace todo y la excepción se propaga.
     */
    public static function enTransaccion(callable $operacion): mixed
    {
        $pdo = self::conexion();
        $pdo->beginTransaction();

        try {
            $resultado = $operacion($pdo);
            $pdo->commit();

            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Cola de eventos
    // -------------------------------------------------------------------------

    /**
     * Encola un webhook recibido.
     *
     * Es lo único que hace el endpoint antes de responder 200: guardar el
     * payload en bruto y devolver el control. El procesamiento corre después,
     * a cargo del worker.
     *
     * ON CONFLICT DO NOTHING sobre (cliente, id_externo) es la idempotencia:
     * si Voucherify reenvía el mismo evento, la fila no se duplica y el
     * método devuelve false sin que nadie tenga que comprobar nada antes.
     *
     * @param array<string,mixed> $payload Cuerpo completo del webhook
     * @return bool true si se encoló, false si ya estaba
     */
    public static function encolar(string $tipoEvento, string $idExterno, array $payload): bool
    {
        if (trim($idExterno) === '') {
            throw new RuntimeException(
                'Db::encolar necesita un identificador externo para garantizar la idempotencia.'
            );
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new RuntimeException('El payload del webhook no se pudo serializar a JSON.');
        }

        $id = self::valor(
            'INSERT INTO sm_eventos_pendientes
                    (cliente, id_externo, tipo_evento, payload, id_correlacion)
             VALUES (:cliente, :id_externo, :tipo, :payload, :correlacion)
             ON CONFLICT (cliente, id_externo) DO NOTHING
             RETURNING id',
            [
                'cliente'     => self::cliente(),
                'id_externo'  => $idExterno,
                'tipo'        => $tipoEvento,
                'payload'     => $json,
                'correlacion' => Log::correlacion(),
            ]
        );

        return $id !== null;
    }

    /**
     * Reserva un lote de eventos pendientes y los devuelve.
     *
     * SKIP LOCKED hace que varios workers en paralelo nunca tomen la misma
     * fila: cada uno se salta las que otro tiene bloqueadas. Es el mismo
     * principio de los pg_try_advisory_lock ya usados en los pipelines de
     * Rivera, aplicado a nivel de fila.
     *
     * El UPDATE ... RETURNING reserva y lee en una sola operación, de modo que
     * no hay hueco entre «lo he visto» y «lo he marcado como mío».
     *
     * @return array<int,array<string,mixed>>
     */
    public static function tomarLote(int $limite = 50): array
    {
        $filas = self::filas(
            "UPDATE sm_eventos_pendientes
                SET estado = 'procesando',
                    intentos = intentos + 1
              WHERE id IN (
                    SELECT id
                      FROM sm_eventos_pendientes
                     WHERE estado = 'pendiente'
                       AND cliente = :cliente
                       AND proximo_intento_en <= now()
                     ORDER BY proximo_intento_en
                       FOR UPDATE SKIP LOCKED
                     LIMIT :limite
                    )
          RETURNING id, tipo_evento, payload, intentos, id_correlacion",
            [
                'cliente' => self::cliente(),
                'limite'  => $limite,
            ]
        );

        // PostgreSQL devuelve jsonb como texto; se decodifica aquí para que el
        // worker reciba el payload ya listo.
        foreach ($filas as &$fila) {
            $fila['payload'] = json_decode((string) $fila['payload'], true) ?? [];
        }

        return $filas;
    }

    /**
     * @param int[] $ids
     */
    public static function marcarProcesados(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        // Se construye la lista de marcadores en lugar de usar ANY(), porque
        // PDO no vincula arrays de forma nativa.
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        self::ejecutar(
            "UPDATE sm_eventos_pendientes
                SET estado = 'procesado',
                    procesado_en = now(),
                    ultimo_error = NULL
              WHERE id IN ({$marcadores})",
            array_values($ids)
        );
    }

    /**
     * Registra un fallo y programa el siguiente intento.
     *
     * La espera crece: 1, 2, 4, 8 y 16 minutos. Al quinto intento la fila
     * pasa a 'fallido' y deja de reintentarse, para no consumir el ciclo del
     * worker indefinidamente con un evento que no va a salir adelante.
     */
    public static function marcarFallido(int $id, string $error, int $maxIntentos = 5): void
    {
        self::ejecutar(
            "UPDATE sm_eventos_pendientes
                SET estado = CASE WHEN intentos >= :max THEN 'fallido' ELSE 'pendiente' END,
                    ultimo_error = :error,
                    proximo_intento_en = now() + (interval '1 minute' * power(2, intentos))
              WHERE id = :id",
            [
                'id'    => $id,
                'error' => mb_substr($error, 0, 2000),
                'max'   => $maxIntentos,
            ]
        );
    }

    /**
     * Devuelve a la cola los eventos que quedaron en 'procesando'.
     *
     * Ocurre cuando el worker muere a mitad de un lote: las filas quedan
     * reservadas por un proceso que ya no existe. El propio worker lo llama al
     * arrancar.
     *
     * @return int Eventos recuperados
     */
    public static function recuperarAtascados(int $minutos = 10): int
    {
        return self::ejecutar(
            "UPDATE sm_eventos_pendientes
                SET estado = 'pendiente',
                    proximo_intento_en = now()
              WHERE cliente = :cliente
                AND estado = 'procesando'
                AND recibido_en < now() - (interval '1 minute' * :minutos)",
            [
                'cliente' => self::cliente(),
                'minutos' => $minutos,
            ]
        );
    }

    /**
     * Devuelve a la cola eventos reservados que no llegaron a procesarse.
     *
     * Lo usa el worker al recibir SIGTERM: los eventos del lote que quedaron
     * sin atender vuelven a 'pendiente' de inmediato, en lugar de esperar los
     * diez minutos que tarda recuperarAtascados() en rescatarlos.
     *
     * Se descuenta el intento, porque no llegó a haberlo.
     *
     * @param int[] $ids
     */
    public static function devolverACola(array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        return self::ejecutar(
            "UPDATE sm_eventos_pendientes
                SET estado = 'pendiente',
                    intentos = greatest(intentos - 1, 0),
                    proximo_intento_en = now()
              WHERE estado = 'procesando'
                AND id IN ({$marcadores})",
            array_values($ids)
        );
    }

    /**
     * Recuento de eventos por estado. Para diagnóstico y para el endpoint de
     * salud.
     *
     * @return array<string,int>
     */
    public static function estadoDeLaCola(): array
    {
        $filas = self::filas(
            'SELECT estado, count(*) AS total
               FROM sm_eventos_pendientes
              WHERE cliente = :cliente
              GROUP BY estado',
            ['cliente' => self::cliente()]
        );

        $resumen = [];

        foreach ($filas as $fila) {
            $resumen[(string) $fila['estado']] = (int) $fila['total'];
        }

        return $resumen;
    }

    /**
     * Cierra la conexión. El worker lo usa si necesita forzar una reconexión;
     * bajo PHP-FPM no hace falta, PHP la libera al terminar la petición.
     */
    public static function cerrar(): void
    {
        self::$pdo = null;
    }
}
