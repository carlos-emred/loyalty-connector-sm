<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\Log;

/**
 * Enruta cada evento al manejador que le corresponde.
 *
 * El worker no contiene ningún «if» sobre el tipo de evento: pregunta aquí y
 * ejecuta. Añadir un tipo nuevo es registrar un manejador, sin tocar el bucle.
 *
 * EVENTOS SIN MANEJADOR
 * ---------------------
 * Voucherify envía muchos más tipos de los que nos interesan. Un evento sin
 * manejador NO es un error: se descarta y se registra. Reintentarlo cinco
 * veces antes de rendirse desperdiciaría ciclos del worker y llenaría la
 * tabla de fallos con ruido que no lo es.
 *
 * Por eso despachar() devuelve un resultado en lugar de lanzar excepción
 * cuando no encuentra manejador: el worker necesita distinguir «ha fallado»
 * de «esto no va conmigo».
 */
final class Despachador
{
    /** @var array<string,ManejadorEvento> Tipo de evento a manejador */
    private array $manejadores = [];

    public function registrar(ManejadorEvento $manejador): void
    {
        foreach ($manejador->tipos() as $tipo) {
            if (isset($this->manejadores[$tipo])) {
                Log::warning('Despachador: se sustituye un manejador ya registrado', [
                    'tipo' => $tipo,
                ]);
            }

            $this->manejadores[$tipo] = $manejador;
        }
    }

    public function tieneManejador(string $tipo): bool
    {
        return isset($this->manejadores[$tipo]);
    }

    /**
     * Procesa un evento de la cola.
     *
     * @param array<string,mixed> $evento Fila devuelta por Db::tomarLote()
     *
     * @throws \Throwable la que lance el manejador, para que el worker la
     *                    trate como fallo reintentable
     */
    public function despachar(array $evento): ResultadoDespacho
    {
        $tipo = (string) ($evento['tipo_evento'] ?? '');
        $id = (int) ($evento['id'] ?? 0);

        if (!$this->tieneManejador($tipo)) {
            return ResultadoDespacho::sinManejador($tipo);
        }

        $this->manejadores[$tipo]->manejar($evento['payload'] ?? [], $id);

        return ResultadoDespacho::procesado();
    }

    /**
     * Tipos registrados. Para diagnóstico y para el arranque del worker.
     *
     * @return string[]
     */
    public function tiposRegistrados(): array
    {
        return array_keys($this->manejadores);
    }
}
