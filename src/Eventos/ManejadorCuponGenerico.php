<?php

declare(strict_types=1);

namespace SalesManago\Eventos;

use SalesManago\ApiSalesManago;
use SalesManago\Db;
use SalesManago\Log;
use SalesManago\RecepcionCuponGenerico;

/**
 * Atiende salesmanago.cupon_generico: una regla de automatización de
 * SalesManago pide asignar un cupón genérico a un contacto
 * (webhooks/cupones_genericos/*.php, RecepcionCuponGenerico).
 *
 *   1. Pide a SalesManago la ficha del contacto y toma su correo.
 *   2. Busca al socio en sm_clientes por ese correo y toma su id de
 *      Voucherify.
 *   3. Guarda el cupón en sm_cupones para ese socio, marcado como genérico.
 *      obtener_datos_usuario.php lo devuelve como cualquier otro.
 *   4. Lanza en su contacto el mismo evento externo que un cupón propio
 *      (detail1 = COMUNICAR_CUPON, RegistroCupon::notificar), que dispara el
 *      correo.
 *
 * No toca Voucherify: un cupón genérico tiene el mismo código para todos y no
 * se publica a nadie. Voucherify lo valida al aplicarlo, como siempre.
 *
 * UN SOLO CORREO POR SOCIO
 * ------------------------
 * Si el socio ya tenía el cupón, la fila no se toca, pero se avisa si aún no
 * se había avisado (notificado_en nulo). Así un reintento tras un fallo de
 * SalesManago sí manda el correo, y una segunda llamada de la regla no lo
 * repite.
 *
 * SIN EXCEPCIONES SALVO FALLO DE SALESMANAGO
 * ------------------------------------------
 * Un contacto que SalesManago no conoce, sin correo o que no está registrado
 * en el club no mejora al reintentarlo: se registra y se termina. Si
 * SalesManago falla (al leer el contacto o al lanzar el evento), se lanza
 * excepción y el worker reintenta.
 */
final class ManejadorCuponGenerico implements ManejadorEvento
{
    public function tipos(): array
    {
        return ['salesmanago.cupon_generico'];
    }

    public function manejar(array $payload, int $idEvento): void
    {
        $contactId = trim((string) ($payload['contactId'] ?? ''));
        $archivo = (string) ($payload['archivo'] ?? '');
        $cupon = RecepcionCuponGenerico::cuponValido(
            (string) json_encode($payload['cupon'] ?? null, JSON_UNESCAPED_UNICODE),
            $archivo
        );

        if ($contactId === '' || $cupon === null) {
            Log::error('salesmanago.cupon_generico sin contactId o con un cupón no válido; se ignora', [
                'evento' => $idEvento,
            ]);

            return;
        }

        $voucher = $cupon['voucher'];
        $voucherId = trim((string) $voucher['id']);
        $codigo = trim((string) $voucher['code']);
        $contexto = ['evento' => $idEvento, 'archivo' => $archivo, 'voucher' => $voucherId, 'contacto' => $contactId];

        $contacto = ApiSalesManago::contactoPorId($contactId);
        $email = mb_strtolower(trim((string) ($contacto['email'] ?? '')));

        if ($email === '') {
            Log::warning('Cupón genérico para un contacto que SalesManago no conoce o sin correo; se ignora', $contexto);

            return;
        }

        $customerId = Db::valor(
            'SELECT voucherify_id FROM sm_clientes WHERE cliente = :cli AND email = :email',
            ['cli' => Db::cliente(), 'email' => $email]
        );

        if (!is_string($customerId) || $customerId === '') {
            // Sin id de Voucherify no hay cliente al que colgar el cupón:
            // obtener_datos_usuario.php busca los cupones por ese id.
            Log::warning('Cupón genérico para un contacto que no está registrado en el club; se ignora', $contexto);

            return;
        }

        // cuponValido() ya comprobó que el tipo es conocido.
        $descuento = RegistroCupon::clasificarDescuento($voucher) ?? [];

        $yaLoTenia = Db::valor(
            'SELECT estado FROM sm_cupones WHERE cliente = :cli AND customer_id = :customer AND voucher_id = :voucher',
            ['cli' => Db::cliente(), 'customer' => $customerId, 'voucher' => $voucherId]
        );

        if ($yaLoTenia !== null) {
            Log::info('El socio ya tenía el cupón genérico; no se cambia', $contexto + ['estado' => $yaLoTenia]);
        } else {
            RegistroCupon::guardar($voucher, $cupon, $voucherId, $codigo, $customerId, $descuento, generico: true);

            Log::info('Cupón genérico asignado', $contexto + ['customer' => $customerId]);
        }

        // No repite el correo si notificado_en ya está puesto.
        RegistroCupon::notificar($voucher, $cupon, $email, $voucherId, $codigo, $customerId, $descuento);
    }
}
