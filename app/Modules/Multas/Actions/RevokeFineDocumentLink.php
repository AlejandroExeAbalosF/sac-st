<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Desvincula un documento de la multa anulando el vínculo.
 *
 * Es cómo se corrige un documento vinculado por error: no se borra, el
 * vínculo queda a la vista como anulado, con el motivo y quién lo hizo, y el
 * documento sigue en el expediente. Lo correcto se vincula después.
 *
 * No se desvincula un documento que respalda un movimiento vigente de la
 * obligación: el importe original queda con su resolución, y un ajuste se
 * revierte antes. La base lo impone (`fine_document_links_revoke_only`);
 * acá se dice qué hacer.
 */
final class RevokeFineDocumentLink
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(Fine $multa, FineDocument $documento, FineDocumentRole $papel, Reason $reason, ?int $actorId): void
    {
        DB::transaction(function () use ($multa, $documento, $papel, $reason, $actorId): void {
            // Como el ajuste: dos actos simultáneos sobre la multa no se cruzan.
            Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();

            $vinculo = DB::table('fine_document_links')
                ->where('fine_id', $multa->id)
                ->where('fine_document_id', $documento->id)
                ->where('role', $papel->value)
                ->whereNull('revoked_at')
                ->value('id');

            if ($vinculo === null) {
                throw ValidationException::withMessages(['reason' => 'Ese documento no está vinculado a la multa con ese papel.']);
            }

            $otroPapel = DB::table('fine_document_links')
                ->where('fine_id', $multa->id)
                ->where('fine_document_id', $documento->id)
                ->whereNull('revoked_at')
                ->where('id', '<>', $vinculo)
                ->exists();

            $respaldado = $otroPapel ? null : FineDebtEntry::query()
                ->where('fine_id', $multa->id)
                ->where('fine_document_id', $documento->id)
                ->where('kind', '<>', FineDebtEntryKind::Reversal)
                ->whereDoesntHave('reversedBy')
                ->orderBy('id')
                ->first();

            if ($respaldado instanceof FineDebtEntry) {
                throw ValidationException::withMessages([
                    'reason' => match ($respaldado->kind) {
                        FineDebtEntryKind::Constitution => 'Es el documento del importe original de la multa: no se desvincula. Si se leyó mal, corregí su número o su fecha.',
                        FineDebtEntryKind::Reduction => 'Respalda una reducción vigente de la obligación: revertí esa reducción antes de desvincularlo.',
                        default => 'Respalda un aumento vigente de la obligación: revertí ese aumento antes de desvincularlo.',
                    },
                ]);
            }

            DB::table('fine_document_links')->where('id', $vinculo)->update([
                'revoked_at' => now(),
                'revoked_by' => $actorId,
                'revocation_reason_code' => $reason->code->value,
                'revocation_reason' => $reason->detail,
            ]);

            $this->auditar->handle(
                'multa.documento-desvinculado',
                $multa,
                metadata: ['documento' => $documento->label(), 'papel' => $papel->label(), ...$reason->auditMetadata()],
                actorId: $actorId,
            );
        });
    }
}
