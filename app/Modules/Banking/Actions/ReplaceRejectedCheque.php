<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Llegó el cheque que reemplaza al rechazado (Multas M03).
 *
 * Solo otro cheque del mismo importe: el cambio de medio no es un reemplazo
 * (P13). Entra a custodia sin recibo nuevo ni nueva recaudación —el pago ya
 * estaba aplicado— y cancela lo que la empresa tenía que regularizar
 * (D `CHEQUES_IN_CUSTODY` / C `REJECTED_CHEQUES`). El seguimiento bancario del
 * rechazado sigue su curso: el débito puede llegar después.
 *
 * El reemplazo queda en la cadena del rechazado y se deposita como cualquier
 * cheque; si también se rechaza, se repite el circuito.
 */
final class ReplaceRejectedCheque
{
    public function __construct(private readonly PostJournalEntry $asentar) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}  $datos
     *
     * @throws ValidationException
     */
    public function handle(ChequeRejection $rechazo, array $datos, string $idempotencyKey, ?int $actorId): FundReceipt
    {
        return DB::transaction(function () use ($rechazo, $datos, $idempotencyKey, $actorId): FundReceipt {
            // Un segundo envío: el reemplazo ya está.
            $yaRecibido = FundReceipt::query()
                ->whereIn('financial_event_id', FinancialEvent::query()->where('idempotency_key', $idempotencyKey)->select('id'))
                ->first();

            if ($yaRecibido !== null) {
                return $yaRecibido;
            }

            // El cheque rechazado primero y después el rechazo: el mismo orden que al rechazar.
            $rechazado = FundReceipt::query()->lockForUpdate()->findOrFail($rechazo->fund_receipt_id);
            $rechazo = ChequeRejection::query()->lockForUpdate()->findOrFail($rechazo->id);

            if (! $rechazo->isOpen()) {
                throw ValidationException::withMessages([
                    'chequeNumber' => 'Ese rechazo ya está regularizado: el cheque tiene su reemplazo.',
                ]);
            }

            $numero = trim($datos['number']);

            if ($numero === '') {
                throw ValidationException::withMessages(['chequeNumber' => 'Anotá el número del cheque de reemplazo.']);
            }

            $hoy = BusinessDate::today();
            $moneda = Currency::from($rechazado->currency);

            $evento = $this->asentar->handle(
                type: FinancialEventType::ChequeReplaced,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::ChequesInCustody, $rechazado->amount)->in($moneda)->onCashBox($rechazado->cash_box_id),
                    EntryLine::credit(LedgerAccount::RejectedCheques, $rechazado->amount)->in($moneda)->onCashBox($rechazado->cash_box_id),
                ],
                date: $hoy,
                cashBoxId: $rechazado->cash_box_id,
                description: 'Reemplazo del cheque rechazado n.º '.$rechazado->cheque_number,
                actorId: $actorId,
            );

            $reemplazo = FundReceipt::query()->create([
                'financial_event_id' => $evento->id,
                'cash_box_id' => $rechazado->cash_box_id,
                'depositor_id' => $rechazado->depositor_id,
                'medium' => PaymentMedium::Cheque,
                'amount' => $rechazado->amount,
                'currency' => $rechazado->currency,
                'received_date' => $hoy,
                'received_by' => $actorId,
                'cheque_number' => $numero,
                'cheque_bank' => $datos['bank'] ?? null,
                'cheque_issue_date' => $datos['issueDate'] ?? null,
                'cheque_status' => ChequeStatus::InCustody,
                'origin' => FundReceiptOrigin::Replacement,
                'replaces_fund_receipt_id' => $rechazado->id,
            ]);

            $rechazo->forceFill([
                'replacement_fund_receipt_id' => $reemplazo->id,
                'replacement_event_id' => $evento->id,
                'regularized_at' => now(),
            ])->save();

            return $reemplazo;
        });
    }
}
