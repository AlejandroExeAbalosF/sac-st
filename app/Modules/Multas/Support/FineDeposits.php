<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Data\FineCancelledDepositData;
use App\Modules\Multas\Data\FineDepositData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El camino entre un cobro de la multa y su depósito.
 *
 * Multas no tiene tabla propia de depósitos: el traslado lleva un ítem con
 * la recepción del cobro, y la recepción es la de la aplicación del pago
 * (M06). Esto recorre ese camino en los dos sentidos, y dice dónde está la
 * plata de cada cobro.
 */
final class FineDeposits
{
    private ?int $cajaId = null;

    public function __construct(private readonly FineAttachments $adjuntos) {}

    /** La recepción del cobro, la que va al banco. */
    public function receiptOf(FinePayment $pago): ?FundReceipt
    {
        return $pago->application?->fundReceipt;
    }

    /** El pago cuyo cobro viajó en ese traslado. */
    public function paymentOf(CashToBankTransfer $traslado): ?FinePayment
    {
        $recepcion = CashToBankTransferItem::query()
            ->where('cash_to_bank_transfer_id', $traslado->id)
            ->value('fund_receipt_id');

        return $recepcion === null ? null : FinePayment::query()
            ->whereHas('application', fn (Builder $q) => $q->where('fund_receipt_id', $recepcion))
            ->first();
    }

    /** Si el traslado es de la caja de Multas: los demás no existen para estas pantallas. */
    public function owns(CashToBankTransfer $traslado): bool
    {
        return $traslado->cash_box_id === $this->cashBoxId();
    }

    public function cashBoxId(): int
    {
        return $this->cajaId ??= (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');
    }

    /**
     * Los cobros de la caja que todavía están en ella, o en tránsito: los
     * que la caja tiene que depositar o esperar.
     *
     * @return Builder<FinePayment>
     */
    public function counterPayments(FineCollectionPlace $lugar): Builder
    {
        $enTraslado = fn (Builder $q, string $estado) => $q->whereExists(fn ($e) => $e->selectRaw('1')
            ->from('cash_to_bank_transfer_items as i')
            ->join('cash_to_bank_transfers as t', 't.id', '=', 'i.cash_to_bank_transfer_id')
            ->whereColumn('i.fund_receipt_id', 'fine_payment_applications.fund_receipt_id')
            ->where('t.status', $estado));

        return FinePayment::query()
            ->where('status', FinePaymentStatus::Applied)
            ->whereIn('medium', ['cash', 'cheque'])
            ->whereHas('application', fn (Builder $aplicacion) => match ($lugar) {
                FineCollectionPlace::InCustody => $aplicacion
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('cash_to_bank_transfer_items as i')
                        ->join('cash_to_bank_transfers as t', 't.id', '=', 'i.cash_to_bank_transfer_id')
                        ->whereColumn('i.fund_receipt_id', 'fine_payment_applications.fund_receipt_id')
                        ->where('t.status', '<>', CashTransferStatus::Cancelled->value)),
                FineCollectionPlace::InTransit => $enTraslado($aplicacion, CashTransferStatus::Deposited->value),
                FineCollectionPlace::InBank => $enTraslado($aplicacion, CashTransferStatus::BankConfirmed->value),
            });
    }

    /**
     * Dónde está la plata de cada cobro de caja, con su depósito vigente y
     * los cancelados.
     *
     * @param  iterable<FinePayment>  $pagos  con `application` cargada
     * @return array<int, FineDepositData> por id del pago; solo los cobros de caja aplicados
     */
    public function forPayments(iterable $pagos): array
    {
        $recepcionDe = [];

        foreach ($pagos as $pago) {
            if ($pago->status === FinePaymentStatus::Applied && $pago->medium->isCounter() && $pago->application !== null) {
                $recepcionDe[$pago->id] = $pago->application->fund_receipt_id;
            }
        }

        if ($recepcionDe === []) {
            return [];
        }

        $traslados = CashToBankTransfer::query()
            ->join('cash_to_bank_transfer_items as i', 'i.cash_to_bank_transfer_id', '=', 'cash_to_bank_transfers.id')
            ->whereIn('i.fund_receipt_id', array_values($recepcionDe))
            ->orderBy('cash_to_bank_transfers.id')
            ->get(['cash_to_bank_transfers.*', 'i.fund_receipt_id'])
            ->groupBy('fund_receipt_id');

        $vigentes = $traslados->flatten()->filter(
            fn (CashToBankTransfer $t): bool => $t->status !== CashTransferStatus::Cancelled,
        );

        // El día del crédito en el extracto, por su imputación.
        $acreditados = DB::table('bank_transaction_allocations as a')
            ->join('bank_transactions as m', 'm.id', '=', 'a.bank_transaction_id')
            ->whereIn('a.financial_event_id', $vigentes->pluck('credit_event_id')->filter()->values()->all())
            ->whereNull('a.reversal_of_id')
            ->pluck('m.transaction_date', 'a.financial_event_id');

        $tickets = $this->adjuntos->for(AttachmentSubject::CashTransfer, array_values($vigentes->pluck('id')->all()));

        $resultado = [];

        foreach ($recepcionDe as $pagoId => $recepcionId) {
            $suyos = $traslados->get($recepcionId, collect());
            $vigente = $suyos->first(fn (CashToBankTransfer $t): bool => $t->status !== CashTransferStatus::Cancelled);

            $resultado[$pagoId] = new FineDepositData(
                place: match ($vigente?->status) {
                    CashTransferStatus::Deposited => FineCollectionPlace::InTransit,
                    CashTransferStatus::BankConfirmed => FineCollectionPlace::InBank,
                    default => FineCollectionPlace::InCustody,
                },
                transferId: $vigente?->id,
                depositDate: $vigente?->deposit_date->toDateString(),
                operationNumber: $vigente?->deposit_operation_number,
                creditedOn: $vigente?->credit_event_id !== null && isset($acreditados[$vigente->credit_event_id])
                    ? (string) $acreditados[$vigente->credit_event_id]
                    : null,
                ticket: $vigente === null ? null : ($tickets[$vigente->id][0] ?? null),
                cancelled: array_values($suyos
                    ->filter(fn (CashToBankTransfer $t): bool => $t->status === CashTransferStatus::Cancelled)
                    ->map(fn (CashToBankTransfer $t): FineCancelledDepositData => new FineCancelledDepositData(
                        transferId: $t->id,
                        depositDate: $t->deposit_date->toDateString(),
                        reason: $t->notes,
                    ))
                    ->all()),
            );
        }

        return $resultado;
    }
}
