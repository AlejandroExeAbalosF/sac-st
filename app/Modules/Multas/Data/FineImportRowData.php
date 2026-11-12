<?php

declare(strict_types=1);

namespace App\Modules\Multas\Data;

use App\Models\User;
use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Models\FineImportResolution;
use App\Modules\Multas\Models\FineImportRow;
use App\Support\Money\Decimal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una fila de la planilla para revisar: lo que decía, cada problema con su
 * columna y su valor, y lo que se decidió.
 */
#[TypeScript]
final class FineImportRowData extends Data
{
    /**
     * @param  list<array{code: FineImportIssue, label: string, blocking: bool, column: string|null, value: string|null, message: string, actions: list<array{value: FineImportAction, label: string}>, bulk: bool, cases: list<array{id: int, label: string}>, fineId: int|null, resolution: array{action: string, label: string, reason: string|null, byName: string|null, at: string|null}|null}>  $issues
     */
    public function __construct(
        public int $id,
        public string $sheet,
        public int $row,
        public string $caseText,
        public string $resolutionText,
        public ?string $actText,
        public string $caption,
        /** @var numeric-string|null */
        public ?string $amount,
        /** @var numeric-string */
        public string $paid,
        public array $issues,
        public ?int $fineId,
        public ?string $fineUrl,
        public ?string $failure,
    ) {}

    /**
     * Espera cargadas `resolutions.resolver` y `fine.fineCase`.
     */
    public static function fromModel(FineImportRow $fila): self
    {
        $normal = $fila->normalized;
        $decisiones = $fila->resolutions->keyBy(fn (FineImportResolution $r): string => $r->issue_code->value);
        $problemas = [];

        foreach ($fila->issues as $problema) {
            $codigo = FineImportIssue::from($problema['code']);
            $decision = $decisiones->get($codigo->value);
            $autor = $decision?->resolver;
            $meta = $problema['meta'] ?? [];

            $problemas[] = [
                'code' => $codigo,
                'label' => $codigo->label(),
                'blocking' => $codigo->blocking(),
                'column' => $problema['column'],
                'value' => $problema['value'],
                'message' => $problema['message'],
                'actions' => array_map(fn (FineImportAction $a): array => ['value' => $a, 'label' => $a->label()], $codigo->actions()),
                'bulk' => $codigo->bulk(),
                'cases' => array_values(array_map(fn (array $c): array => ['id' => (int) $c['id'], 'label' => (string) $c['label']], $meta['cases'] ?? [])),
                'fineId' => isset($meta['fine_id']) ? (int) $meta['fine_id'] : null,
                'resolution' => $decision === null ? null : [
                    'action' => $decision->action->value,
                    'label' => $decision->action->label(),
                    'reason' => $decision->reason,
                    'byName' => $autor instanceof User ? $autor->name : null,
                    'at' => $decision->resolved_at?->toIso8601String(),
                ],
            ];
        }

        return new self(
            id: $fila->id,
            sheet: $fila->sheet,
            row: $fila->row_number,
            caseText: (string) ($normal['case_text'] ?? ''),
            resolutionText: (string) ($normal['resolution_text'] ?? ''),
            actText: isset($normal['act_text']) ? (string) $normal['act_text'] : null,
            caption: (string) ($normal['caption'] ?? ''),
            amount: isset($normal['amount']) ? Decimal::scale((string) $normal['amount']) : null,
            paid: Decimal::scale((string) ($normal['paid'] ?? '0')),
            issues: $problemas,
            fineId: $fila->fine_id,
            fineUrl: $fila->fine === null ? null : route('multas.show', $fila->fine->showParameters()),
            failure: $fila->failure,
        );
    }
}
