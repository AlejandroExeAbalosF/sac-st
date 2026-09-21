<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuota lista para entregarse en efectivo, por mostrador.
 *
 * **El mismo objeto alimenta la pantalla y la planilla impresa.** No es
 * comodidad: es lo que garantiza que el papel que el cajero lleva a la
 * ventanilla diga exactamente lo que la pantalla decía cuando lo imprimió.
 * Dos armados distintos de la misma lista terminan divergiendo, y el que
 * se entera es el beneficiario que vino al pedo.
 */
#[TypeScript]
final class CounterPayoutRowData extends Data
{
    public function __construct(
        public int $installmentId,
        public int $expedienteId,
        /**
         * El ordinal dentro del expediente, no el id global.
         *
         * Es lo que la ruta del haber espera: `Haber::resolveRouteBinding()`
         * resuelve por `haber_number` acotado al expediente. Con el id, el
         * enlace abre el haber que lleve ese ordinal —otro beneficiario del
         * mismo expediente— y no falla por ningún lado.
         */
        public int $haberNumber,
        public string $expedienteNumber,
        public string $beneficiaryName,
        public ?string $beneficiaryDocument,
        /** «Haber 1 · Cuota 2 de 3», armado para leerse de un vistazo. */
        public string $installmentLabel,
        public ?string $employerName,
        public ?string $concept,
        /** @var numeric-string */
        public string $amount,
        /**
         * El recibo de ingreso, que va antes que el de egreso.
         *
         * Nunca es nulo acá —sin él la cuota no estaría en esta cola—, pero
         * se declara opcional porque el tipo de `Receipt` lo es y mentirle
         * al analizador para ahorrar una interrogación no mejora nada.
         */
        public ?string $incomeReceiptNumber,
        public ?string $cashBoxName,
    ) {}
}
