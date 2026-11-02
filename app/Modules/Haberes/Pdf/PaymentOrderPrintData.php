<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

/**
 * Lo que el formulario de la Orden tiene además de sus propias columnas.
 *
 * Dos cosas, y las dos vienen armadas de afuera:
 *
 * 1. **La tabla de depósitos**, que al emitir sale de
 *    `payment_order_funding_sources` y en la vista previa de los renglones
 *    todavía sin congelar. Que las dos entren por el mismo lugar es lo que
 *    garantiza que la vista previa muestre el papel que se va a imprimir.
 * 2. **La cuenta del organismo involucrada en la Orden**. Como el sistema
 *    genera el formulario sobre papel blanco, no lista alternativas ni
 *    imprime una marca de selección que ya no aporta información.
 */
final readonly class PaymentOrderPrintData
{
    /**
     * @param  list<PaymentOrderDepositRow>  $depositos
     */
    public function __construct(
        public array $depositos = [],
        public ?string $cuenta = null,
        /** Ya formateado: `2.892.402,00`. */
        public string $total = '0,00',
        /** El renglón «BANCO»: el del organismo donde está el dinero. */
        public ?string $banco = null,
        /**
         * El renglón «FECHA» del bloque de la derecha.
         *
         * Es la fecha del depósito, la misma que abre el cuadro de abajo.
         * Se resuelve acá una vez para que los dos lugares no puedan
         * discrepar.
         */
        public ?string $fechaBanco = null,
    ) {}
}
