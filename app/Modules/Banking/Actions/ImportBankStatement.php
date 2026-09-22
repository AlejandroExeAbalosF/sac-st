<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\MatchMethod;
use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\ParsedRow;
use App\Modules\Banking\Support\StatementPreview;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Persiste un extracto: el archivo, sus filas y los movimientos nuevos.
 *
 * Toda la escritura ocurre dentro de una transacción. Un extracto a medias
 * es peor que ninguno: dejaría movimientos sin las filas que los explican,
 * y la próxima importación los tomaría por buenos.
 *
 * **Un archivo rechazado también se guarda.** Con `status = failed`, su
 * motivo y sus totales, pero sin filas ni movimientos. Saber que alguien
 * intentó importar el extracto equivocado un martes a las nueve es parte
 * de la trazabilidad; perderlo porque «no entró» no.
 */
final class ImportBankStatement
{
    public function __construct(
        private readonly ParseBankStatement $parse,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
    ) {}

    public function handle(
        UploadedFile $file,
        BankAccount $account,
        int $userId,
    ): BankStatementImport {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $hash = hash_file('sha256', $path);

        if ($hash === false) {
            throw new RuntimeException('No se pudo calcular la huella del archivo.');
        }

        $this->assertNotAlreadyImported($account, $hash);

        $format = SourceFormat::fromExtension($file->getClientOriginalExtension());
        $preview = $this->parse->handle($path, $account, $format);

        /*
         * El archivo entra como adjunto, dentro de la transacción y
         * después del import, porque necesita su `subject_id`. Se guarda
         * aunque el extracto se rechace: uno rechazado es exactamente el
         * que después alguien va a querer volver a mirar.
         */
        $adjunto = null;

        try {
            return DB::transaction(function () use ($account, $file, $format, $hash, $preview, $userId, &$adjunto): BankStatementImport {
                $import = $this->createImport($account, $file, $format, $hash, $preview, $userId);

                $adjunto = $this->attachments->handle(
                    file: $file,
                    subject: AttachmentSubject::Import,
                    subjectId: $import->id,
                    documentType: 'bank_statement',
                    userId: $userId,
                    title: $file->getClientOriginalName(),
                );

                if (! $preview->isImportable()) {
                    $this->auditar->handle('banco.extracto.rechazado', $import, after: [
                        'original_filename' => $import->original_filename,
                        'failure_reason' => $import->failure_reason,
                    ], actorId: $userId);

                    return $import;
                }

                [$new, $duplicate] = $this->persistRows($import, $account, $preview);

                $import->forceFill([
                    'rows_new' => $new,
                    'rows_duplicate' => $duplicate,
                    'status' => ImportStatus::Completed,
                    'imported_at' => now(),
                ])->save();

                $this->auditar->handle('banco.extracto.importado', $import, after: [
                    'original_filename' => $import->original_filename,
                    'rows_total' => $import->rows_total,
                    'rows_new' => $new,
                    'rows_duplicate' => $duplicate,
                ], actorId: $userId);

                return $import;
            });
        } catch (Throwable $exception) {
            /*
             * La fila del adjunto vuelve atrás con la transacción, pero el
             * archivo ya está escrito en el disco. Sin esto quedaría
             * ocupando lugar sin que nada lo referencie.
             */
            if ($adjunto !== null) {
                $this->attachments->deleteFile([
                    'disk' => $adjunto->storage_disk,
                    'key' => $adjunto->object_key,
                ]);
            }

            throw $exception;
        }
    }

    private function assertNotAlreadyImported(BankAccount $account, string $hash): void
    {
        $previous = BankStatementImport::query()
            ->where('bank_account_id', $account->id)
            ->where('file_sha256', $hash)
            ->first();

        if ($previous === null) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Este archivo ya se importó el %s como «%s».',
            $previous->created_at?->copy()->timezone((string) config('app.display_timezone'))->format('d/m/Y H:i') ?? 'antes',
            $previous->original_filename,
        ));
    }

    private function createImport(
        BankAccount $account,
        UploadedFile $file,
        SourceFormat $format,
        string $hash,
        StatementPreview $preview,
        int $userId,
    ): BankStatementImport {
        $statement = $preview->statement;
        $importable = $preview->isImportable();

        return BankStatementImport::query()->create([
            'bank_account_id' => $account->id,
            'imported_by' => $userId,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'file_size' => $file->getSize(),
            'file_sha256' => $hash,
            'source_format' => $format,
            'parser_version' => $preview->parserVersion,
            'account_number_in_file' => $statement->accountNumber,
            'currency_in_file' => $statement->currency,
            'downloaded_at' => $statement->downloadedAt,
            'operator_in_file' => $statement->operator,
            'period_from' => $statement->periodFrom(),
            'period_to' => $statement->periodTo(),
            'opening_balance' => $statement->openingBalance(),
            'closing_balance' => $statement->closingBalance(),
            'balance_chain_ok' => $preview->chain->isValid,
            /*
             * Se guarda aunque el operador siga adelante. Dentro de seis
             * meses, cuando alguien busque por qué una cuota nunca se
             * financió, el registro de que ese día el sistema avisó de un
             * salto es la pista.
             */
            'continuity_warning' => $preview->continuityWarning,
            'status' => $importable ? ImportStatus::Parsing : ImportStatus::Failed,
            'failure_reason' => $importable ? null : $preview->failureReason(),
            'rows_total' => count($statement->rows),
            'rows_valid' => count($statement->usableRows()),
            'rows_rejected' => $statement->rejectedCount(),
            /*
             * En cero explícito y no por el default de la columna: la fila
             * recién creada se devuelve tal cual quedó en memoria, y un
             * default de PostgreSQL no aparece ahí hasta releerla. Un
             * archivo rechazado nunca llega a contarlos, así que sin esto
             * viajaban nulos a la pantalla.
             */
            'rows_new' => 0,
            'rows_duplicate' => 0,
            'imported_at' => null,
        ]);
    }

    /**
     * Guarda cada fila y crea los movimientos que todavía no existen.
     *
     * El mapa local no es una optimización: un mismo archivo puede traer
     * dos veces el mismo movimiento, y sin él la segunda vez intentaría
     * crearlo de nuevo y chocaría contra el índice único de la base.
     *
     * @return array{0: int, 1: int} nuevos y repetidos
     */
    private function persistRows(
        BankStatementImport $import,
        BankAccount $account,
        StatementPreview $preview,
    ): array {
        $new = 0;
        $duplicate = 0;
        /** @var array<string, int> $seen huella => id del movimiento */
        $seen = [];

        foreach ($preview->statement->rows as $row) {
            if (! $row->isUsable()) {
                $this->storeRow($import, $row, null, null, null);

                continue;
            }

            $fingerprint = ParseBankStatement::fingerprintOf($row, $account);

            if ($fingerprint === null) {
                $this->storeRow($import, $row, null, null, null);

                continue;
            }

            $deduplicable = $row->balanceAfter !== null;
            $transactionId = $deduplicable
                ? ($seen[$fingerprint] ?? $this->findExisting($account, $fingerprint))
                : null;

            if ($transactionId !== null) {
                $duplicate++;
                $seen[$fingerprint] = $transactionId;

                $this->storeRow($import, $row, $fingerprint, $transactionId, MatchMethod::Fingerprint);

                continue;
            }

            $transaction = $this->createTransaction($import, $account, $row, $fingerprint);
            if ($deduplicable) {
                $seen[$fingerprint] = $transaction->id;
            }
            $new++;

            $this->storeRow($import, $row, $fingerprint, $transaction->id, MatchMethod::Imported);
        }

        return [$new, $duplicate];
    }

    private function findExisting(BankAccount $account, string $fingerprint): ?int
    {
        /** @var BankTransaction|null $existing */
        $existing = BankTransaction::query()
            ->where('bank_account_id', $account->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        return $existing?->id;
    }

    private function createTransaction(
        BankStatementImport $import,
        BankAccount $account,
        ParsedRow $row,
        string $fingerprint,
    ): BankTransaction {
        return BankTransaction::query()->create([
            'bank_account_id' => $account->id,
            'first_seen_import_id' => $import->id,
            'transaction_date' => $row->transactionDate,
            'value_date' => null,
            'amount' => $row->amount,
            'direction' => $row->direction,
            'operation_id' => $row->operationId,
            'causal_code' => $row->causalCode,
            'description' => $row->description,
            'counterparty_name' => $row->counterpartyName,
            'counterparty_identifier' => $row->counterpartyIdentifier,
            'balance_after' => $row->balanceAfter,
            'fingerprint' => $fingerprint,
            'reconciliation_status' => ReconciliationStatus::Pending,
        ]);
    }

    private function storeRow(
        BankStatementImport $import,
        ParsedRow $row,
        ?string $fingerprint,
        ?int $transactionId,
        ?MatchMethod $method,
    ): BankStatementRow {
        return BankStatementRow::query()->create([
            'bank_statement_import_id' => $import->id,
            'row_number' => $row->rowNumber,
            'raw_data' => $row->raw,
            'parsed_transaction_date' => $row->transactionDate,
            'parsed_value_date' => null,
            'parsed_amount' => $row->amount,
            'parsed_direction' => $row->direction,
            'parsed_operation_id' => $row->operationId,
            'parsed_causal_code' => $row->causalCode,
            'parsed_description' => $row->description,
            'parsed_counterparty' => $row->counterpartyName,
            'parsed_counterparty_identifier' => $row->counterpartyIdentifier,
            'parsed_balance_after' => $row->balanceAfter,
            'fingerprint' => $fingerprint,
            'parse_status' => $row->status,
            'error_message' => $row->errorMessage,
            'bank_transaction_id' => $transactionId,
            'match_method' => $method,
            /*
             * Una fila sin saldo posterior se vinculó por una huella que
             * no distingue dos movimientos idénticos. Entra igual, pero
             * declarando que la certeza es menor: es lo que después
             * permite listar los casos que conviene mirar a mano.
             */
            'match_confidence' => $method === null
                ? null
                : ($row->status === ParseStatus::Warning ? 60 : 100),
            'linked_by' => null,
        ]);
    }
}
