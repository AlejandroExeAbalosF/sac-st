<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imputa contablemente una diferencia de arqueo.
 *
 * **Es opcional y deliberadamente aparte del arqueo.** El §4.1 del DER deja
 * abierto si el área ajusta la diferencia o solo la documenta, y las dos
 * prácticas son legítimas: documentar deja el libro diciendo lo que debería
 * haber y la realidad diciendo otra cosa; ajustar hace que el libro
 * describa el cajón real y traslada la explicación a `CASH_DIFFERENCE`.
 *
 * Modelarlo como un acto separado —con su propio permiso, reservado al
 * contador— es lo que permite que el área elija sin que el modelo decida
 * por ella. Un arqueo revisado y no ajustado es un estado final válido.
 *
 * ```text
 * Sobra plata en el cajón:   Débito  CASH_ON_HAND     Crédito CASH_DIFFERENCE
 * Falta plata en el cajón:   Débito  CASH_DIFFERENCE  Crédito CASH_ON_HAND
 * ```
 *
 * **El signo se lee igual en los dos lados.** `CASH_DIFFERENCE` no es una
 * cuenta de ubicación ni de atribución —no dice dónde está la plata ni de
 * quién es— así que `CashBalance` la devuelve con signo de atribución, y el
 * resultado es el que conviene: su saldo queda idéntico al
 * `difference_amount` del arqueo. Negativo es faltante en los dos. Si
 * apuntara al revés, el libro estaría explicando lo contrario de lo que el
 * conteo encontró.
 */
final class AdjustCashDifference
{
    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /** @throws ValidationException */
    public function handle(CashCount $cashCount, int $actorId, ?string $authorization = null): CashCount
    {
        $authorization = trim((string) $authorization) ?: null;

        return DB::transaction(function () use ($cashCount, $actorId, $authorization): CashCount {
            CashBox::query()->lockForUpdate()->findOrFail($cashCount->cash_box_id);
            $cashCount = CashCount::query()
                ->whereKey($cashCount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($cashCount->status !== CashCountStatus::Reviewed) {
                throw ValidationException::withMessages([
                    'status' => 'Solo se ajusta un arqueo ya revisado.',
                ]);
            }

            if ($cashCount->isBalanced()) {
                throw ValidationException::withMessages([
                    'status' => 'Este arqueo cuadra con el libro: no hay diferencia que imputar.',
                ]);
            }

            $importe = Decimal::abs($cashCount->difference_amount);
            $sobra = ! Decimal::isNegative($cashCount->difference_amount);

            /*
             * El sentido del asiento lo da el signo de la diferencia, y la
             * diferencia es una columna generada: no hay forma de que el
             * asiento apunte para el lado contrario al que el conteo dice.
             */
            $lineas = $sobra
                ? [
                    EntryLine::debit(LedgerAccount::CashOnHand, $importe),
                    EntryLine::credit(LedgerAccount::CashDifference, $importe),
                ]
                : [
                    EntryLine::debit(LedgerAccount::CashDifference, $importe),
                    EntryLine::credit(LedgerAccount::CashOnHand, $importe),
                ];

            $evento = $this->postJournalEntry->handle(
                type: FinancialEventType::CashAdjustment,
                idempotencyKey: "cash-count-adjustment:{$cashCount->id}",
                lines: array_map(
                    fn (EntryLine $linea): EntryLine => $linea
                        ->in($cashCount->currency)
                        ->onCashBox($cashCount->cash_box_id)
                        ->describedAs('Diferencia de arqueo'),
                    $lineas,
                ),
                /*
                 * El ajuste lleva la fecha del arqueo y no la de hoy: la
                 * diferencia se produjo ese día, y fecharla hoy la sacaría
                 * del período que el cierre está por congelar.
                 */
                date: $cashCount->counted_on,
                cashBoxId: $cashCount->cash_box_id,
                description: $authorization ?? 'Imputación de diferencia de arqueo',
                actorId: $actorId,
            );

            $cashCount->fill([
                'status' => CashCountStatus::Adjusted,
                'adjustment_event_id' => $evento->id,
            ])->save();

            $this->recordAuditEvent->handle(
                action: 'arqueo.diferencia-imputada',
                subject: $cashCount,
                after: [
                    'difference_amount' => $cashCount->difference_amount,
                    'adjustment_event_id' => $evento->id,
                ],
                metadata: $authorization === null ? [] : ['autorizacion' => $authorization],
                actorId: $actorId,
            );

            return $cashCount->refresh();
        });
    }
}
