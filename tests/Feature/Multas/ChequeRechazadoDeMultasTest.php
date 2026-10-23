<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El cheque rechazado y su reemplazo (M03, DER físico de Multas §6.6): el
 * pago y su recibo se conservan, la multa muestra aparte lo pendiente de
 * regularizar, y el banco se sigue con un asiento por hecho real.
 */
class ChequeRechazadoDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── El circuito ─────────────────────────────────────────────────────

    /**
     * La aceptación del plan: multa de 100.000 y cheque de 20.000 rechazado
     * dan saldo 80.000 y regularización 20.000; el reemplazo cierra la
     * incidencia sin acreditar el banco; un segundo rechazo la reabre.
     */
    public function test_a_rejected_cheque_keeps_the_payment_and_shows_what_is_pending(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $recibo = $pago->paymentReceipt()->value('receipt_id');

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        expect($this->saldo($multa, 'debt'))->toBe('80000.00')
            ->and($this->saldo($multa, 'pending_regularization'))->toBe('20000.00')
            ->and($rechazo->bank_status)->toBe(ChequeRejectionBankStatus::NotApplicable)
            ->and($rechazo->fundReceipt->cheque_status)->toBe(ChequeStatus::Rejected)
            ->and($pago->refresh()->paymentReceipt()->value('receipt_id'))->toBe($recibo)
            ->and($this->libro())->toMatchArray(['CHEQUES_IN_CUSTODY' => '0.00', 'REJECTED_CHEQUES' => '20000.00']);

        $this->actingAs($this->operador())
            ->post(route('multas.rechazos.reemplazo', $rechazo), $this->reemplazo('00099999'))
            ->assertSessionHasNoErrors();

        $reemplazo = FundReceipt::query()->where('origin', FundReceiptOrigin::Replacement)->sole();

        expect($this->saldo($multa, 'pending_regularization'))->toBe('0.00')
            ->and($this->saldo($multa, 'debt'))->toBe('80000.00')
            ->and($reemplazo->replaces_fund_receipt_id)->toBe($rechazo->fund_receipt_id)
            ->and($reemplazo->cheque_status)->toBe(ChequeStatus::InCustody)
            ->and($this->libro())->toMatchArray(['CHEQUES_IN_CUSTODY' => '20000.00', 'REJECTED_CHEQUES' => '0.00'])
            ->and(FinePayment::query()->count())->toBe(1);

        // El reemplazo también se rechaza: el circuito se repite sobre él.
        $this->rechazar($pago)->assertSessionHasNoErrors();

        expect(ChequeRejection::query()->count())->toBe(2)
            ->and(ChequeRejection::query()->latest('id')->firstOrFail()->fund_receipt_id)->toBe($reemplazo->id)
            ->and($this->saldo($multa, 'pending_regularization'))->toBe('20000.00');

        $this->get(route('multas.show', $multa))->assertInertia(fn (Assert $page) => $page
            ->where('multa.balance.pendingRegularization', '20000.00')
            ->where('multa.payments.0.deposit.place', 'rejected')
            ->has('multa.payments.0.deposit.rejections', 2)
            ->where('multa.payments.0.deposit.rejections.0.replacementChequeNumber', '00099999'));

        expect(AuditEvent::query()->where('action', 'multa.cheque-rechazado')->count())->toBe(2)
            ->and(AuditEvent::query()->where('action', 'multa.cheque-reemplazado')->count())->toBe(1);
    }

    /** Con excedente: la regularización es por el cheque entero, una sola vez por cadena. */
    public function test_the_whole_cheque_is_pending_even_with_an_excess(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '120000.00');

        $this->rechazar($pago)->assertSessionHasNoErrors();

        expect($this->saldo($multa, 'pending_regularization'))->toBe('120000.00')
            ->and($this->saldo($multa, 'debt'))->toBe('0.00');

        $this->actingAs($this->operador())
            ->post(route('multas.rechazos.reemplazo', ChequeRejection::query()->sole()), $this->reemplazo('00011111'))
            ->assertSessionHasNoErrors();
        $this->rechazar($pago)->assertSessionHasNoErrors();

        expect($this->saldo($multa, 'pending_regularization'))->toBe('120000.00');
    }

    // ── El banco ────────────────────────────────────────────────────────

    /**
     * Depositado y sin acreditar: el traslado queda devuelto, sale el
     * tránsito y el banco queda sin verificar. Si el extracto después trae
     * el crédito y el débito, se reconocen sin retirar dos veces el tránsito.
     */
    public function test_a_bounced_deposit_is_verified_later_against_the_statement(): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $traslado = $this->depositar($pago);

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        expect($traslado->refresh()->status)->toBe(CashTransferStatus::Bounced)
            ->and($rechazo->bank_status)->toBe(ChequeRejectionBankStatus::Unverified)
            ->and($this->libro())->toMatchArray(['CASH_IN_TRANSIT' => '0.00', 'REJECTED_CHEQUES' => '20000.00']);

        // Rechazado no es cancelado: el cheque no vuelve a custodia.
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.traslados.cancel', $traslado), ['reason' => 'No corresponde', 'idempotencyKey' => (string) Str::uuid()])
            ->assertSessionHasErrors('transferId');

        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00');
        $debito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00', TransactionDirection::Debit);

        $this->post(route('multas.rechazos.movimientos.store', $rechazo), [
            'creditTransactionId' => $credito->id, 'debitTransactionId' => $debito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitRecorded)
            ->and($this->libro())->toMatchArray([
                'CASH_IN_TRANSIT' => '0.00', 'BANK_ACCOUNT' => '0.00', 'PENDING_BANK_DEBITS' => '0.00', 'REJECTED_CHEQUES' => '20000.00',
            ]);
    }

    /** Sin el extracto, no se afirma que el banco no lo acreditó. */
    public function test_never_credited_is_said_by_someone_with_the_statement(): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $traslado = $this->depositar($pago);

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.rechazos.no-acreditado', $rechazo), ['note' => 'El extracto de septiembre no lo trae'])
            ->assertSessionHasErrors('note');

        // Un archivo rechazado conserva su período, pero ninguno de sus movimientos.
        BankStatementImport::query()->create([
            'bank_account_id' => $this->laCuenta->id, 'imported_by' => $this->quienAbre(), 'original_filename' => 'roto.csv',
            'file_size' => 100, 'file_sha256' => hash('sha256', 'roto'), 'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1', 'period_from' => BusinessDate::today()->toDateString(),
            'period_to' => BusinessDate::today()->toDateString(), 'status' => 'failed', 'failure_reason' => 'Formato no reconocido',
            'rows_total' => 1, 'rows_valid' => 0, 'rows_rejected' => 1, 'rows_new' => 0, 'rows_duplicate' => 0,
        ]);

        $this->post(route('multas.rechazos.no-acreditado', $rechazo), ['note' => 'El extracto de septiembre no lo trae'])
            ->assertSessionHasErrors('note');
        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::Unverified);

        $this->importacion($this->laCuenta, BusinessDate::today()->toDateString(), BusinessDate::today()->toDateString());

        $this->post(route('multas.rechazos.no-acreditado', $rechazo), ['note' => 'El extracto de septiembre no lo trae'])
            ->assertSessionHasNoErrors();

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::NotApplicable)
            ->and($rechazo->never_credited_note)->toBe('El extracto de septiembre no lo trae')
            ->and($traslado->refresh()->status)->toBe(CashTransferStatus::Bounced);
    }

    /**
     * Acreditado y rechazado: el débito queda anunciado. En cualquier orden
     * de aviso, reemplazo y débito, `REJECTED_CHEQUES` nunca queda acreedor y
     * `PENDING_BANK_DEBITS` nunca deudor.
     *
     * @return iterable<string, array{list<string>}>
     */
    public static function ordenes(): iterable
    {
        yield 'aviso, reemplazo, débito' => [['reemplazo', 'debito']];
        yield 'aviso, débito, reemplazo' => [['debito', 'reemplazo']];
    }

    #[DataProvider('ordenes')]
    public function test_a_credited_cheque_waits_for_its_debit_in_any_order(array $orden): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $traslado = $this->depositar($pago);
        $this->acreditar($traslado);

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        expect($rechazo->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending)
            ->and($traslado->refresh()->status)->toBe(CashTransferStatus::BankConfirmed)
            ->and($this->libro())->toMatchArray(['BANK_ACCOUNT' => '20000.00', 'PENDING_BANK_DEBITS' => '-20000.00', 'REJECTED_CHEQUES' => '20000.00']);
        $this->assertSignos();

        foreach ($orden as $hecho) {
            if ($hecho === 'reemplazo') {
                $this->actingAs($this->operador())
                    ->post(route('multas.rechazos.reemplazo', $rechazo), $this->reemplazo('00055555'))
                    ->assertSessionHasNoErrors();
            } else {
                $debito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00', TransactionDirection::Debit);
                $this->actingAs($this->operador('contador'))
                    ->post(route('multas.rechazos.movimientos.store', $rechazo), [
                        'debitTransactionId' => $debito->id, 'idempotencyKey' => (string) Str::uuid(),
                    ])->assertSessionHasNoErrors();
            }

            $this->assertSignos();
        }

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitRecorded)
            ->and($rechazo->isOpen())->toBeFalse()
            ->and($this->libro())->toMatchArray([
                'BANK_ACCOUNT' => '0.00', 'PENDING_BANK_DEBITS' => '0.00', 'REJECTED_CHEQUES' => '0.00', 'CHEQUES_IN_CUSTODY' => '20000.00',
            ]);
    }

    /** El débito sin aviso previo: rechazo y débito en un mismo acto, sin otro respaldo. */
    public function test_a_debit_without_notice_records_both_facts_at_once(): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->acreditar($this->depositar($pago));
        $debito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00', TransactionDirection::Debit);

        $this->actingAs($this->operador('contador'))
            ->getJson(route('multas.pagos.rechazo.debitos', $pago))
            ->assertOk()
            ->assertJsonPath('movements.0.id', $debito->id);

        $this->rechazar($pago, sinRespaldo: true, debito: $debito)->assertSessionHasNoErrors();

        expect(ChequeRejection::query()->sole()->bank_status)->toBe(ChequeRejectionBankStatus::DebitRecorded)
            ->and($this->libro())->toMatchArray(['BANK_ACCOUNT' => '0.00', 'PENDING_BANK_DEBITS' => '0.00']);
    }

    /** Un reemplazo rechazado mientras el débito del original sigue pendiente: cada uno lleva lo suyo. */
    public function test_each_rejection_keeps_its_own_bank_follow_up(): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->acreditar($this->depositar($pago));

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $primero = ChequeRejection::query()->sole();
        $this->actingAs($this->operador())
            ->post(route('multas.rechazos.reemplazo', $primero), $this->reemplazo('00077777'))
            ->assertSessionHasNoErrors();
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $segundo = ChequeRejection::query()->latest('id')->firstOrFail();

        expect($primero->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending)
            ->and($segundo->bank_status)->toBe(ChequeRejectionBankStatus::NotApplicable)
            ->and($this->saldo($multa, 'pending_regularization'))->toBe('20000.00');
        $this->assertSignos();
    }

    /** Un débito anterior a lo que el banco hizo con el cheque no es el suyo, aunque coincidan cuenta e importe. */
    public function test_a_debit_before_the_cheque_reached_the_bank_is_not_its_debit(): void
    {
        // Cobertura del motor preparado para una etapa futura, deshabilitado por defecto.
        config(['multas.cheque_bank_followup_enabled' => true]);

        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->acreditar($this->depositar($pago));
        $ajeno = $this->movimiento($this->laCuenta, '2026-06-15', '20000.00', TransactionDirection::Debit);

        $this->actingAs($this->operador('contador'))
            ->getJson(route('multas.pagos.rechazo.debitos', $pago))
            ->assertOk()
            ->assertJsonCount(0, 'movements');

        $this->rechazar($pago, debito: $ajeno)->assertSessionHasErrors('debitTransactionId');
        expect(ChequeRejection::query()->count())->toBe(0);

        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        $this->getJson(route('multas.rechazos.movimientos.index', [$rechazo, 'sentido' => 'debit']))
            ->assertOk()
            ->assertJsonCount(0, 'movements');
        $this->post(route('multas.rechazos.movimientos.store', $rechazo), [
            'debitTransactionId' => $ajeno->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('debitTransactionId');

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending)
            ->and($ajeno->refresh()->reconciliation_status->value)->toBe('pending');
    }

    /** La pantalla del depósito muestra el cheque que se deposita: el reemplazo, no el rechazado. */
    public function test_the_deposit_screen_shows_the_current_cheque(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $this->actingAs($this->operador())
            ->post(route('multas.rechazos.reemplazo', ChequeRejection::query()->sole()), $this->reemplazo('REEMPLAZO-999'))
            ->assertSessionHasNoErrors();

        $this->get(route('multas.pagos.deposito.create', $pago))->assertInertia(fn (Assert $page) => $page
            ->component('multas/depositos/create')
            ->where('cobro.chequeNumber', 'REEMPLAZO-999')
            ->where('cobro.chequeBank', 'Banco Macro')
            ->where('cobro.receivedOn', BusinessDate::today()->toDateString()));
    }

    // ── Lo que no se puede ──────────────────────────────────────────────

    public function test_a_rejected_cheque_is_not_deposited_nor_rejected_again(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->rechazar($pago)->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), [
                'depositDate' => BusinessDate::today()->toDateString(), 'ticket' => UploadedFile::fake()->image('t.jpg'),
                'idempotencyKey' => (string) Str::uuid(),
            ])->assertSessionHasErrors('receiptId');

        $this->rechazar($pago)->assertSessionHasErrors('chequeId');

        $this->rechaza('cheque_rejections_fund_receipt_id_key', fn () => DB::table('cheque_rejections')->insert([
            'fund_receipt_id' => ChequeRejection::query()->sole()->fund_receipt_id, 'rejected_on' => BusinessDate::today()->toDateString(),
            'reason' => 'Otra vez', 'rejection_event_id' => ChequeRejection::query()->sole()->rejection_event_id, 'bank_status' => 'not_applicable',
        ]));
    }

    public function test_the_evidence_is_required_unless_the_debit_is_there(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');

        $this->rechazar($pago, sinRespaldo: true)->assertSessionHasErrors('evidence');

        expect(ChequeRejection::query()->count())->toBe(0);
    }

    /** El reemplazo es otro cheque del mismo importe: la base rechaza otro importe u otro medio (P13). */
    public function test_the_database_keeps_the_replacement_a_cheque_of_the_same_amount(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazado = ChequeRejection::query()->sole()->fundReceipt;

        $fila = [
            'financial_event_id' => $this->asientoDeReemplazo('19000.00'), 'cash_box_id' => $rechazado->cash_box_id,
            'medium' => 'cheque', 'amount' => '19000.00', 'currency' => 'ARS', 'received_date' => BusinessDate::today()->toDateString(),
            'cheque_number' => '1', 'cheque_status' => 'in_custody', 'origin' => 'replacement',
            'replaces_fund_receipt_id' => $rechazado->id, 'created_at' => now(), 'updated_at' => now(),
        ];

        $this->rechaza('mismo importe', fn () => DB::table('fund_receipts')->insert($fila));
        $this->rechaza('mismo importe', fn () => DB::table('fund_receipts')->insert([
            ...$fila, 'financial_event_id' => $this->asientoDeReemplazo('20000.00'),
            'amount' => '20000.00', 'medium' => 'cash', 'cheque_number' => null, 'cheque_status' => null,
        ]));
    }

    /** Haberes no registra rechazos: su cheque se reparte entre cuotas. */
    public function test_the_database_keeps_rejections_out_of_haberes(): void
    {
        $this->abrirLibrosSinSaldo();
        $chequeDeHaberes = app(RegisterCashFundReceipt::class)->handle(
            cashBoxId: $this->cajaDe(CashBox::HABERES), medium: PaymentMedium::Cheque, amount: '5000.00',
            receivedDate: BusinessDate::today(), idempotencyKey: (string) Str::uuid(), currency: Currency::Ars,
            cheque: ['number' => '42', 'bank' => null, 'issueDate' => null], actorId: $this->quienAbre(),
        );

        $this->rechaza('caja que deposita cobros enteros', fn () => DB::table('cheque_rejections')->insert([
            'fund_receipt_id' => $chequeDeHaberes->id, 'rejected_on' => BusinessDate::today()->toDateString(),
            'reason' => 'Prueba', 'rejection_event_id' => $chequeDeHaberes->financial_event_id, 'bank_status' => 'not_applicable',
        ]));
    }

    /** El reemplazo regulariza su pago: no se aplica a otro. */
    public function test_the_database_keeps_a_replacement_from_paying_another_fine(): void
    {
        $multa = $this->escenario('100000.00');
        $otro = $this->cobrarCheque($multa, '20000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $this->actingAs($this->operador())
            ->post(route('multas.rechazos.reemplazo', ChequeRejection::query()->sole()), $this->reemplazo('00088888'))
            ->assertSessionHasNoErrors();
        $reemplazo = FundReceipt::query()->where('origin', 'replacement')->sole();
        $aplicacion = $otro->application()->firstOrFail();

        $this->rechaza('la recepción no es la de este pago', fn () => DB::table('fine_payment_applications')->insert([
            'fine_payment_id' => $otro->id, 'fine_id' => $multa->id, 'fund_receipt_id' => $reemplazo->id,
            'application_event_id' => $aplicacion->application_event_id, 'amount' => '20000.00',
            'applied_amount' => '20000.00', 'excess_amount' => '0.00', 'timeliness' => 'on_time', 'applied_at' => now(),
        ]));
    }

    public function test_who_can_reject_replace_and_complete(): void
    {
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');

        $this->rechazar($pago, como: 'administrativo')->assertForbidden();
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.rechazos.reemplazo', $rechazo), $this->reemplazo('1'))
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.rechazos.completar', $rechazo), ['disposition' => 'act'])
            ->assertSessionHasErrors('dispositionProof');
        $this->post(route('multas.rechazos.completar', $rechazo), [
            'companyNotifiedOn' => BusinessDate::today()->toDateString(),
            'disposition' => 'act', 'dispositionProof' => UploadedFile::fake()->image('acta.jpg'),
        ])->assertSessionHasNoErrors();
        $this->post(route('multas.rechazos.completar', $rechazo), ['companyNotifiedOn' => BusinessDate::today()->toDateString()])
            ->assertSessionHasErrors('companyNotifiedOn');

        expect($rechazo->refresh()->disposition?->value)->toBe('act')
            ->and($rechazo->company_notified_on?->toDateString())->toBe(BusinessDate::today()->toDateString());

        $this->rechaza('una sola vez', fn () => DB::table('cheque_rejections')->where('id', $rechazo->id)->update(['disposition' => 'voided_returned']));
    }

    /** La base: los dos vínculos de la cadena dicen lo mismo. */
    public function test_the_database_keeps_both_links_of_the_chain_together(): void
    {
        $multa = $this->escenario('100000.00');
        $otro = $this->cobrarCheque($multa, '20000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();

        $this->rechaza('tiene que reemplazar a ese cheque', fn () => DB::table('cheque_rejections')->where('id', $rechazo->id)->update([
            'replacement_fund_receipt_id' => $otro->application()->firstOrFail()->fund_receipt_id,
            'replacement_event_id' => $this->asientoDeReemplazo('20000.00'), 'regularized_at' => now(),
        ]));
    }

    // ── Escenario ───────────────────────────────────────────────────────

    /** El alcance vigente no permite cerrar ni conciliar el rechazo por HTTP. */
    public function test_bank_followup_is_disabled_but_rejection_and_replacement_remain_available(): void
    {
        expect(config('multas.cheque_bank_followup_enabled'))->toBeFalse();
        $multa = $this->escenario('100000.00');
        $pago = $this->cobrarCheque($multa, '20000.00');
        $traslado = $this->depositar($pago);
        $credito = $this->acreditar($traslado);
        $debito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00', TransactionDirection::Debit);

        $this->rechazar($pago, sinRespaldo: true, debito: $debito)->assertSessionHasErrors('debitTransactionId');
        expect(ChequeRejection::query()->count())->toBe(0);
        $this->rechazar($pago)->assertSessionHasNoErrors();
        $rechazo = ChequeRejection::query()->sole();
        $libro = $this->libro();
        $asignaciones = DB::table('bank_transaction_allocations')->count();

        $this->get(route('multas.pagos.rechazo.debitos', $pago))->assertNotFound();
        $this->get(route('multas.rechazos.movimientos.index', $rechazo))->assertNotFound();
        $this->post(route('multas.rechazos.movimientos.store', $rechazo), [
            'debitTransactionId' => $debito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('debitTransactionId');
        $this->post(route('multas.rechazos.no-acreditado', $rechazo), ['note' => 'Sin acreditación según operador'])
            ->assertSessionHasErrors(['note' => 'El seguimiento bancario del rechazo queda pendiente para una próxima etapa.']);

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending)
            ->and($this->libro())->toBe($libro)
            ->and(DB::table('bank_transaction_allocations')->count())->toBe($asignaciones)
            ->and(DB::table('bank_transaction_allocations')->where('bank_transaction_id', $credito->id)->exists())->toBeTrue();

        $this->actingAs($this->operador())->post(route('multas.rechazos.reemplazo', $rechazo), $this->reemplazo('REEMP-ACTUAL'))
            ->assertSessionHasNoErrors();
        expect($this->saldo($multa, 'pending_regularization'))->toBe('0.00')
            ->and($this->saldo($multa, 'debt'))->toBe('80000.00')
            ->and($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending);
    }

    private function escenario(string $importe): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => $importe,
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function cobrarCheque(Fine $multa, string $importe): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => 'cheque', 'amount' => $importe,
            'cheque' => ['number' => '0001'.random_int(1000, 9999), 'bank' => 'Banco Nación', 'issueDate' => null],
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function depositar(FinePayment $pago, ?string $fecha = null): CashToBankTransfer
    {
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => $fecha ?? BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return CashToBankTransfer::query()->latest('id')->firstOrFail();
    }

    private function acreditar(CashToBankTransfer $traslado): BankTransaction
    {
        $credito = $this->movimiento($this->laCuenta, $traslado->deposit_date->toDateString(), $traslado->amount);

        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return $credito;
    }

    private function rechazar(FinePayment $pago, bool $sinRespaldo = false, ?BankTransaction $debito = null, string $como = 'contador'): TestResponse
    {
        return $this->actingAs($this->operador($como))->post(route('multas.pagos.rechazo.store', $pago), array_filter([
            'rejectedOn' => BusinessDate::today()->toDateString(),
            'reason' => 'Sin fondos suficientes',
            'evidence' => $sinRespaldo ? null : UploadedFile::fake()->image('aviso.jpg'),
            'debitTransactionId' => $debito?->id,
            'idempotencyKey' => (string) Str::uuid(),
        ]));
    }

    /** Un asiento de reemplazo de la caja de Multas, sin pasar por el Action. */
    private function asientoDeReemplazo(string $importe): int
    {
        $caja = $this->cajaDe(CashBox::MULTAS);

        return app(PostJournalEntry::class)->handle(
            type: FinancialEventType::ChequeReplaced,
            idempotencyKey: (string) Str::uuid(),
            lines: [
                EntryLine::debit(LedgerAccount::ChequesInCustody, $importe)->in(Currency::Ars)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::RejectedCheques, $importe)->in(Currency::Ars)->onCashBox($caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $caja,
            actorId: $this->quienAbre(),
        )->id;
    }

    /** @return array<string, string> */
    private function reemplazo(string $numero): array
    {
        return ['chequeNumber' => $numero, 'chequeBank' => 'Banco Macro', 'idempotencyKey' => (string) Str::uuid()];
    }

    /** `REJECTED_CHEQUES` nunca acreedor; `PENDING_BANK_DEBITS` nunca deudor. */
    private function assertSignos(): void
    {
        $libro = $this->libro();

        expect((float) ($libro['REJECTED_CHEQUES'] ?? 0))->toBeGreaterThanOrEqual(0)
            ->and((float) ($libro['PENDING_BANK_DEBITS'] ?? 0))->toBeLessThanOrEqual(0);
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    /** @return array<string, string> */
    private function libro(): array
    {
        return DB::table('journal_lines')
            ->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))
            ->groupBy('account_code')
            ->selectRaw('account_code, SUM(debit - credit)::numeric(19,2)::text AS saldo')
            ->pluck('saldo', 'account_code')
            ->all();
    }

    private function rechaza(string $fragmento, callable $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            expect(mb_strtolower($e->getMessage()))->toContain(mb_strtolower($fragmento));

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
