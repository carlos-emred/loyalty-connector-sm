<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

/**
 * Contrato de un manejador de eventos.
 *
 * Cada tipo de webhook de Voucherify tendrá su manejador: uno para
 * voucher.published, otro para redemption.succeeded, otro para los
 * movimientos de puntos. El worker no sabe qué hace cada uno; solo los
 * invoca según el tipo de evento.
 *
 * SOBRE LAS EXCEPCIONES
 * ---------------------
 * Un manejador que termina sin lanzar nada se considera correcto y el evento
 * pasa a 'procesado'. Si lanza una excepción, el evento vuelve a la cola con
 * espera creciente, hasta cinco intentos.
 *
 * De ahí se sigue una regla importante: lanza excepción solo si reintentar
 * tiene sentido. Un fallo de red contra Blueshift, sí. Un payload malformado
 * que nunca va a mejorar, no: en ese caso registra el problema y termina sin
 * lanzar, para que el evento no consuma cinco ciclos del worker antes de
 * darse por vencido.
 */
interface ManejadorEvento
{
    /**
     * Tipos de evento que atiende este manejador, tal como llegan en el campo
     * type del webhook: 'voucher.published', 'redemption.succeeded', etc.
     *
     * @return string[]
     */
    public function tipos(): array;

    /**
     * Procesa un evento.
     *
     * @param array<string,mixed> $payload  Cuerpo completo del webhook
     * @param int                 $idEvento Identificador de la fila en la cola
     *
     * @throws \Throwable si el fallo es transitorio y merece reintento
     */
    public function manejar(array $payload, int $idEvento): void;
}
