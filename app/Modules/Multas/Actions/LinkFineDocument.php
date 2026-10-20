<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Vincula un documento del expediente con la multa, diciendo qué papel cumple.
 *
 * Es lo que registra una ratificación: la resolución que confirma la multa
 * sin cambiar su importe queda vinculada y la obligación no se mueve
 * (M04). También sirve para un acta o un papel de respaldo que llegó
 * después.
 */
final class LinkFineDocument
{
    public function __construct(
        private readonly AttachFineDocument $registrarDocumento,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(Fine $multa, FineDocument|NewDocument $documento, FineDocumentRole $papel, ?int $actorId): FineDocument
    {
        return DB::transaction(function () use ($multa, $documento, $papel, $actorId): FineDocument {
            $multa->loadMissing('fineCase');

            $vinculado = $documento instanceof NewDocument
                ? $this->registrarDocumento->handle($multa->fineCase, $documento, $actorId, 'document.text')
                : $documento;

            if (! $multa->fineCase->documents()->whereKey($vinculado->id)->exists()) {
                throw ValidationException::withMessages(['documentId' => 'Ese documento no pertenece al expediente de la multa.']);
            }

            $repetido = $multa->documents()
                ->whereKey($vinculado->id)
                ->wherePivot('role', $papel->value)
                ->exists();

            if ($repetido) {
                throw ValidationException::withMessages(['documentId' => 'Ese documento ya está vinculado a la multa con ese papel.']);
            }

            $multa->documents()->attach($vinculado->id, [
                'fine_case_id' => $multa->fine_case_id,
                'role' => $papel->value,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle(
                'multa.documento-vinculado',
                $multa,
                metadata: ['documento' => $vinculado->label(), 'papel' => $papel->label()],
                actorId: $actorId,
            );

            return $vinculado;
        });
    }
}
