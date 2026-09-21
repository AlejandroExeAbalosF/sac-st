<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuota a la que se le puede asignar la recepción.
 *
 * Trae `remaining` porque es lo que el operador necesita comparar contra
 * lo que quedó sin asignar: si coinciden, la asignación es de un clic.
 */
#[TypeScript]
final class InstallmentCandidateData extends Data
{
    public function __construct(
        public int $id,
        public int $haberId,
        public int $installmentNumber,
        /** @var numeric-string */
        public string $expectedAmount,
        /** @var numeric-string */
        public string $allocated,
        /** @var numeric-string */
        public string $remaining,
        public bool $isFullyFunded,
        public string $beneficiaryName,
        public ?string $beneficiaryDocument,
        public ?string $concept,
        public ?string $description,
        public string $expedienteNumber,
        public int $expedienteId,
        /**
         * El medio con el que ya se está financiando, si se fijó.
         *
         * Una cuota se financia con un solo medio (invariante 4), así que
         * esto es lo que le dice a la pantalla cuándo bloquear la opción
         * antes de que el operador la elija.
         */
        public ?string $fixedMedium,
    ) {}

    /**
     * @param  numeric-string  $allocated
     * @param  numeric-string  $remaining
     */
    public static function fromModel(
        BeneficiaryInstallment $installment,
        string $allocated,
        string $remaining,
        ?string $fixedMedium,
    ): self {
        $haber = $installment->haber;
        $expediente = $haber->expediente;

        return new self(
            id: $installment->id,
            haberId: $installment->haber_id,
            installmentNumber: $installment->installment_number,
            expectedAmount: $installment->importeEsperado(),
            allocated: $allocated,
            remaining: $remaining,
            isFullyFunded: bccomp($remaining, '0', 2) === 0,
            beneficiaryName: $haber->beneficiary->name,
            beneficiaryDocument: $haber->beneficiary->document,
            concept: $haber->concept,
            description: $installment->description,
            expedienteNumber: $expediente->display_number,
            expedienteId: $expediente->id,
            fixedMedium: $fixedMedium,
        );
    }
}
