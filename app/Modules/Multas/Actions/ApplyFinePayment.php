<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentApplication;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FinePaymentTimeliness;
use App\Modules\Multas\Support\FineReceiptSeries;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplica a la multa un pago que ya tiene su dinero recibido, y emite su
 * recibo (M02, M05, M09).
 *
 * La recepción entra entera: lo que cubre la deuda pasa a recaudación y lo
 * que la excede a excedente pendiente, en un solo asiento. El término se
 * juzga en este momento y queda guardado. El recibo de Multas toma su número
 * de la serie de la caja y congela lo que decía la multa: si la deja en cero,
 * es el cancelatorio y lista los pagos anteriores.
 *
 * Se llama dentro de la transacción de quien recibió el dinero, con la multa
 * bloqueada y el pago ya marcado como aplicado.
 */
final class ApplyFinePayment
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly TakeNextDocumentNumber $numerar,
        private readonly FinePaymentTimeliness $termino,
        private readonly FineBankCredits $banco,
    ) {}

    public function handle(
        FinePayment $pago,
        FundReceipt $recepcion,
        CarbonImmutable $fecha,
        ?string $talonarioNumber,
        ?int $signedById,
        ?int $actorId,
    ): FinePaymentReceipt {
        if (DB::transactionLevel() === 0 || $pago->status !== FinePaymentStatus::Applied) {
            throw new RuntimeException('Se aplica un pago ya marcado como aplicado, en la transacción que recibió su dinero.');
        }

        /** @var Fine $multa */
        $multa = $pago->fine()->with('fineCase')->firstOrFail();
        $antes = FineBalance::query()->whereKey($multa->id)->firstOrFail();

        // Lo que excede la deuda es excedente, no queda sin aplicar (M05).
        $sobrante = Decimal::sub($pago->amount, $antes->debt);
        $excedente = Decimal::isNegative($sobrante) ? '0.00' : $sobrante;
        $aplicado = Decimal::sub($pago->amount, $excedente);

        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id');
        $moneda = Currency::from($multa->currency);

        $lineas = [EntryLine::debit(LedgerAccount::UnassignedFunds, $pago->amount)->in($moneda)->onCashBox($caja)];
        if (! Decimal::equals($aplicado, '0')) {
            $lineas[] = EntryLine::credit(LedgerAccount::CollectedFunds, $aplicado)->in($moneda)->onCashBox($caja);
        }
        if (! Decimal::equals($excedente, '0')) {
            $lineas[] = EntryLine::credit(LedgerAccount::PendingExcess, $excedente)->in($moneda)->onCashBox($caja);
        }

        $evento = $this->asentar->handle(
            type: FinancialEventType::FundsApplied,
            idempotencyKey: $pago->idempotency_key.':aplicacion',
            lines: $lineas,
            date: $fecha,
            cashBoxId: $caja,
            description: 'Pago de la multa '.$multa->caption,
            actorId: $actorId,
        );

        $termino = $this->termino->judge($multa, $pago->presented_at, $pago->presented_precision);

        FinePaymentApplication::query()->create([
            'fine_payment_id' => $pago->id,
            'fine_id' => $multa->id,
            'fund_receipt_id' => $recepcion->id,
            'application_event_id' => $evento->id,
            'amount' => $pago->amount,
            'applied_amount' => $aplicado,
            'excess_amount' => $excedente,
            'timeliness' => $termino['timeliness'],
            'origin_closing_evidence_id' => $termino['evidenceId'],
            'origin_closed_at' => $termino['closedAt'],
            'applied_by' => $actorId,
        ]);

        $despues = FineBalance::query()->whereKey($multa->id)->firstOrFail();
        $cancela = Decimal::equals($despues->debt, '0');
        /*
         * La resolución que impuso la multa, que es única. Sin filtrar por
         * tipo, «el primer documento de origen» podía ser un acta —también
         * son de origen— y el recibo decía «por Acta HYS …».
         */
        $resolucion = $multa->documents()
            ->where('kind', FineDocumentKind::Resolution)
            ->wherePivot('role', FineDocumentRole::Origin->value)
            ->first()?->label();
        $concepto = ($cancela ? 'Pago cancelatorio de la multa' : 'Pago parcial de la multa')
            .($resolucion === null ? '' : ' por '.$resolucion);

        $numero = $this->numerar->handle(FineReceiptSeries::CODE);
        $firmante = $signedById === null ? null : User::query()->find($signedById);

        $recibo = Receipt::query()->create([
            'document_series_id' => $numero['series_id'],
            'cash_box_id' => $caja,
            'number' => $numero['number'],
            'formatted_number' => $numero['formatted'],
            'talonario_number' => $talonarioNumber,
            'prints_talonario_number' => false,
            'receipt_type' => ReceiptType::Income,
            'person_id' => $pago->payer_person_id,
            'concept_snapshot' => mb_substr($concepto, 0, 300),
            'medium_snapshot' => match ($pago->medium) {
                FinePaymentMedium::Cash => 'cash',
                FinePaymentMedium::Cheque => 'cheque',
                default => 'bank',
            },
            'counterparty_name_snapshot' => mb_substr($pago->payer_name, 0, 200),
            'expediente_number_snapshot' => mb_substr($multa->fineCase->label(), 0, 40),
            'amount' => $pago->amount,
            'issue_date' => $fecha->toDateString(),
            'status' => ReceiptStatus::Issued,
            'issue_mode' => $talonarioNumber === null ? ReceiptIssueMode::Online : ReceiptIssueMode::OfflineTalonario,
            'issued_by' => $actorId,
            'signed_by' => $firmante?->id,
            'signed_by_name_snapshot' => $firmante?->name,
            'signed_by_title_snapshot' => $firmante?->position,
            'recorded_at' => $talonarioNumber === null ? null : now(),
            'recorded_by' => $talonarioNumber === null ? null : $actorId,
        ]);

        // El recibo documenta los dos hechos: el dinero que entró y su aplicación.
        foreach ([$recepcion->financial_event_id, $evento->id] as $eventoId) {
            ReceiptFinancialEvent::query()->create(['receipt_id' => $recibo->id, 'financial_event_id' => $eventoId]);
        }

        return FinePaymentReceipt::query()->create([
            'fine_payment_id' => $pago->id,
            'receipt_id' => $recibo->id,
            'is_cancellation' => $cancela,
            'snapshot' => [
                'caption' => $multa->caption,
                'caseLabel' => $multa->fineCase->label(),
                'resolution' => $resolucion,
                'concept' => $concepto,
                'judicialReference' => $pago->judicial_reference,
                'obligation' => $despues->obligation,
                'paidBefore' => Decimal::add($antes->operational_paid, $antes->historical_paid),
                'applied' => $aplicado,
                'excess' => $excedente,
                'debtAfter' => $despues->debt,
                'previousPayments' => $cancela ? $this->anteriores($multa, $pago) : [],
                /*
                 * La cuenta que el formulario imprime en «Depósito Cta.», como
                 * estaba al emitir: si después se corrige el maestro, la
                 * reimpresión no puede decir otra cosa que el original.
                 */
                'bankAccountNumber' => $this->cuenta(),
            ],
        ]);
    }

    /**
     * Los pagos vigentes anteriores, para el cancelatorio: los del sistema con
     * su recibo y los anteriores al sistema ya validados.
     *
     * La fecha va con su precisión: un antecedente que solo se conoce por el
     * mes no se imprime con un día que nadie informó.
     *
     * @return list<array{date: string|null, precision: string, amount: string, receipt: string|null, evidence: string|null}>
     */
    private function anteriores(Fine $multa, FinePayment $actual): array
    {
        $delSistema = FinePayment::query()
            ->where('fine_id', $multa->id)
            ->where('status', FinePaymentStatus::Applied)
            ->whereKeyNot($actual->id)
            ->with('paymentReceipt.receipt')
            ->orderBy('id')
            ->get()
            ->map(fn (FinePayment $pago): array => [
                'date' => $pago->paymentReceipt?->receipt->issue_date->toDateString(),
                'precision' => DatePrecision::Day->value,
                'amount' => $pago->amount,
                'receipt' => $pago->paymentReceipt?->receipt->talonario_number ?? $pago->paymentReceipt?->receipt->formatted_number,
                'evidence' => null,
            ]);

        $previos = FineHistoricalMovement::query()
            ->where('fine_id', $multa->id)
            ->where('status', HistoricalMovementStatus::Validated)
            ->where('kind', HistoricalMovementKind::Payment)
            ->orderBy('occurred_on')
            ->get()
            ->map(fn (FineHistoricalMovement $antecedente): array => [
                'date' => $antecedente->occurred_on?->toDateString(),
                'precision' => $antecedente->occurred_precision->value,
                'amount' => $antecedente->amount,
                'receipt' => null,
                'evidence' => $antecedente->evidence_text,
            ]);

        return [...$previos->all(), ...$delSistema->all()];
    }

    private function cuenta(): ?string
    {
        $cuenta = $this->banco->book()?->bank_account_id;

        return $cuenta === null ? null : BankAccount::query()->whereKey($cuenta)->value('account_number');
    }
}
