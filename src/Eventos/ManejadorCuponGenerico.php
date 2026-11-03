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
 *
 * No toca Voucherify: un cupón genérico tiene el mismo código para todos y no
 * se publica a nadie. Voucherify lo valida al aplicarlo, como siempre.
 *
 * No avisa a SalesManago: quien llama es una regla de SalesManago, que puede
 * mandar el correo ella misma.
 *
 * SIN EXCEPCIONES SALVO FALLO DE SALESMANAGO
 * ------------------------------------------
 * Un contacto que SalesManago no conoce, sin correo o que no está registrado
 * en el club no mejora al reintentarlo: se registra y se termina. Si
 * SalesManago falla, contactoPorId() lanza y el worker reintenta.
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

        $yaLoTenia = Db::valor(
            'SELECT estado FROM sm_cupones WHERE cliente = :cli AND customer_id = :customer AND voucher_id = :voucher',
            ['cli' => Db::cliente(), 'customer' => $customerId, 'voucher' => $voucherId]
        );

        if ($yaLoTenia !== null) {
            Log::info('El socio ya tenía el cupón genérico; no se cambia', $contexto + ['estado' => $yaLoTenia]);

            return;
        }

        RegistroCupon::guardar(
            $voucher,
            $cupon,
            $voucherId,
            $codigo,
            $customerId,
            // cuponValido() ya comprobó que el tipo es conocido.
            RegistroCupon::clasificarDescuento($voucher) ?? [],
            generico: true,
        );

        Log::info('Cupón genérico asignado', $contexto + ['customer' => $customerId]);
    }
}
