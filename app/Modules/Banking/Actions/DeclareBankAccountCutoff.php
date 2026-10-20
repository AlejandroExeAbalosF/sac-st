<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankAccountOpening;
use App\Modules\Banking\Support\AccountCutoffBalance;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Declara el saldo de una cuenta compartida al corte, una sola vez.
 *
 * La cuenta la usan varias cajas —Multas y, mañana, Aranceles— y su saldo
 * no se puede repartir entre ellas (M08, M12): se declara para la cuenta,
 * tal como lo informa el banco. Lo que el extracto trae hasta ese día ya
 * está adentro y no entra a ningún libro como dinero nuevo.
 *
 * Cada control lo repite la base (`bank_account_openings_guard`); acá se
 * dice con un mensaje al lado del campo.
 */
final class DeclareBankAccountCutoff
{
    public function __construct(
        private readonly AccountCutoffBalance $saldos,
        private readonly StoreAttachment $adjuntos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        BankAccount $cuenta,
        CarbonImmutable $cutoffOn,
        string $statementBalance,
        ?string $notes,
        ?UploadedFile $evidence,
        int $actorId,
    ): BankAccountOpening {
        $saldo = Decimal::scale($statementBalance);

        if ($cutoffOn->toDateString() >= BusinessDate::today()->toDateString()) {
            throw ValidationException::withMessages([
                'cutoffOn' => 'El corte es un día ya cerrado: tiene que ser anterior a hoy.',
            ]);
        }

        $adjunto = null;

        try {
            return DB::transaction(function () use ($cuenta, $cutoffOn, $saldo, $notes, $evidence, $actorId, &$adjunto): BankAccountOpening {
                BankAccount::query()->lockForUpdate()->findOrFail($cuenta->id);

                $this->assertDeclarable($cuenta, $cutoffOn, $saldo);

                $corte = BankAccountOpening::query()->create([
                    'bank_account_id' => $cuenta->id,
                    'cutoff_on' => $cutoffOn->toDateString(),
                    'statement_balance' => $saldo,
                    'declared_by' => $actorId,
                    'notes' => self::texto($notes),
                ]);

                if ($evidence !== null) {
                    $adjunto = $this->adjuntos->handle(
                        file: $evidence,
                        subject: AttachmentSubject::BankAccountOpening,
                        subjectId: $corte->id,
                        documentType: 'bank_statement_balance',
                        userId: $actorId,
                        title: 'Saldo al corte',
                    );
                }

                $this->auditar->handle(
                    'banco.cuenta.corte-declarado',
                    $cuenta,
                    metadata: [
                        'corte' => $cutoffOn->format('d/m/Y'),
                        'saldo' => Decimal::format($saldo),
                        'comprobante' => $adjunto?->original_filename,
                    ],
                    actorId: $actorId,
                );

                return $corte;
            });
        } catch (Throwable $exception) {
            // La fila volvió atrás: el archivo ya escrito quedaría huérfano.
            if ($adjunto instanceof Attachment) {
                $this->adjuntos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $exception;
        }
    }

    /**
     * @param  numeric-string  $saldo
     *
     * @throws ValidationException
     */
    private function assertDeclarable(BankAccount $cuenta, CarbonImmutable $cutoffOn, string $saldo): void
    {
        if (BankAccountOpening::query()->where('bank_account_id', $cuenta->id)->exists()) {
            throw ValidationException::withMessages([
                'cutoffOn' => 'Esta cuenta ya tiene su corte declarado, y un corte no se rehace.',
            ]);
        }

        $caja = DB::table('journal_lines')
            ->join('financial_events', 'financial_events.id', '=', 'journal_lines.financial_event_id')
            ->join('cash_boxes', 'cash_boxes.id', '=', 'financial_events.cash_box_id')
            ->where('financial_events.event_type', 'opening_balance')
            ->whereIn('financial_events.status', ['posted', 'reversed'])
            ->where('journal_lines.account_code', 'BANK_ACCOUNT')
            ->where('journal_lines.bank_account_id', $cuenta->id)
            ->value('cash_boxes.name');

        if (is_string($caja)) {
            throw ValidationException::withMessages([
                'cutoffOn' => "La apertura de la caja {$caja} ya declaró el saldo de esta cuenta: no es una cuenta compartida.",
            ]);
        }

        $libro = DB::table('cash_book_openings')
            ->join('cash_boxes', 'cash_boxes.id', '=', 'cash_book_openings.cash_box_id')
            ->where('cash_book_openings.bank_account_id', $cuenta->id)
            ->where('cash_book_openings.opened_on', '<=', $cutoffOn->toDateString())
            ->orderBy('cash_book_openings.opened_on')
            ->first(['cash_boxes.name', 'cash_book_openings.opened_on']);

        if ($libro !== null) {
            throw ValidationException::withMessages([
                'cutoffOn' => sprintf(
                    'La caja %s opera con esta cuenta desde el %s: el corte tiene que ser anterior.',
                    $libro->name,
                    CarbonImmutable::parse((string) $libro->opened_on)->format('d/m/Y'),
                ),
            ]);
        }

        $imputado = DB::table('bank_transactions as t')
            ->where('t.bank_account_id', $cuenta->id)
            ->where('t.transaction_date', '<=', $cutoffOn->toDateString())
            ->whereRaw('(SELECT COALESCE(SUM(CASE WHEN a.reversal_of_id IS NULL THEN a.amount ELSE -a.amount END), 0)
                FROM bank_transaction_allocations a WHERE a.bank_transaction_id = t.id) > 0')
            ->min('t.transaction_date');

        if (is_string($imputado)) {
            throw ValidationException::withMessages([
                'cutoffOn' => sprintf(
                    'El movimiento del %s ya está imputado en un libro: el corte no puede incluirlo.',
                    CarbonImmutable::parse($imputado)->format('d/m/Y'),
                ),
            ]);
        }

        /*
         * Solo si un extracto importado abarca el día del corte: si no, el
         * sistema no sabe el saldo de ese día y no lo inventa con el de otra
         * fecha. La pantalla muestra la verificación pendiente.
         */
        $delExtracto = $this->saldos->atEndOf($cuenta->id, $cutoffOn);

        if ($delExtracto !== null && ! Decimal::equals($delExtracto, $saldo)) {
            throw ValidationException::withMessages([
                'statementBalance' => sprintf(
                    'Según el extracto importado, el saldo al cierre del %s era $ %s. El saldo declarado tiene que ser el del banco.',
                    $cutoffOn->format('d/m/Y'),
                    Decimal::format($delExtracto),
                ),
            ]);
        }
    }

    /** Lo que vino vacío se guarda como ausente, no como cadena vacía. */
    private static function texto(?string $valor): ?string
    {
        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }
}
