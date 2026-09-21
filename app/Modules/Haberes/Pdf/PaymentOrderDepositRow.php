<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Pdf;

/**
 * Un renglón del cuadro de depósitos, listo para imprimir.
 *
 * Todo en `string` y ya formateado: la plantilla no formatea importes ni
 * fechas. Es la misma regla que rige el resto del sistema —ningún importe
 * se formatea en línea— y acá pesa el doble, porque un `float` metido en
 * un documento que pide transferir dinero es la peor versión del problema.
 */
final readonly class PaymentOrderDepositRow
{
    public function __construct(
        /** «DEPÓSITO U OPERACIÓN N°». */
        public ?string $operacion,
        /** Ya en formato del área: `28/5/2026`. */
        public ?string $fecha,
        /** «CTA. CTE.». */
        public ?string $cuenta,
        /** Ya formateado: `2.892.402,00`. */
        public string $importe,
    ) {}
}
