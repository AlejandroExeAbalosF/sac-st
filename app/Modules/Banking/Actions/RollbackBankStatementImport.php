<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Deshace una importación: sus filas, y los movimientos que nacieron con ella.
 *
 * **Por qué existe, si `bank_transactions` es append-only.** Porque sin
 * ella un archivo mal interpretado sería definitivo: además de dejar
 * movimientos falsos para siempre, su huella ocuparía el índice único y
 * **bloquearía la importación del movimiento correcto**. La tabla es
 * append-only para que nadie edite lo que informó el banco, no para
 * convertir un error de carga en patrimonio permanente.
 *
 * Tres condiciones la mantienen honesta:
 *
 * 1. Solo se borran los movimientos que nacieron acá. Los que esta
 *    importación volvió a ver —porque el rango se pisaba con otro
 *    archivo— no le pertenecen.
 * 2. Un movimiento que también aparece en otra importación no se borra:
 *    se le reasigna la paternidad a la más antigua que lo contenga.
 * 3. Si algún movimiento dejó de estar pendiente —alguien lo concilió o
 *    lo dejó fuera del circuito— la reversión se rechaza entera. Ese
 *    trabajo es de una persona y no se descarta en silencio.
 */
final class RollbackBankStatementImport
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
    ) {}

    public function handle(BankStatementImport $import, int $userId): void
    {
        /** @var list<array{disk: string, key: string}> $archivosPendientes */
        $archivosPendientes = [];

        DB::transaction(function () use ($import, $userId, &$archivosPendientes): void {
            /** @var list<BankTransaction> $originated */
            $originated = $import->originatedTransactions()->get()->all();

            $this->assertUntouched($originated);

            // Auditar antes de borrar: después el sujeto no existe, y un
            // evento que apunta a un identificador muerto no cuenta nada.
            $this->auditar->handle('banco.extracto.revertido', $import, before: [
                'original_filename' => $import->original_filename,
                'rows_total' => $import->rows_total,
                'rows_new' => $import->rows_new,
                'status' => $import->status->value,
            ], metadata: [
                'movimientos_borrados' => count($originated),
                'usuario' => $userId,
            ], actorId: $userId);

            $this->releaseTransactions($import, $originated);

            /*
             * El archivo del extracto se va con él: un adjunto cuyo sujeto
             * ya no existe no es evidencia de nada. Es la única situación
             * en que un adjunto se borra, y por eso `forget` exige estar
             * dentro de una transacción.
             */
            foreach ($this->attachmentsOf($import) as $adjunto) {
                $archivosPendientes[] = $this->attachments->forget($adjunto);
            }

            $import->delete();
        });

        // Los archivos se eliminan recién después de confirmar: si la
        // transacción vuelve atrás, las filas reaparecen y tienen que
        // seguir apuntando a algo.
        foreach ($archivosPendientes as $pendiente) {
            $this->attachments->deleteFile($pendiente);
        }
    }

    /**
     * @return Collection<int, Attachment>
     */
    private function attachmentsOf(BankStatementImport $import): Collection
    {
        return Attachment::query()
            ->forSubject(AttachmentSubject::Import, $import->id)
            ->get();
    }

    /**
     * @param  list<BankTransaction>  $transactions
     */
    private function assertUntouched(array $transactions): void
    {
        foreach ($transactions as $transaction) {
            if ($transaction->reconciliation_status !== ReconciliationStatus::Pending) {
                throw new RuntimeException(sprintf(
                    'No se puede revertir: el movimiento del %s por %s ya fue tratado (%s). '
                    .'Hay que deshacer esa decisión antes.',
                    $transaction->transaction_date?->format('d/m/Y') ?? 'sin fecha',
                    $transaction->amount,
                    $transaction->reconciliation_status->label(),
                ));
            }
        }
    }

    /**
     * @param  list<BankTransaction>  $transactions
     */
    private function releaseTransactions(BankStatementImport $import, array $transactions): void
    {
        if ($transactions === []) {
            return;
        }

        /*
         * Habilita el borrado solo dentro de esta transacción. `SET LOCAL`
         * se descarta al terminar, así que la excepción al append-only no
         * sobrevive a la operación que la necesitaba.
         */
        DB::statement("SET LOCAL sacst.allow_bank_transaction_delete = 'on'");

        foreach ($transactions as $transaction) {
            $heir = $this->otherImportOf($import, $transaction);

            if ($heir !== null) {
                // Lo vio otro archivo antes o después: el movimiento es
                // real y sobrevive, cambiando de padre.
                $transaction->forceFill(['first_seen_import_id' => $heir])->save();

                continue;
            }

            $transaction->delete();
        }
    }

    /**
     * La importación más antigua, distinta de esta, que también trajo el
     * movimiento.
     */
    private function otherImportOf(BankStatementImport $import, BankTransaction $transaction): ?int
    {
        /** @var BankStatementRow|null $row */
        $row = BankStatementRow::query()
            ->where('bank_transaction_id', $transaction->id)
            ->where('bank_statement_import_id', '!=', $import->id)
            ->orderBy('bank_statement_import_id')
            ->first();

        return $row?->bank_statement_import_id;
    }
}
