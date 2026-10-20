<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\FineDocumentNumber;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra una resolución, un acta u otro papel en el expediente.
 *
 * Se puede cargar antes que la multa (M01): una resolución que llega sola
 * queda en el expediente y se vincula cuando se carga la multa.
 *
 * El texto se guarda como lo escribe el papel; número y año se leen de él.
 * Un acta toma su tipo del formulario o, si no viene, del propio texto
 * («HYS N° 1025288/25»).
 */
final class AttachFineDocument
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  string  $field  Dónde mostrar el error si el texto no se entiende.
     */
    public function handle(FineCase $expediente, NewDocument $documento, ?int $actorId, string $field = 'text'): FineDocument
    {
        $leido = FineDocumentNumber::parse($documento->originalText);
        $tipoDeActa = $documento->actType ?? $leido?->actType;

        $this->assertReadable($documento, $leido, $tipoDeActa, $field);

        return DB::transaction(function () use ($expediente, $documento, $leido, $tipoDeActa, $actorId): FineDocument {
            $nuevo = FineDocument::query()->create([
                'kind' => $documento->kind,
                'act_type' => $documento->kind === FineDocumentKind::Act ? $tipoDeActa : null,
                'number' => $documento->kind === FineDocumentKind::Other ? null : $leido?->number,
                'year' => $documento->kind === FineDocumentKind::Other ? null : $leido?->year,
                'original_text' => $documento->originalText,
                'issued_on' => $documento->issued->date,
                'issued_precision' => $documento->issued->precision,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $expediente->documents()->attach($nuevo->id, ['created_by' => $actorId]);

            $this->auditar->handle(
                'documento-multa.registrado',
                $nuevo,
                after: [
                    'kind' => $nuevo->kind->value,
                    'original_text' => $nuevo->original_text,
                ],
                metadata: ['expediente' => $expediente->label()],
                actorId: $actorId,
            );

            return $nuevo;
        });
    }

    /**
     * Lo que la base exige a una carga manual, dicho antes y en castellano.
     */
    private function assertReadable(
        NewDocument $documento,
        ?FineDocumentNumber $leido,
        ?FineActType $tipoDeActa,
        string $field,
    ): void {
        if ($documento->originalText === '') {
            throw ValidationException::withMessages([$field => 'Escribí el documento como figura en el papel.']);
        }

        if ($documento->kind === FineDocumentKind::Other) {
            return;
        }

        if ($leido === null) {
            throw ValidationException::withMessages([
                $field => $documento->kind === FineDocumentKind::Resolution
                    ? 'No se entiende el número de la resolución. Se espera N°0649/26.'
                    : 'No se entiende el número del acta. Se espera HYS N°1025288/25 o LAB N°25045/25.',
            ]);
        }

        if ($documento->kind === FineDocumentKind::Resolution && $leido->year === null) {
            throw ValidationException::withMessages([$field => 'A la resolución le falta el año: N°0649/26.']);
        }

        if ($documento->kind === FineDocumentKind::Act && $tipoDeActa === null) {
            throw ValidationException::withMessages([$field => 'Indicá si el acta es laboral (LAB) o de higiene y seguridad (HYS).']);
        }
    }
}
