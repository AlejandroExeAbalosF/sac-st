<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Models\User;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Data\FineCancelledDepositData;
use App\Modules\Multas\Data\FineChequeRejectionData;
use App\Modules\Multas\Data\FineDepositData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El camino entre un cobro de la multa, su cheque vigente y su depósito.
 *
 * Multas no tiene tabla propia de depósitos ni de cheques: la aplicación del
 * pago apunta a la recepción con que se cobró —la raíz—; si ese cheque se
 * rechazó, sus reemplazos cuelgan de él (`replaces_fund_receipt_id`), y el
 * último de la cadena es el instrumento vigente, el que se deposita (M03,
 * M06). Esto recorre ese camino en los dos sentidos y dice dónde está la
 * plata de cada cobro.
 */
final class FineDeposits
{
    private ?int $cajaId = null;

    public function __construct(private readonly FineAttachments $adjuntos) {}

    /** El instrumento vigente del cobro: el que va al banco. */
    public function receiptOf(FinePayment $pago): ?FundReceipt
    {
        $raiz = $pago->application?->fund_receipt_id;

        return $raiz === null ? null : FundReceipt::query()->find($this->tip($raiz));
    }

    /** El pago cuyo cobro viajó en ese traslado, aunque haya viajado un reemplazo. */
    public function paymentOf(CashToBankTransfer $traslado): ?FinePayment
    {
        $recepcion = CashToBankTransferItem::query()
            ->where('cash_to_bank_transfer_id', $traslado->id)
            ->value('fund_receipt_id');

        return $recepcion === null ? null : $this->paymentOfReceipt((int) $recepcion);
    }

    /** El pago del cheque rechazado: el de la raíz de su cadena. */
    public function paymentOfRejection(ChequeRejection $rechazo): ?FinePayment
    {
        return $this->paymentOfReceipt($rechazo->fund_receipt_id);
    }

    /** Si el traslado es de la caja de Multas: los demás no existen para estas pantallas. */
    public function owns(CashToBankTransfer $traslado): bool
    {
        return $traslado->cash_box_id === $this->cashBoxId();
    }

    /** Si el rechazo es de un cheque de la caja de Multas. */
    public function ownsRejection(ChequeRejection $rechazo): bool
    {
        return FundReceipt::query()->whereKey($rechazo->fund_receipt_id)->value('cash_box_id') === $this->cashBoxId();
    }

    public function cashBoxId(): int
    {
        return $this->cajaId ??= (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');
    }

    /**
     * Los cobros de la caja cuyo instrumento vigente está en ese lugar.
     *
     * @return Builder<FinePayment>
     */
    public function counterPayments(FineCollectionPlace $lugar): Builder
    {
        $vigente = 'fund_receipt_tip(fine_payment_applications.fund_receipt_id)';

        $enTraslado = fn (Builder $q, string $estado) => $q->whereExists(fn ($e) => $e->selectRaw('1')
            ->from('cash_to_bank_transfer_items as i')
            ->join('cash_to_bank_transfers as t', 't.id', '=', 'i.cash_to_bank_transfer_id')
            ->whereRaw("i.fund_receipt_id = {$vigente}")
            ->where('t.status', $estado));

        return FinePayment::query()
            ->where('status', FinePaymentStatus::Applied)
            ->whereIn('medium', ['cash', 'cheque'])
            ->whereHas('application', fn (Builder $aplicacion) => match ($lugar) {
                FineCollectionPlace::InCustody => $aplicacion
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('cash_to_bank_transfer_items as i')
                        ->join('cash_to_bank_transfers as t', 't.id', '=', 'i.cash_to_bank_transfer_id')
                        ->whereRaw("i.fund_receipt_id = {$vigente}")
                        ->where('t.status', '<>', CashTransferStatus::Cancelled->value))
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('fund_receipts as r')
                        ->whereRaw("r.id = {$vigente}")
                        ->where('r.cheque_status', ChequeStatus::Rejected->value)),
                FineCollectionPlace::InTransit => $enTraslado($aplicacion, CashTransferStatus::Deposited->value),
                FineCollectionPlace::InBank => $enTraslado($aplicacion, CashTransferStatus::BankConfirmed->value)
                    ->whereNotExists(fn ($e) => $e->selectRaw('1')
                        ->from('fund_receipts as r')
                        ->whereRaw("r.id = {$vigente}")
                        ->where('r.cheque_status', ChequeStatus::Rejected->value)),
                FineCollectionPlace::Rejected => $aplicacion->whereExists(fn ($e) => $e->selectRaw('1')
                    ->from('fund_receipts as r')
                    ->whereRaw("r.id = {$vigente}")
                    ->where('r.cheque_status', ChequeStatus::Rejected->value)),
            });
    }

    /**
     * Los cobros con cheque que tienen un rechazo con algo pendiente: la
     * regularización de la empresa, un hecho del banco, o el aviso y la
     * disposición del papel. Una incidencia con reemplazo y débito pendiente
     * sigue acá (DER físico §6.6).
     *
     * @return Builder<FinePayment>
     */
    public function paymentsWithPendingRejections(): Builder
    {
        return FinePayment::query()
            ->where('status', FinePaymentStatus::Applied)
            ->where('medium', 'cheque')
            ->whereHas('application', fn (Builder $aplicacion) => $aplicacion->whereExists(fn ($e) => $e->selectRaw('1')
                ->from('cheque_rejections as c')
                ->whereRaw('fund_receipt_root(c.fund_receipt_id) = fine_payment_applications.fund_receipt_id')
                ->where(fn ($pendiente) => $pendiente
                    ->whereNull('c.replacement_fund_receipt_id')
                    ->orWhereIn('c.bank_status', ['unverified', 'debit_pending'])
                    ->orWhereNull('c.company_notified_on')
                    ->orWhereNull('c.disposition'))));
    }

    /**
     * Dónde está la plata de cada cobro de caja: su instrumento vigente, su
     * depósito, los cancelados y los rechazos de la cadena.
     *
     * @param  iterable<FinePayment>  $pagos  con `application` cargada
     * @return array<int, FineDepositData> por id del pago; solo los cobros de caja aplicados
     */
    public function forPayments(iterable $pagos): array
    {
        $raizDe = [];

        foreach ($pagos as $pago) {
            if ($pago->status === FinePaymentStatus::Applied && $pago->medium->isCounter() && $pago->application !== null) {
                $raizDe[$pago->id] = $pago->application->fund_receipt_id;
            }
        }

        if ($raizDe === []) {
            return [];
        }

        $raices = array_values(array_unique($raizDe));
        $vigenteDe = DB::table('fund_receipts')
            ->whereIn('id', $raices)
            ->selectRaw('id, fund_receipt_tip(id) AS tip')
            ->pluck('tip', 'id');
        $vigentes = FundReceipt::query()->whereIn('id', $vigenteDe->values()->all())->get()->keyBy('id');

        $traslados = CashToBankTransfer::query()
            ->join('cash_to_bank_transfer_items as i', 'i.cash_to_bank_transfer_id', '=', 'cash_to_bank_transfers.id')
            ->whereIn('i.fund_receipt_id', $vigenteDe->values()->all())
            ->orderBy('cash_to_bank_transfers.id')
            ->get(['cash_to_bank_transfers.*', 'i.fund_receipt_id'])
            ->groupBy('fund_receipt_id');

        $vivos = $traslados->flatten()->filter(
            fn (CashToBankTransfer $t): bool => $t->status !== CashTransferStatus::Cancelled,
        );

        // El día del crédito en el extracto, por su imputación.
        $acreditados = DB::table('bank_transaction_allocations as a')
            ->join('bank_transactions as m', 'm.id', '=', 'a.bank_transaction_id')
            ->whereIn('a.financial_event_id', $vivos->pluck('credit_event_id')->filter()->values()->all())
            ->whereNull('a.reversal_of_id')
            ->pluck('m.transaction_date', 'a.financial_event_id');

        $tickets = $this->adjuntos->for(AttachmentSubject::CashTransfer, array_values($vivos->pluck('id')->all()));
        $rechazos = $this->rejectionsOf($raices);

        $resultado = [];

        foreach ($raizDe as $pagoId => $raiz) {
            $actual = $vigentes->get((int) $vigenteDe[$raiz]);
            $suyos = $traslados->get((int) $vigenteDe[$raiz], collect());
            $vivo = $suyos->first(fn (CashToBankTransfer $t): bool => $t->status !== CashTransferStatus::Cancelled);
            $rechazado = $actual?->cheque_status === ChequeStatus::Rejected;
            $cadena = $rechazos[$raiz] ?? [];

            $resultado[$pagoId] = new FineDepositData(
                place: match (true) {
                    $rechazado => FineCollectionPlace::Rejected,
                    $vivo?->status === CashTransferStatus::Deposited => FineCollectionPlace::InTransit,
                    $vivo?->status === CashTransferStatus::BankConfirmed => FineCollectionPlace::InBank,
                    default => FineCollectionPlace::InCustody,
                },
                transferId: $rechazado ? null : $vivo?->id,
                depositDate: $rechazado ? null : $vivo?->deposit_date->toDateString(),
                operationNumber: $rechazado ? null : $vivo?->deposit_operation_number,
                creditedOn: ! $rechazado && $vivo?->credit_event_id !== null && isset($acreditados[$vivo->credit_event_id])
                    ? (string) $acreditados[$vivo->credit_event_id]
                    : null,
                ticket: $rechazado || $vivo === null ? null : ($tickets[$vivo->id][0] ?? null),
                cancelled: array_values($suyos
                    ->filter(fn (CashToBankTransfer $t): bool => $t->status === CashTransferStatus::Cancelled)
                    ->map(fn (CashToBankTransfer $t): FineCancelledDepositData => new FineCancelledDepositData(
                        transferId: $t->id,
                        depositDate: $t->deposit_date->toDateString(),
                        reason: $t->notes,
                    ))
                    ->all()),
                chequeNumber: $actual?->cheque_number,
                chequeBank: $actual?->cheque_bank,
                openRejectionId: $rechazado
                    ? collect($cadena)->first(fn (FineChequeRejectionData $r): bool => ! $r->regularized)?->id
                    : null,
                rejections: $cadena,
            );
        }

        return $resultado;
    }

    /**
     * Los rechazos de la cadena de cada raíz, del primero al último.
     *
     * @param  list<int>  $raices
     * @return array<int, list<FineChequeRejectionData>> por id de la raíz
     */
    private function rejectionsOf(array $raices): array
    {
        $rechazos = ChequeRejection::query()
            ->with(['fundReceipt', 'replacement', 'transfer', 'registrar:id,name'])
            ->selectRaw('cheque_rejections.*, fund_receipt_root(fund_receipt_id) AS raiz')
            ->whereRaw('fund_receipt_root(fund_receipt_id) = ANY(?::bigint[])', ['{'.implode(',', $raices).'}'])
            ->orderBy('id')
            ->get();

        if ($rechazos->isEmpty()) {
            return [];
        }

        $movimientos = DB::table('bank_transactions')
            ->whereIn('id', $rechazos->pluck('credit_bank_transaction_id')->merge($rechazos->pluck('debit_bank_transaction_id'))->filter()->values()->all())
            ->pluck('transaction_date', 'id');
        $adjuntos = $this->adjuntos->for(AttachmentSubject::ChequeRejection, array_values($rechazos->modelKeys()));

        $porRaiz = [];

        foreach ($rechazos as $rechazo) {
            $autor = $rechazo->registrar;

            $porRaiz[(int) $rechazo->getAttribute('raiz')][] = new FineChequeRejectionData(
                id: $rechazo->id,
                chequeNumber: (string) $rechazo->fundReceipt->cheque_number,
                chequeBank: $rechazo->fundReceipt->cheque_bank,
                rejectedOn: $rechazo->rejected_on->toDateString(),
                reason: $rechazo->reason,
                registeredByName: $autor instanceof User ? $autor->name : null,
                bankStatus: $rechazo->bank_status,
                depositDate: $rechazo->transfer?->deposit_date->toDateString(),
                creditOn: $rechazo->credit_bank_transaction_id === null ? null : (string) ($movimientos[$rechazo->credit_bank_transaction_id] ?? ''),
                debitOn: $rechazo->debit_bank_transaction_id === null ? null : (string) ($movimientos[$rechazo->debit_bank_transaction_id] ?? ''),
                neverCreditedNote: $rechazo->never_credited_note,
                companyNotifiedOn: $rechazo->company_notified_on?->toDateString(),
                disposition: $rechazo->disposition,
                regularized: ! $rechazo->isOpen(),
                replacementChequeNumber: $rechazo->replacement?->cheque_number,
                attachments: $adjuntos[$rechazo->id] ?? [],
            );
        }

        return $porRaiz;
    }

    private function tip(int $recepcion): int
    {
        return (int) DB::scalar('SELECT fund_receipt_tip(?)', [$recepcion]);
    }

    private function paymentOfReceipt(int $recepcion): ?FinePayment
    {
        $raiz = (int) DB::scalar('SELECT fund_receipt_root(?)', [$recepcion]);

        return FinePayment::query()
            ->whereHas('application', fn (Builder $q) => $q->where('fund_receipt_id', $raiz))
            ->first();
    }
}
