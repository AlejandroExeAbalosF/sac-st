<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\FineActType;
use App\Modules\Multas\Enums\FineDocumentKind;

/**
 * Un documento que se carga en el mismo acto que lo usa.
 *
 * El alta de una multa trae su resolución y sus actas, y un ajuste trae la
 * resolución que lo dispone: obligar a cargarlas antes en otra pantalla
 * sería un paso que el papel no tiene.
 */
final readonly class NewDocument
{
    public function __construct(
        public FineDocumentKind $kind,
        public ?FineActType $actType,
        public string $originalText,
        public PartialDate $issued,
    ) {}

    /**
     * @param  array{kind?: string, actType?: string|null, text?: string, issuedOn?: string|null}  $datos
     */
    public static function fromArray(array $datos, FineDocumentKind $kind): self
    {
        return new self(
            $kind,
            FineActType::tryFrom((string) ($datos['actType'] ?? '')),
            trim((string) ($datos['text'] ?? '')),
            PartialDate::parse($datos['issuedOn'] ?? null) ?? PartialDate::unknown(),
        );
    }
}
