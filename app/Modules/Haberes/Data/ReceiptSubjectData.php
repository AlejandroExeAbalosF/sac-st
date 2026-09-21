<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Models\Person;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * De quién y de qué es un comprobante, resuelto contra el circuito.
 *
 * **No son los snapshots del recibo.** El papel congela lo que decía el
 * día que se entregó; esto dice qué es hoy, y por eso trae los ids: sirve
 * para ir a la pantalla del haber o del expediente, cosa que un texto
 * congelado no permite.
 *
 * Los dos se muestran juntos cuando difieren —una razón social corregida
 * después de emitir— y ahí la diferencia no es un error: es el papel
 * diciendo la verdad de su fecha.
 */
#[TypeScript]
final class ReceiptSubjectData extends Data
{
    public function __construct(
        public int $installmentId,
        public int $installmentNumber,
        public int $haberId,
        /** Ordinal dentro del expediente: es lo que va en la dirección. */
        public int $haberNumber,
        public int $expedienteId,
        /** Forma corta de uso diario: `125957/2026`. */
        public string $expedienteNumber,
        public string $beneficiaryName,
        public ?string $beneficiaryDocument,
        public string $employerName,
        /** Concepto vigente del haber, para ubicar de qué se trata. */
        public ?string $concept,
    ) {}

    /**
     * La cuota tiene que llegar con `haber.beneficiary` y
     * `haber.expediente.employer` cargados: con `preventLazyLoading`
     * encendido, resolverlos acá no es una consulta de más, es un 500.
     */
    public static function fromInstallment(BeneficiaryInstallment $installment): self
    {
        $haber = $installment->haber;
        $expediente = $haber->expediente;
        $empleador = $expediente->employer;

        return new self(
            installmentId: $installment->id,
            installmentNumber: $installment->installment_number,
            haberId: $haber->id,
            haberNumber: $haber->haber_number,
            expedienteId: $expediente->id,
            expedienteNumber: $expediente->display_number,
            beneficiaryName: $haber->beneficiary->name,
            beneficiaryDocument: $haber->beneficiary->document,
            employerName: $empleador instanceof Person ? $empleador->name : 'Sin identificar',
            concept: $installment->description ?? $haber->concept,
        );
    }
}
