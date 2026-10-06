<?php

declare(strict_types=1);

/**
 * Worker: drena la cola de eventos.
 *
 * Proceso de larga duración bajo systemd. No atiende peticiones HTTP: lee de
 * loyalty.sm_eventos_pendientes, despacha cada evento a su manejador y marca
 * el resultado.
 *
 * Copia del worker del conector de Blueshift. Es un proceso aparte
 * (salesmanago-worker) que solo drena la cola de este proyecto; el de
 * Blueshift sigue drenando la suya.
 *
 * PARADA ORDENADA
 * ---------------
 * Al recibir SIGTERM —lo que envía systemd al reiniciar— el worker termina el
 * lote en curso y sale. Sin eso, un reinicio a mitad de lote dejaría eventos
 * en estado 'procesando' reservados por un proceso muerto.
 *
 * REINICIO PERIÓDICO
 * ------------------
 * PHP no se diseñó para procesos de días. Tras un número de ciclos o al
 * superar un límite de memoria, el worker sale con código 0 y systemd lo
 * levanta de nuevo. Es más simple y fiable que perseguir fugas de memoria.
 *
 * Uso:
 *   php /opt/salesmanago/repo/worker/run.php
 *   php /opt/salesmanago/repo/worker/run.php --una-vez    (un ciclo y sale)
 */

require_once __DIR__ . '/../bootstrap.php';

use SalesManago\Config;
use SalesManago\Db;
use SalesManago\Eventos\Despachador;
use SalesManago\Log;

Log::fijarCanal('worker');

// -----------------------------------------------------------------------------
// Parámetros
// -----------------------------------------------------------------------------

$lote          = Config::getInt('WORKER_LOTE', 25) ?? 25;
$pausaVacia    = Config::getInt('WORKER_PAUSA_VACIA', 5) ?? 5;
$pausaEventoMs = Config::getInt('WORKER_PAUSA_EVENTO_MS', 0) ?? 0;
$maxCiclos     = Config::getInt('WORKER_MAX_CICLOS', 2000) ?? 2000;
$maxMemoriaMb  = Config::getInt('WORKER_MAX_MEMORIA_MB', 128) ?? 128;

$unaVez = in_array('--una-vez', $argv, true);

// -----------------------------------------------------------------------------
// Manejadores
// -----------------------------------------------------------------------------
// Ahora mismo ninguno: el único que había (customer.created, de
// completar_usuario.php) se retiró porque registro_club.php ya recibe el id de
// Voucherify en la respuesta del alta. Sin manejadores, cualquier evento que
// llegue a la cola se descarta. Añadir un tipo es registrar aquí su manejador:
// el bucle de abajo no sabe nada de tipos de evento.

$despachador = new Despachador();

// -----------------------------------------------------------------------------
// Señales
// -----------------------------------------------------------------------------

$seguir = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    $parar = static function (int $senal) use (&$seguir): void {
        Log::info('Worker: señal de parada recibida, se termina el lote en curso', [
            'senal' => $senal,
        ]);
        $seguir = false;
    };

    pcntl_signal(SIGTERM, $parar);
    pcntl_signal(SIGINT, $parar);
} else {
    // Sin la extensión pcntl no hay parada ordenada: systemd matará el proceso
    // a mitad de lote y los eventos quedarán en 'procesando' hasta que
    // recuperarAtascados() los rescate. Funciona, pero conviene instalarla.
    Log::warning('Worker: la extensión pcntl no está disponible; sin parada ordenada');
}

// -----------------------------------------------------------------------------
// Arranque
// -----------------------------------------------------------------------------

Log::info('Worker: iniciando', [
    'cliente'   => Db::cliente(),
    'lote'      => $lote,
    'tipos'     => implode(',', $despachador->tiposRegistrados()),
    'pid'       => getmypid(),
]);

// Rescata lo que un worker anterior dejó reservado al morir.
$recuperados = Db::recuperarAtascados(10);

if ($recuperados > 0) {
    Log::warning('Worker: eventos recuperados de un proceso anterior', [
        'eventos' => $recuperados,
    ]);
}

// -----------------------------------------------------------------------------
// Bucle principal
// -----------------------------------------------------------------------------

$ciclos = 0;
$procesadosTotal = 0;

while ($seguir) {
    $ciclos++;

    try {
        $eventos = Db::tomarLote($lote);
    } catch (Throwable $e) {
        // La base puede estar reiniciándose. Se espera y se reintenta en lugar
        // de morir, para no entrar en un ciclo de reinicios con systemd.
        Log::error('Worker: fallo al leer la cola', ['mensaje' => $e->getMessage()]);
        Db::cerrar();
        sleep(min($pausaVacia * 4, 60));
        continue;
    }

    if ($eventos === []) {
        if ($unaVez) {
            break;
        }

        sleep($pausaVacia);
        continue;
    }

    $inicioLote = microtime(true);
    // Identificadores del lote, para devolver a la cola los que no lleguen a
    // procesarse si entra una señal de parada a mitad.
    $pendientesDelLote = array_map(static fn (array $e): int => (int) $e['id'], $eventos);
    $correctos = [];
    $descartados = 0;
    $fallidos = 0;

    foreach ($eventos as $evento) {
        $id = (int) $evento['id'];

        // Encadena el log del worker con el del endpoint que encoló el evento.
        Log::fijarCorrelacion($evento['id_correlacion'] ?? null);

        try {
            $resultado = $despachador->despachar($evento);

            if ($resultado->procesado) {
                $correctos[] = $id;
            } else {
                Db::ejecutar(
                    "UPDATE sm_eventos_pendientes
                        SET estado = 'descartado', procesado_en = now()
                      WHERE id = :id",
                    ['id' => $id]
                );

                $descartados++;

                Log::info('Worker: evento descartado, no hay manejador', [
                    'evento' => $id,
                    'tipo'   => $resultado->tipoSinManejador,
                ]);
            }
        } catch (Throwable $e) {
            $fallidos++;

            Log::error('Worker: fallo al procesar el evento', [
                'evento'  => $id,
                'tipo'    => $evento['tipo_evento'] ?? '?',
                'intento' => $evento['intentos'] ?? '?',
                'mensaje' => $e->getMessage(),
            ]);

            try {
                Db::marcarFallido($id, $e->getMessage());
            } catch (Throwable $e2) {
                Log::error('Worker: además falló al registrar el error', [
                    'evento'  => $id,
                    'mensaje' => $e2->getMessage(),
                ]);
            }
        }

        if ($pausaEventoMs > 0) {
            usleep($pausaEventoMs * 1000);
        }

        $pendientesDelLote = array_values(array_diff($pendientesDelLote, [$id]));

        // Una señal a mitad de lote corta aquí, no en medio de un evento.
        if (!$seguir) {
            break;
        }
    }

    if ($correctos !== []) {
        try {
            Db::marcarProcesados($correctos);
            $procesadosTotal += count($correctos);
        } catch (Throwable $e) {
            // Los eventos quedan en 'procesando' y los rescatará
            // recuperarAtascados() en el siguiente arranque. Se reprocesarán,
            // pero las restricciones de unicidad impiden que eso duplique nada.
            Log::error('Worker: fallo al marcar como procesados', [
                'eventos' => count($correctos),
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

        // Si una señal cortó el lote, lo no atendido vuelve a la cola ya mismo.
    if (!$seguir && $pendientesDelLote !== []) {
        $devueltos = Db::devolverACola($pendientesDelLote);
        Log::info('Worker: eventos devueltos a la cola por parada', ['eventos' => $devueltos]);
    }

    Log::fijarCorrelacion(null);

    Log::info('Worker: lote completado', [
        'procesados'  => count($correctos),
        'descartados' => $descartados,
        'fallidos'    => $fallidos,
        'ms'          => (int) ((microtime(true) - $inicioLote) * 1000),
    ]);

    if ($unaVez) {
        break;
    }

    // Reinicio preventivo: más simple que perseguir fugas de memoria.
    $memoriaMb = memory_get_usage(true) / 1048576;

    if ($ciclos >= $maxCiclos || $memoriaMb >= $maxMemoriaMb) {
        Log::info('Worker: reinicio preventivo', [
            'ciclos'     => $ciclos,
            'memoria_mb' => round($memoriaMb, 1),
        ]);
        break;
    }
}

Log::info('Worker: finalizado', [
    'ciclos'     => $ciclos,
    'procesados' => $procesadosTotal,
]);

exit(0);
