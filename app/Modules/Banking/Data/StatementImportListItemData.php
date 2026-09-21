<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Una fila del listado de importaciones. */
#[TypeScript]
final class StatementImportListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $originalFilename,
        public SourceFormat $sourceFormat,
        public ImportStatus $status,
        public ?string $failureReason,
        public string $accountLabel,
        public ?string $periodFrom,
        public ?string $periodTo,
        public int $rowsTotal,
        public int $rowsValid,
        public int $rowsRejected,
        public int $rowsNew,
        public int $rowsDuplicate,
        public ?bool $balanceChainOk,
        /** Salto detectado al importar, si lo hubo. Se advirtió y se siguió. */
        public ?string $continuityWarning,
        public ?string $importedAt,
        public string $importedBy,
        /** @var numeric-string|null */
        public ?string $closingBalance,
        /**
         * Deducido del primer movimiento; el archivo no lo declara.
         *
         * @var numeric-string|null
         */
        public ?string $openingBalance,
        /*
         * La procedencia: lo que el archivo decía de sí mismo.
         *
         * Se guarda desde el principio y hasta ahora no se veía en ninguna
         * pantalla, que es la peor forma de tener un dato de trazabilidad.
         * Cuando una importación se rechaza porque el extracto era de otra
         * cuenta, el número que traía es justamente lo que hay que mirar.
         */
        public ?string $accountNumberInFile,
        public ?string $currencyInFile,
        public ?string $operatorInFile,
        public ?string $downloadedAt,
        /** Huella del archivo: dos importaciones iguales la comparten. */
        public string $fileSha256,
        public int $fileSize,
        /**
         * El adjunto con el archivo original, para poder descargarlo.
         *
         * Nulo solo en importaciones anteriores a que `attachments`
         * existiera y cuyo archivo se hubiera perdido: la migración creó
         * el adjunto de todas las que tenían ruta.
         */
        public ?int $attachmentId,
        /** Si tiene movimientos propios, revertirla los borra. */
        public bool $canBeRolledBack,
    ) {}

    public static function fromModel(BankStatementImport $import): self
    {
        return new self(
            id: $import->id,
            originalFilename: $import->original_filename,
            sourceFormat: $import->source_format,
            status: $import->status,
            failureReason: $import->failure_reason,
            accountLabel: $import->account->label,
            periodFrom: $import->period_from?->format('Y-m-d'),
            periodTo: $import->period_to?->format('Y-m-d'),
            rowsTotal: $import->rows_total,
            rowsValid: $import->rows_valid,
            rowsRejected: $import->rows_rejected,
            rowsNew: $import->rows_new,
            rowsDuplicate: $import->rows_duplicate,
            balanceChainOk: $import->balance_chain_ok,
            continuityWarning: $import->continuity_warning,
            importedAt: $import->imported_at?->toIso8601String(),
            importedBy: $import->importer->name,
            closingBalance: $import->closing_balance,
            openingBalance: $import->opening_balance,
            accountNumberInFile: $import->account_number_in_file,
            currencyInFile: $import->currency_in_file,
            operatorInFile: $import->operator_in_file,
            downloadedAt: $import->downloaded_at?->toIso8601String(),
            fileSha256: $import->file_sha256,
            fileSize: $import->file_size,
            attachmentId: Attachment::query()
                ->forSubject(AttachmentSubject::Import, $import->id)
                ->value('id'),
            canBeRolledBack: $import->status === ImportStatus::Completed,
        );
    }
}
