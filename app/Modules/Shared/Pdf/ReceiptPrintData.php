<?php

declare(strict_types=1);

namespace App\Modules\Shared\Pdf;

/**
 * Lo que el formulario del recibo tiene además del comprobante.
 *
 * El papel pide datos que no viven en `receipts`: el número de operación
 * del depósito, los del cheque, cuántas cuotas tiene el haber. Rastrearlos
 * desde acá obligaría a esta clase a mirar Haberes y Banking, que están
 * más arriba en la pila; llegan armados desde el módulo que sí puede
 * verlos.
 */
final readonly class ReceiptPrintData
{
    public function __construct(
        /** La cuenta del organismo, cuando el dinero entró por banco. */
        public ?string $cuenta = null,
        /** El número de operación del depósito, si el ticket lo trajo. */
        public ?string $numeroOperacion = null,
        public ?string $chequeNumero = null,
        public ?string $chequeBanco = null,
        /** Para las casillas «Cuota [X] de [Y]». */
        public ?int $cuotaNumero = null,
        public ?int $cuotaTotal = null,
        /** El renglón de observaciones del formulario. */
        public ?string $observaciones = null,
    ) {}
}
