<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un documento que se desvinculó de la multa: el vínculo anulado sigue a la
 * vista, con el papel que cumplía, el motivo y quién lo anuló.
 */
#[TypeScript]
final class FineRevokedDocumentData extends Data
{
    public function __construct(
        public int $documentId,
        public string $label,
        public FineDocumentRole $role,
        public string $reason,
        public string $revokedAt,
        public ?string $revokedByName,
    ) {}

    /**
     * Espera el documento cargado desde `Fine::revokedDocuments`.
     *
     * @param  array<int, string>  $nombres  los autores, por id de usuario
     */
    public static function fromModel(FineDocument $documento, array $nombres): self
    {
        /** @var object{role: string, revoked_at: string, revoked_by: int|null, revocation_reason_code: string, revocation_reason: string|null} $vinculo */
        $vinculo = $documento->getRelationValue('pivot');

        return new self(
            documentId: $documento->id,
            label: $documento->label(),
            role: FineDocumentRole::from($vinculo->role),
            reason: Reason::stored(FineReasonCode::from($vinculo->revocation_reason_code), $vinculo->revocation_reason),
            revokedAt: Carbon::parse($vinculo->revoked_at)->toIso8601String(),
            revokedByName: $vinculo->revoked_by === null ? null : ($nombres[$vinculo->revoked_by] ?? null),
        );
    }
}
