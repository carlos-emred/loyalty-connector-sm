<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

/**
 * Resultado de intentar despachar un evento.
 *
 * Distingue dos desenlaces que el worker trata de forma distinta:
 *
 *   procesado     -> el evento se atendió; pasa a 'procesado'
 *   sin manejador -> ningún manejador cubre ese tipo; pasa a 'descartado'
 *
 * El fallo no aparece aquí: se expresa lanzando una excepción desde el
 * manejador, porque es lo que activa el reintento con espera creciente.
 */
final class ResultadoDespacho
{
    private function __construct(
        public readonly bool $procesado,
        public readonly ?string $tipoSinManejador = null,
    ) {
    }

    public static function procesado(): self
    {
        return new self(procesado: true);
    }

    public static function sinManejador(string $tipo): self
    {
        return new self(procesado: false, tipoSinManejador: $tipo);
    }
}
