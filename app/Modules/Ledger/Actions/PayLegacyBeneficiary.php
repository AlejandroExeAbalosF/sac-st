<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paga un haber que entró antes de que el sistema existiera.
 *
 * **Es la única salida de `LEGACY_FUNDS`**, y sin ella esa cuenta sube y
 * no baja jamás: la apertura la acredita con todo el saldo que ya estaba en
 * el cajón, y hasta acá nada la debitaba. En la práctica eso significaba
 * que los expedientes anteriores al arranque no se podían pagar desde el
 * sistema y el área tenía que seguir con la planilla en paralelo —
 * exactamente lo que la apertura vino a evitar.
 *
 * ```text
 * Débito   LEGACY_FUNDS      baja lo que se debía del sistema anterior
 * Crédito  el origen         y sale de donde estaba
 * ```
 *
 * **El origen son los tres saldos de la planilla**, porque los tres pueden
 * traer plata vieja: el efectivo del cajón, un cheque en custodia que se
 * entrega como cheque, y los «DEPOSITOS DIRECTOS» —lo que las empresas
 * depositaron derecho en la cuenta de la Secretaría y todavía no se
 * transfirió a su beneficiario—. Ese último se paga por transferencia y
 * exige decir de qué cuenta sale: `journal_lines` es append-only y una
 * línea bancaria sin cuenta no se corrige después, se revierte.
 *
 * Es el espejo del asiento de apertura, y por eso `LEGACY_FUNDS` termina en
 * cero el día que se pagó el último caso viejo. Ahí se apaga la operación
 * en paralelo, que es todo el objetivo del §12 del DER.
 *
 * ─── Qué lo distingue de un egreso normal ────────────────────────────────
 *
 * No hay expediente, ni haber, ni cuota, ni Orden de Pago: nada de eso
 * existe en el sistema para estos casos. Lo único que ata el pago a la
 * realidad es **la referencia al registro manual**, y por eso es
 * obligatoria. Un pago de esta cuenta sin decir a qué expediente del
 * sistema anterior corresponde sería plata saliendo sin respaldo.
 *
 * El recibo de egreso **sí se emite**, con su número de la serie 0020 y sin
 * cuota. El trigger que exige egreso confirmado no lo alcanza: su guarda es
 * sobre `beneficiary_installment_id IS NOT NULL`, y la migración que lo
 * creó ya anticipó este caso.
 */
final class PayLegacyBeneficiary
{
    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
        private readonly TakeNextDocumentNumber $numerar,
        private readonly CashBalance $saldos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        int $cashBoxId,
        Person $beneficiary,
        string $amount,
        string $legacyReference,
        CarbonInterface $paymentDate,
        PaymentMedium $medium = PaymentMedium::Cash,
        Currency $currency = Currency::Ars,
        ?int $bankAccountId = null,
        ?int $actorId = null,
        ?string $talonarioNumber = null,
        bool $printsTalonarioNumber = false,
        ?string $notes = null,
    ): Receipt {
        $importe = Decimal::scale($amount);
        $referencia = trim($legacyReference);
        $origen = $this->accountFor($medium);

        $this->assertPayable($cashBoxId, $importe, $referencia, $origen, $currency, $bankAccountId);

        return DB::transaction(function () use (
            $cashBoxId, $beneficiary, $importe, $referencia, $paymentDate,
            $medium, $origen, $currency, $bankAccountId, $actorId, $talonarioNumber,
            $printsTalonarioNumber, $notes
        ): Receipt {
            $evento = $this->postJournalEntry->handle(
                type: FinancialEventType::LegacyDisbursement,
                /*
                 * La clave lleva la referencia manual y el beneficiario: dos
                 * pagos del mismo expediente viejo a la misma persona el
                 * mismo día son casi con seguridad un doble clic, y ese es
                 * el escenario que la idempotencia existe para atajar.
                 */
                idempotencyKey: sprintf(
                    'legacy-disbursement:%d:%d:%s:%s',
                    $cashBoxId,
                    $beneficiary->id,
                    $paymentDate->format('Y-m-d'),
                    mb_substr($referencia, 0, 40),
                ),
                lines: [
                    EntryLine::debit(LedgerAccount::LegacyFunds, $importe)
                        ->in($currency)
                        ->onCashBox($cashBoxId)
                        ->describedAs('Haber del sistema anterior'),
                    EntryLine::credit($origen, $importe)
                        ->in($currency)
                        ->onCashBox($cashBoxId)
                        ->onBankAccount($bankAccountId)
                        ->describedAs('Entregado al beneficiario'),
                ],
                date: $paymentDate,
                cashBoxId: $cashBoxId,
                description: $notes ?? "Pago de haber anterior · {$referencia}",
                actorId: $actorId,
            );

            $serie = ReceiptType::Expense->seriesCode();
            $numero = $this->numerar->handle($serie);

            $recibo = Receipt::query()->create([
                'document_series_id' => DocumentSeries::query()->where('code', $serie)->value('id'),
                'number' => $numero['number'],
                'formatted_number' => $numero['formatted'],
                'talonario_number' => $talonarioNumber,
                'prints_talonario_number' => $printsTalonarioNumber,
                'receipt_type' => ReceiptType::Expense,
                'person_id' => $beneficiary->id,
                /*
                 * Sin cuota, porque no existe: es lo que distingue a este
                 * comprobante y lo que hace que el trigger del invariante 13
                 * no lo alcance.
                 */
                'beneficiary_installment_id' => null,
                'concept_snapshot' => 'Haber del sistema anterior',
                'medium_snapshot' => $medium->value,
                'beneficiary_name_snapshot' => $beneficiary->name,
                'beneficiary_document_snapshot' => $beneficiary->document,
                /*
                 * La referencia al registro manual va donde iría el número
                 * de expediente, que es exactamente lo que es: el
                 * identificador del caso en el sistema que este reemplaza.
                 */
                'expediente_number_snapshot' => $referencia,
                'amount' => $importe,
                'issue_date' => $paymentDate,
                'status' => ReceiptStatus::Issued,
                'issue_mode' => $talonarioNumber === null
                    ? ReceiptIssueMode::Online
                    : ReceiptIssueMode::OfflineTalonario,
                'issued_by' => $actorId,
                'recorded_at' => $talonarioNumber === null ? null : now(),
                'recorded_by' => $talonarioNumber === null ? null : $actorId,
            ]);

            ReceiptFinancialEvent::query()->firstOrCreate([
                'receipt_id' => $recibo->id,
                'financial_event_id' => $evento->id,
            ]);

            $this->auditar->handle('caja.haber-anterior-pagado', $recibo, after: [
                'formatted_number' => $recibo->formatted_number,
                'amount' => $importe,
                'beneficiary' => $beneficiary->name,
            ], metadata: ['referencia' => $referencia], actorId: $actorId);

            return $recibo;
        });
    }

    /**
     * Con qué se paga, y de dónde sale.
     *
     * El cheque en custodia se entrega **como cheque** (§2.5.5): el papel
     * cambia de manos. Convertirlo a efectivo inventaría un movimiento de
     * caja que no ocurrió.
     */
    private function accountFor(PaymentMedium $medium): LedgerAccount
    {
        return match ($medium) {
            PaymentMedium::Cheque => LedgerAccount::ChequesInCustody,
            PaymentMedium::Bank => LedgerAccount::BankAccount,
            default => LedgerAccount::CashOnHand,
        };
    }

    /**
     * @param  numeric-string  $amount
     *
     * @throws ValidationException
     */
    private function assertPayable(
        int $cashBoxId,
        string $amount,
        string $reference,
        LedgerAccount $origen,
        Currency $currency,
        ?int $bankAccountId,
    ): void {
        if (Decimal::isNegative($amount) || Decimal::equals($amount, '0')) {
            throw ValidationException::withMessages([
                'amount' => 'Un pago tiene que mover dinero.',
            ]);
        }

        /*
         * Una línea de `BANK_ACCOUNT` sin cuenta no dice de dónde salió el
         * dinero, y el libro no admite corregirla: es append-only. Se
         * exige acá y no en el FormRequest porque el Action tiene que
         * poder invocarse desde un seeder o una consola sin perder la
         * regla.
         */
        if ($origen === LedgerAccount::BankAccount && $bankAccountId === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => 'Un pago por transferencia tiene que decir de qué cuenta bancaria sale.',
            ]);
        }

        if ($reference === '') {
            throw ValidationException::withMessages([
                'legacyReference' => 'Sin la referencia al registro manual, este pago sale sin respaldo: '
                    .'es lo único que lo ata al expediente del sistema anterior.',
            ]);
        }

        /*
         * No se puede pagar del sistema anterior más de lo que se declaró
         * al abrir los libros. Si el saldo no alcanza, o la apertura quedó
         * corta o este pago no corresponde a esta cuenta.
         */
        $pendiente = $this->saldos->of(LedgerAccount::LegacyFunds, $cashBoxId, $currency);

        if (bccomp($amount, $pendiente, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'Del sistema anterior queda por pagar %s y este pago es de %s.',
                    Decimal::format($pendiente),
                    Decimal::format($amount),
                ),
            ]);
        }

        /*
         * Y tampoco se entrega lo que no está. El cajón guarda plata de
         * los dos circuitos —la vieja y la nueva—, así que este control es
         * sobre el total disponible, no sobre la parte que le toca al
         * sistema anterior.
         */
        $disponible = $this->saldos->of($origen, $cashBoxId, $currency);

        if (bccomp($amount, $disponible, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => sprintf(
                    'En «%s» hay %s y este pago es de %s.',
                    $origen->label(),
                    Decimal::format($disponible),
                    Decimal::format($amount),
                ),
            ]);
        }
    }
}
