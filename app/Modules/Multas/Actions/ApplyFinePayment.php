<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\CashBoxBankAccounts;
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
use App\Modules\Multas\Support\FinePaymentTimeliness;
use App\Modules\Multas\Support\FineReceiptSeries;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\DocumentSeries;
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
        private readonly CashBoxBankAccounts $cuentas,
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

        ['aplicado' => $aplicado, 'excedente' => $excedente] = $this->reparto($pago->amount, $antes->debt);

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
            'term_ends_on' => $termino['termEndsOn']?->toDateString(),
            'applied_by' => $actorId,
        ]);

        $despues = FineBalance::query()->whereKey($multa->id)->firstOrFail();
        $papel = $this->componer($multa, $pago, $antes, $despues->obligation, $despues->debt, $fecha, $talonarioNumber, $signedById, $recepcion);
        $numero = $this->numerar->handle(FineReceiptSeries::CODE);

        $recibo = Receipt::query()->create([
            ...$papel['receipt'],
            'document_series_id' => $numero['series_id'],
            'number' => $numero['number'],
            'formatted_number' => $numero['formatted'],
            'status' => ReceiptStatus::Issued,
            'issued_by' => $actorId,
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
            'is_cancellation' => $papel['cancela'],
            'snapshot' => $papel['snapshot'],
        ]);
    }

    /**
     * El recibo sin emitir, para mirarlo antes de cobrar.
     *
     * **Es el mismo armado que la emisión**, como en Haberes: una vista
     * previa que compone los datos por su cuenta termina mostrando algo
     * distinto de lo que se imprime. No escribe nada: el pago llega sin
     * guardar y el saldo posterior se calcula en vez de leerse.
     *
     * El número es el que le tocaría, como anticipo y no como promesa: el
     * correlativo real se toma bajo lock recién al emitir.
     */
    public function preview(
        FinePayment $pago,
        CarbonImmutable $fecha,
        ?string $talonarioNumber,
        ?int $signedById,
    ): FinePaymentReceipt {
        /** @var Fine $multa */
        $multa = $pago->fine()->with('fineCase')->firstOrFail();
        $antes = FineBalance::query()->whereKey($multa->id)->firstOrFail();
        ['aplicado' => $aplicado] = $this->reparto($pago->amount, $antes->debt);

        $papel = $this->componer(
            $multa, $pago, $antes, $antes->obligation, Decimal::sub($antes->debt, $aplicado), $fecha, $talonarioNumber, $signedById,
        );
        $serie = DocumentSeries::activeForCode(FineReceiptSeries::CODE);

        $recibo = new Receipt([
            ...$papel['receipt'],
            'formatted_number' => $serie === null ? '—' : $serie->formatNumber($serie->next_number),
            'status' => ReceiptStatus::Issued,
        ]);

        return (new FinePaymentReceipt([
            'is_cancellation' => $papel['cancela'],
            'snapshot' => $papel['snapshot'],
        ]))->setRelation('receipt', $recibo)->setRelation('payment', $pago);
    }

    /**
     * Cuánto del pago cubre deuda y cuánto la excede: lo que la excede es
     * excedente, no queda sin aplicar (M05).
     *
     * @return array{aplicado: string, excedente: string}
     */
    private function reparto(string $importe, string $deuda): array
    {
        $sobrante = Decimal::sub($importe, $deuda);
        $excedente = Decimal::isNegative($sobrante) ? '0.00' : $sobrante;

        return ['aplicado' => Decimal::sub($importe, $excedente), 'excedente' => $excedente];
    }

    /**
     * Lo que va a decir el papel.
     *
     * Una sola definición para la emisión y para la vista previa: es lo que
     * garantiza que lo que se mira sea lo que se imprime. Lo que no compone
     * es lo que solo existe al emitir: el número y quién lo emitió.
     *
     * @return array{receipt: array<string, mixed>, cancela: bool, snapshot: array<string, mixed>}
     */
    private function componer(
        Fine $multa,
        FinePayment $pago,
        FineBalance $antes,
        string $obligacion,
        string $deudaDespues,
        CarbonImmutable $fecha,
        ?string $talonarioNumber,
        ?int $signedById,
        ?FundReceipt $recepcion = null,
    ): array {
        ['aplicado' => $aplicado, 'excedente' => $excedente] = $this->reparto($pago->amount, $antes->debt);
        $cancela = Decimal::equals($deudaDespues, '0');
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
        $firmante = $signedById === null ? null : User::query()->find($signedById);

        return [
            'receipt' => [
                'cash_box_id' => (int) CashBox::query()->where('code', CashBox::MULTAS)->valueOrFail('id'),
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
                'issue_mode' => $talonarioNumber === null ? ReceiptIssueMode::Online : ReceiptIssueMode::OfflineTalonario,
                'signed_by' => $firmante?->id,
                'signed_by_name_snapshot' => $firmante?->name,
                'signed_by_title_snapshot' => $firmante?->position,
            ],
            'cancela' => $cancela,
            'snapshot' => [
                'caption' => $multa->caption,
                'caseLabel' => $multa->fineCase->label(),
                'resolution' => $resolucion,
                'concept' => $concepto,
                'judicialReference' => $pago->judicial_reference,
                'obligation' => $obligacion,
                'paidBefore' => Decimal::add($antes->operational_paid, $antes->historical_paid),
                'applied' => $aplicado,
                'excess' => $excedente,
                'debtAfter' => $deudaDespues,
                'previousPayments' => $cancela ? $this->anteriores($multa, $pago) : [],
                /*
                 * La cuenta que el formulario imprime en «Depósito Cta.», como
                 * estaba al emitir: si después se corrige el maestro, la
                 * reimpresión no puede decir otra cosa que el original.
                 */
                'bankAccountNumber' => $this->cuenta($pago, $recepcion, $multa, $fecha),
            ],
        ];
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
            // En la vista previa el pago no está guardado: no hay propio que excluir.
            ->when($actual->exists, fn ($consulta) => $consulta->whereKeyNot($actual->id))
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

    /**
     * El número de cuenta de la casilla «Depósito Cta.».
     *
     * - **Por el banco:** la cuenta del crédito que respalda el pago.
     * - **En la caja:** la cuenta de la caja solo si hay una única vigente
     *   ese día. Con varias, todavía no se sabe dónde se va a depositar, y
     *   el recibo no lo afirma: el depósito guarda su propia cuenta.
     *
     * Queda congelado con el recibo: cambiar la predeterminada después no
     * lo toca.
     */
    private function cuenta(FinePayment $pago, ?FundReceipt $recepcion, Fine $multa, CarbonImmutable $fecha): ?string
    {
        if (! $pago->medium->isCounter() && $recepcion !== null) {
            $cuenta = BankTransactionAllocation::query()
                ->where('financial_event_id', $recepcion->financial_event_id)
                ->whereNull('reversal_of_id')
                ->join('bank_transactions', 'bank_transactions.id', '=', 'bank_transaction_allocations.bank_transaction_id')
                ->value('bank_transactions.bank_account_id');
        } else {
            $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');
            $vigentes = $this->cuentas->activeOn($caja, Currency::from($multa->currency), $fecha);
            $cuenta = $vigentes->count() === 1 ? $vigentes->first()?->bank_account_id : null;
        }

        return $cuenta === null ? null : BankAccount::query()->whereKey($cuenta)->value('account_number');
    }
}
