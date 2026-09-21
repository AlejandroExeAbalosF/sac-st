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
 * 2. **Las cuentas del organismo preimpresas**, con la marca en la que
 *    corresponde. El formulario las trae listadas y se cruza la que se
 *    usó; el sistema pone la cruz donde está el dinero.
 */
final readonly class PaymentOrderPrintData
{
    /**
     * @param  list<PaymentOrderDepositRow>  $depositos
     * @param  list<array{label: string, accountNumber: string|null, marked: bool}>  $cuentas
     */
    public function __construct(
        public array $depositos = [],
        public array $cuentas = [],
        /** Ya formateado: `2.892.402,00`. */
        public string $total = '0,00',
        /** El renglón «BANCO»: el del organismo donde está el dinero. */
        public ?string $banco = null,
        /**
         * El renglón «FECHA» del bloque de la derecha.
         *
         * Es la fecha del depósito, la misma que abre el cuadro de abajo.
         * El área confirmó que los dos lugares dicen lo mismo; se resuelve
         * acá una vez para que no puedan discrepar.
         */
        public ?string $fechaBanco = null,
    ) {}
}
