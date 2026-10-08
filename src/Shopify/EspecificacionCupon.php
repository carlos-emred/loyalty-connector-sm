<?php

declare(strict_types=1);

namespace SalesManago\Shopify;

use InvalidArgumentException;

/**
 * Descripción de un cupón que se va a crear en Shopify.
 *
 * Copia adaptada de Loyalty\Ecommerce\EspecificacionCupon del conector de
 * Blueshift, con las reglas de v2/crear_cupon.php que aquel no tenía: producto
 * regalo, importe máximo y artículo más barato del carrito.
 *
 * Todos los datos llegan en la petición (crear_cupon.php): no se consulta a
 * Voucherify. Por eso la validación es estricta y ocurre aquí, una sola vez,
 * antes de tocar la tienda.
 *
 * SIN VALIDEZ EN SHOPIFY
 * ----------------------
 * No hay fechas de inicio ni de caducidad ni número de usos. Si un cupón se
 * puede usar lo decide Voucherify, al que se consulta siempre justo antes de
 * aplicarlo. El cupón de Shopify solo describe el descuento: tener la validez
 * en los dos sitios solo añade ocasiones de que discrepen.
 *
 * IMPORTES
 * --------
 * Todos los importes se guardan en céntimos, para no arrastrar errores de
 * coma flotante. La conversión a euros la hace ApiShopify.
 *
 *   valor          céntimos si AMOUNT, porcentaje entero si PERCENT, sin uso
 *                  si SHIPPING o si hay producto regalo.
 *   importeMaximo  céntimos (discount.amount_limit de Voucherify).
 *   minimoCompra   céntimos.
 */
final class EspecificacionCupon
{
    public const TIPO_IMPORTE = 'AMOUNT';
    public const TIPO_PORCENTAJE = 'PERCENT';
    public const TIPO_ENVIO_GRATIS = 'SHIPPING';

    /** Longitud máxima que admite Shopify para un código de descuento. */
    private const CODIGO_MAX = 255;

    public function __construct(
        /** Código que el cliente teclea en el carrito. */
        public readonly string $codigo,

        public readonly string $tipo,

        public readonly int $valor,

        /** Correo del cliente al que se restringe el cupón. Obligatorio. */
        public readonly string $emailCliente,

        /**
         * only_one del original: un solo uso, una vez por cliente y, si no
         * hay producto ni colección, solo sobre el artículo más barato.
         */
        public readonly bool $soloUno = false,

        /**
         * Tope del descuento en céntimos. Ver ApiShopify::reglaDePrecio():
         * Shopify no admite topes, y el original convertía el cupón en uno de
         * importe fijo por ese valor.
         */
        public readonly ?int $importeMaximo = null,

        public readonly ?int $minimoCompra = null,

        /** Id de producto de Shopify al que se restringe el descuento. */
        public readonly ?string $productoId = null,

        /** Id de colección de Shopify al que se restringe el descuento. */
        public readonly ?string $categoriaId = null,

        /** Id de producto de Shopify que se regala (100 % de descuento). */
        public readonly ?string $regaloProductoId = null,

        /**
         * Producto más barato del carrito, calculado por crear_cupon.php.
         * Solo se usa con soloUno y sin producto ni colección.
         */
        public readonly ?string $productoMasBaratoId = null,
    ) {
        $this->validar();
    }

    public function esPorcentaje(): bool
    {
        return $this->tipo === self::TIPO_PORCENTAJE;
    }

    public function esEnvioGratis(): bool
    {
        return $this->tipo === self::TIPO_ENVIO_GRATIS;
    }

    /**
     * Copia con el producto más barato del carrito, que crear_cupon.php solo
     * calcula cuando necesitaCarrito().
     */
    public function conProductoMasBarato(string $productoId): self
    {
        return new self(
            codigo: $this->codigo,
            tipo: $this->tipo,
            valor: $this->valor,
            emailCliente: $this->emailCliente,
            soloUno: $this->soloUno,
            importeMaximo: $this->importeMaximo,
            minimoCompra: $this->minimoCompra,
            productoId: $this->productoId,
            categoriaId: $this->categoriaId,
            regaloProductoId: $this->regaloProductoId,
            productoMasBaratoId: $productoId,
        );
    }

    /** Necesita el artículo más barato del carrito para poder crearse. */
    public function necesitaCarrito(): bool
    {
        return $this->soloUno
            && !$this->esEnvioGratis()
            && $this->regaloProductoId === null
            && $this->productoId === null
            && $this->categoriaId === null;
    }

    private function validar(): void
    {
        $codigo = trim($this->codigo);

        if ($codigo === '' || mb_strlen($codigo) > self::CODIGO_MAX) {
            throw new InvalidArgumentException('El código del cupón está vacío o es demasiado largo.');
        }

        if (!in_array($this->tipo, [self::TIPO_IMPORTE, self::TIPO_PORCENTAJE, self::TIPO_ENVIO_GRATIS], true)) {
            throw new InvalidArgumentException('Tipo de cupón no reconocido. Admitidos: AMOUNT, PERCENT, SHIPPING.');
        }

        if (filter_var($this->emailCliente, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Falta el correo del cliente o no es válido.');
        }

        $sinValor = $this->esEnvioGratis() || $this->regaloProductoId !== null || $this->importeMaximo !== null;

        if (!$sinValor && $this->valor <= 0) {
            throw new InvalidArgumentException('El valor del descuento debe ser mayor que cero.');
        }

        if ($this->esPorcentaje() && $this->valor > 100) {
            throw new InvalidArgumentException('Un descuento porcentual no puede superar el 100 %.');
        }

        if ($this->importeMaximo !== null && $this->importeMaximo <= 0) {
            throw new InvalidArgumentException('El importe máximo debe ser mayor que cero.');
        }

        if ($this->minimoCompra !== null && $this->minimoCompra < 0) {
            throw new InvalidArgumentException('El mínimo de compra no puede ser negativo.');
        }

        // Los ids de Shopify son numéricos. Un (int) sobre otra cosa daría 0
        // y Shopify rechazaría la regla con un error poco claro.
        foreach ([
            'product_id'      => $this->productoId,
            'category_id'     => $this->categoriaId,
            'gift_product_id' => $this->regaloProductoId,
        ] as $campo => $id) {
            if ($id !== null && !ctype_digit($id)) {
                throw new InvalidArgumentException(sprintf('%s debe ser un id numérico de Shopify.', $campo));
            }
        }

        if ($this->productoId !== null && $this->categoriaId !== null) {
            throw new InvalidArgumentException('Un cupón no puede restringirse a la vez a un producto y a una colección.');
        }
    }
}
