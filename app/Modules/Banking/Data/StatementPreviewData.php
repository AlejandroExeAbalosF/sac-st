<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Support\ParsedRow;
use App\Modules\Banking\Support\StatementPreview;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el operador ve antes de confirmar la importación.
 *
 * Importar sin ver es la forma más rápida de meter el extracto de otra
 * cuenta o de duplicar un período entero. Esta pantalla convierte la
 * importación en una decisión con información: cuántos movimientos son
 * nuevos, cuántos ya estaban, y si algo impide seguir.
 */
#[TypeScript]
final class StatementPreviewData extends Data
{
    public function __construct(
        public SourceFormat $sourceFormat,
        public bool $importable,
        /** @var list<string> */
        public array $problems,
        /**
         * Salto respecto de lo ya importado.
         *
         * No impide importar: dice que probablemente falte subir otro
         * extracto, no que este esté mal.
         */
        public ?string $continuityWarning,
        /** `null` cuando faltan saldos y la cadena no se puede verificar. */
        public ?bool $balanceChainOk,
        public ?string $accountNumberInFile,
        public ?string $currencyInFile,
        public ?string $operatorInFile,
        public ?string $downloadedAt,
        public ?string $periodFrom,
        public ?string $periodTo,
        /** @var numeric-string|null */
        public ?string $openingBalance,
        /** @var numeric-string|null */
        public ?string $closingBalance,
        public int $rowsTotal,
        public int $rowsValid,
        public int $rowsRejected,
        public int $newCount,
        public int $duplicateCount,
        /** @var list<StatementPreviewRowData> */
        public array $rows,
    ) {}

    public static function fromPreview(StatementPreview $preview): self
    {
        $statement = $preview->statement;

        return new self(
            sourceFormat: $statement->format,
            importable: $preview->isImportable(),
            problems: $preview->problems,
            continuityWarning: $preview->continuityWarning,
            balanceChainOk: $preview->chain->isValid,
            accountNumberInFile: $statement->accountNumber,
            currencyInFile: $statement->currency,
            operatorInFile: $statement->operator,
            downloadedAt: $statement->downloadedAt,
            periodFrom: $statement->periodFrom(),
            periodTo: $statement->periodTo(),
            openingBalance: $statement->openingBalance(),
            closingBalance: $statement->closingBalance(),
            rowsTotal: count($statement->rows),
            rowsValid: count($statement->usableRows()),
            rowsRejected: $statement->rejectedCount(),
            newCount: $preview->newCount(),
            duplicateCount: $preview->duplicateCount(),
            rows: array_map(
                fn (ParsedRow $row): StatementPreviewRowData => StatementPreviewRowData::fromRow(
                    $row,
                    $preview->isRepeated($row->rowNumber),
                ),
                $statement->rows,
            ),
        );
    }
}
