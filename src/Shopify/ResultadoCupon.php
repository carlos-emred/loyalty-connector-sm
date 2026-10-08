<?php

declare(strict_types=1);

namespace SalesManago\Shopify;

/**
 * Resultado de crear un cupón en Shopify.
 *
 * ApiShopify no lanza excepción cuando Shopify rechaza la creación por un
 * motivo previsible: devuelve este objeto con el estado, para que
 * crear_cupon.php responda el código HTTP que corresponde a cada caso.
 */
final class ResultadoCupon
{
    public const CREADO = 'creado';

    /** Ya había un código igual en la tienda. No se crea nada. */
    public const YA_EXISTE = 'ya_existe';

    /** No hay cliente en Shopify con ese correo. No se crea nada. */
    public const CLIENTE_NO_ENCONTRADO = 'cliente_no_encontrado';

    /** Shopify falló o rechazó la regla. Si quedó algo a medias, se revirtió. */
    public const FALLIDO = 'fallido';

    private function __construct(
        public readonly string $estado,
        public readonly string $codigo,
        public readonly ?string $idRegla = null,
        public readonly ?string $idCodigo = null,
        /** Motivo del fallo, apto para el log. */
        public readonly ?string $motivo = null,
    ) {
    }

    public static function creado(string $codigo, string $idRegla, string $idCodigo): self
    {
        return new self(self::CREADO, $codigo, $idRegla, $idCodigo);
    }

    public static function yaExiste(string $codigo): self
    {
        return new self(self::YA_EXISTE, $codigo);
    }

    public static function clienteNoEncontrado(string $codigo): self
    {
        return new self(self::CLIENTE_NO_ENCONTRADO, $codigo);
    }

    public static function fallido(string $codigo, string $motivo): self
    {
        return new self(self::FALLIDO, $codigo, motivo: $motivo);
    }
}
