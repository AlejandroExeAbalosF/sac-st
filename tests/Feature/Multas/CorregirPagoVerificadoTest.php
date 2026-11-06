<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\ReplaceRejectedCheque;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\CorrectVerifiedFinePayment;
use App\Modules\Multas\Actions\RecordFineChequeMovements;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ReplaceFineCheque;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\ResolveFineAppeal;
use App\Modules\Multas\Actions\RevertFineAppealVerdict;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Actions\VerifyFineBankPayment;
use App\Modules\Multas\Actions\VoidFineCollection;
use App\Modules\Multas\Actions\VoidVerifiedFinePayment;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineAppealVerdict;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentCorrection;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Multas\Support\FineMovements;
use App\Modules\Multas\Support\FineRendition;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Corregir un pago bancario verificado y anular un cheque rechazado (decisiones
 * del usuario, 04/11/2026): cada regla en el Action y en la base.
 */
class CorregirPagoVerificadoTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');
    }

    // ── Corregir ────────────────────────────────────────────────────────

    /** A otra multa: la deuda vuelve en una y baja en la otra, y lo anterior queda a la vista. */
    public function test_moving_a_payment_to_another_fine_keeps_the_previous_version(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $recibo = $this->reciboDe($pago);

        $correccion = $this->corregir($pago, $destino, $credito);
        $nuevo = FinePayment::query()->findOrFail($correccion->new_payment_id);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided)
            ->and($recibo->refresh()->status)->toBe(ReceiptStatus::Voided)
            ->and($nuevo->status)->toBe(FinePaymentStatus::Applied)
            ->and($nuevo->fine_id)->toBe($destino->id)
            ->and($nuevo->amount)->toBe('400000.00')
            ->and($this->reciboDe($nuevo)->formatted_number)->not->toBe($recibo->formatted_number)
            ->and($this->saldo($origen, 'debt'))->toBe('1400000.00')
            ->and($this->saldo($destino, 'debt'))->toBe('1000000.00')
            ->and($this->libro(LedgerAccount::BankAccount))->toBe('400000.00')
            ->and($this->libro(LedgerAccount::CollectedFunds))->toBe('400000.00')
            ->and($this->libro(LedgerAccount::UnassignedFunds))->toBe('0.00')
            ->and($correccion->from_fine_id)->toBe($origen->id)
            ->and($correccion->to_fine_id)->toBe($destino->id);

        $this->verificarPendientes();
    }

    /** A otro crédito: el importe es el del crédito nuevo, y el anterior vuelve a ofrecerse. */
    public function test_changing_the_credit_takes_its_whole_amount_and_frees_the_previous_one(): void
    {
        $multa = $this->multa();
        [$pago, $credito] = $this->verificado($multa, '400000.00');
        $otro = $this->credito('500000.00');

        $correccion = $this->corregir($pago, $multa, $otro);

        expect(FinePayment::query()->findOrFail($correccion->new_payment_id)->amount)->toBe('500000.00')
            ->and($this->saldo($multa, 'debt'))->toBe('900000.00')
            ->and($credito->refresh()->reconciliation_status)->toBe(ReconciliationStatus::Pending)
            ->and($otro->refresh()->reconciliation_status)->toBe(ReconciliationStatus::Reconciled)
            ->and($this->disponibles())->toContain($credito->id)
            ->and($this->disponibles())->not->toContain($otro->id);

        $this->verificarPendientes();
    }

    /** Corregir horas después: la presentación queda anterior a la carga, y la base pide decir por qué. */
    public function test_a_later_correction_keeps_the_presentation_and_says_why(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 16:00:00'));
        $correccion = $this->corregir($pago, $destino, $credito);
        $nuevo = FinePayment::query()->findOrFail($correccion->new_payment_id);

        expect($nuevo->presented_at?->toIso8601String())->toBe($pago->refresh()->presented_at?->toIso8601String())
            ->and($nuevo->presentation_evidence)->toContain('que este corrige');
    }

    /** Las dos cosas a la vez. */
    public function test_changing_both_the_fine_and_the_credit(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago] = $this->verificado($origen, '400000.00');

        $correccion = $this->corregir($pago, $destino, $this->credito('300000.00'));

        expect(FinePayment::query()->findOrFail($correccion->new_payment_id)->fine_id)->toBe($destino->id)
            ->and($this->saldo($origen, 'debt'))->toBe('1400000.00')
            ->and($this->saldo($destino, 'debt'))->toBe('1100000.00');

        $this->verificarPendientes();
    }

    /** Si nada cambia, no hay corrección. */
    public function test_a_correction_that_changes_nothing_is_refused(): void
    {
        $multa = $this->multa();
        [$pago, $credito] = $this->verificado($multa, '400000.00');

        $this->rechazaElAction('bankTransactionId', fn () => $this->corregir($pago, $multa, $credito));

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    /** Un segundo envío devuelve la corrección ya hecha. */
    public function test_correcting_twice_is_a_single_correction(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');

        $primera = $this->corregir($pago, $destino, $credito);
        $segunda = $this->corregir($pago, $destino, $credito);

        expect($segunda->id)->toBe($primera->id)
            ->and(FinePaymentCorrection::query()->count())->toBe(1);
    }

    /** Lo que sobra en el destino es excedente; lo que dejó de sobrar en el origen, también se reclasifica. */
    public function test_the_excess_follows_each_fine(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '1500000.00');
        expect($this->saldo($origen, 'operational_excess'))->toBe('100000.00');

        $this->corregir($pago, $destino, $credito);

        expect($this->saldo($origen, 'operational_excess'))->toBe('0.00')
            ->and($this->saldo($destino, 'operational_excess'))->toBe('100000.00')
            ->and($this->libro(LedgerAccount::PendingExcess))->toBe('100000.00');

        $this->verificarPendientes();
    }

    /** No pasa a una multa congelada, ni a una sin deuda. */
    public function test_the_target_fine_has_to_accept_the_payment(): void
    {
        $origen = $this->multa();
        [$pago, $credito] = $this->verificado($origen, '400000.00');

        $enRecurso = $this->multa('169608/24-0');
        $this->recurrir($enRecurso, FinePaymentMedium::Cash, '280000.00');
        $this->rechazaConMensaje('La multa está en recurso', fn () => $this->corregir($pago, $enRecurso, $credito));

        $saldada = $this->multa('169609/24-0');
        $this->cobrar($saldada, '1400000.00');
        $this->rechazaConMensaje('no tiene saldo pendiente', fn () => $this->corregir($pago, $saldada, $credito));

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    /** Con el día de hoy cerrado no se puede: la corrección va con fecha de hoy. */
    public function test_a_correction_needs_today_open(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $this->cerrarDia('2026-09-15');

        $this->expectException(ClosedPeriodException::class);

        $this->corregir($pago, $destino, $credito);
    }

    /** Si falla al emitir el recibo nuevo, todo retrocede y el pago sigue vigente. */
    public function test_a_failure_issuing_the_new_receipt_rolls_everything_back(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $otro = $this->informar($origen, '100000.00');
        app(VerifyFineBankPayment::class)->handle($otro, $this->credito('100000.00')->id, $this->quienAbre(), '72190');
        $recibo = $this->reciboDe($pago);

        try {
            DB::transaction(fn () => app(CorrectVerifiedFinePayment::class)->handle(
                $pago, $destino, $credito->id, new Reason(FineReasonCode::WrongFine, 'Era de otra'), '72190', null, $this->quienAbre(),
            ));
            $this->fail('Tenía que rechazar el talonario ocupado.');
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('talonarioNumber');
        }

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Applied)
            ->and($recibo->refresh()->status)->toBe(ReceiptStatus::Issued)
            ->and($credito->refresh()->reconciliation_status)->toBe(ReconciliationStatus::Reconciled)
            ->and(FinePaymentCorrection::query()->count())->toBe(0)
            ->and(DB::table('fine_payment_voids')->count())->toBe(0)
            ->and($this->saldo($origen, 'debt'))->toBe('900000.00')
            ->and($this->saldo($destino, 'debt'))->toBe('1400000.00');
    }

    // ── El término ──────────────────────────────────────────────────────

    /** En la misma multa conserva la clasificación, aunque su fecha de registro haya cambiado. */
    public function test_the_same_fine_keeps_its_timeliness_even_if_its_registration_changed(): void
    {
        $multa = $this->multa();
        [$pago] = $this->verificado($multa, '400000.00');
        $original = $pago->application()->firstOrFail();
        expect($original->timeliness)->toBe(FineTimeliness::Late);

        $this->reviseRegistro($multa, '2026-09-01');

        $correccion = $this->corregir($pago, $multa->refresh(), $this->credito('300000.00'));
        $nuevo = FinePayment::query()->findOrFail($correccion->new_payment_id);
        $aplicacion = $nuevo->application()->firstOrFail();

        expect($aplicacion->timeliness)->toBe(FineTimeliness::Late)
            ->and($aplicacion->term_ends_on?->toDateString())->toBe($original->term_ends_on?->toDateString())
            ->and($nuevo->presented_at?->toIso8601String())->toBe($pago->refresh()->presented_at?->toIso8601String());
    }

    /** En otra multa se juzga contra esa, con la misma presentación. */
    public function test_another_fine_judges_the_term_against_itself(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0', registrada: '2026-09-01');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        expect($pago->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);

        $correccion = $this->corregir($pago, $destino, $credito);
        $nuevo = FinePayment::query()->findOrFail($correccion->new_payment_id);
        $aplicacion = $nuevo->application()->firstOrFail();

        expect($aplicacion->timeliness)->toBe(FineTimeliness::OnTime)
            ->and($aplicacion->term_ends_on?->toDateString())->toBe('2026-09-30')
            ->and($nuevo->presented_at?->toIso8601String())->toBe($pago->refresh()->presented_at?->toIso8601String());
    }

    // ── Con recurso ─────────────────────────────────────────────────────

    /** Con el recurso en trámite reemplaza su propio pago: el recurso sigue y la multa, congelada. */
    public function test_an_open_appeal_payment_is_replaced_within_its_appeal(): void
    {
        $multa = $this->multa();
        [$pago] = $this->depositoDeRecurso($multa, '280000.00');
        $recurso = FineAppeal::query()->sole();

        $correccion = $this->corregir($pago, $multa, $this->credito('285000.00'));
        $nuevo = FinePayment::query()->findOrFail($correccion->new_payment_id);

        expect($recurso->refresh()->status)->toBe(FineAppealStatus::Open)
            ->and($nuevo->fine_appeal_id)->toBe($recurso->id)
            ->and($nuevo->amount)->toBe('285000.00')
            ->and($nuevo->appeal_base_amount)->toBe($pago->refresh()->appeal_base_amount)
            ->and($recurso->payments()->live()->sole()->id)->toBe($nuevo->id);

        $this->verificarPendientes();
        $this->rechazaElAction('amount', fn () => $this->informar($multa, '100000.00'));
    }

    /** El pago de un recurso no cambia de multa. */
    public function test_an_appeal_payment_does_not_move_to_another_fine(): void
    {
        $multa = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->depositoDeRecurso($multa, '280000.00');

        $this->rechazaConMensaje('dentro de su multa', fn () => $this->corregir($pago, $destino, $credito));
    }

    /** Con veredicto, primero se revierte. Después se puede corregir. */
    public function test_an_appeal_with_a_verdict_is_reverted_first(): void
    {
        $multa = $this->multa();
        [$pago] = $this->depositoDeRecurso($multa, '280000.00');
        $recurso = FineAppeal::query()->sole();
        app(ResolveFineAppeal::class)->handle($multa, FineAppealStatus::Rejected, $recurso->id, $pago->id, $this->quienAbre());
        $otro = $this->credito('285000.00');

        $this->rechazaConMensaje('primero revertí el veredicto', fn () => $this->corregir($pago, $multa, $otro));
        $this->rechazaConMensaje('primero revertí el veredicto', fn () => $this->anularVerificado($pago));

        app(RevertFineAppealVerdict::class)->handle(
            $multa, FineAppealVerdict::query()->sole()->id, new Reason(FineReasonCode::DataEntryError, 'Se cargó mal'), $this->quienAbre(),
        );
        $this->corregir($pago, $multa, $otro);

        expect($recurso->refresh()->status)->toBe(FineAppealStatus::Open);
    }

    /** Un pago verificado después del veredicto ya no traba para siempre: se anula y se revierte. */
    public function test_voiding_a_later_payment_unblocks_the_verdict(): void
    {
        $multa = $this->multa();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $recurso = FineAppeal::query()->sole();
        app(ResolveFineAppeal::class)->handle($multa, FineAppealStatus::Rejected, $recurso->id, $recurso->payments()->live()->sole()->id, $this->quienAbre());
        [$posterior] = $this->verificado($multa, '100000.00');
        $revertir = fn () => app(RevertFineAppealVerdict::class)->handle(
            $multa, FineAppealVerdict::query()->sole()->id, new Reason(FineReasonCode::DataEntryError, 'Se cargó mal'), $this->quienAbre(),
        );

        $this->rechazaConMensaje('Corregir pago', $revertir);

        $this->anularVerificado($posterior);
        $revertir();

        expect($recurso->refresh()->status)->toBe(FineAppealStatus::Open);
    }

    // ── Solo anular ─────────────────────────────────────────────────────

    /** Sin pago nuevo: la deuda vuelve y el crédito queda libre. */
    public function test_voiding_without_a_new_payment_frees_the_credit(): void
    {
        $multa = $this->multa();
        [$pago, $credito] = $this->verificado($multa, '400000.00');

        $this->anularVerificado($pago);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided)
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($credito->refresh()->reconciliation_status)->toBe(ReconciliationStatus::Pending)
            ->and($this->disponibles())->toContain($credito->id)
            ->and($this->libro(LedgerAccount::BankAccount))->toBe('0.00')
            ->and(FundReceipt::query()->findOrFail($pago->application()->firstOrFail()->fund_receipt_id)->reversal_event_id)->not->toBeNull();

        $this->verificarPendientes();

        // El mismo crédito se puede volver a usar para otro pago.
        app(VerifyFineBankPayment::class)->handle($this->informar($multa, '400000.00'), $credito->id, $this->quienAbre());
        expect($this->saldo($multa, 'debt'))->toBe('1000000.00');
    }

    /** Anulado solo, un recurso en trámite cae. */
    public function test_voiding_an_open_appeal_payment_drops_the_appeal(): void
    {
        $multa = $this->multa();
        [$pago] = $this->depositoDeRecurso($multa, '280000.00');

        $this->anularVerificado($pago);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Dropped);
        $this->verificarPendientes();
    }

    /** Esta vía es solo para pagos por el banco. */
    public function test_only_bank_payments_are_corrected(): void
    {
        $multa = $this->multa();
        $cobro = $this->cobrar($multa, '100000.00');
        $informado = $this->informar($multa, '100000.00');
        $credito = $this->credito('100000.00');

        $this->rechazaElAction('reason', fn () => $this->anularVerificado($cobro));
        $this->rechazaElAction('reason', fn () => $this->anularVerificado($informado));
        $this->rechazaElAction('reason', fn () => $this->corregir($informado, $multa, $credito));
    }

    // ── Permiso ─────────────────────────────────────────────────────────

    /** Solo el administrador: un contador no llega a la ruta. */
    public function test_only_the_administrator_corrects(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $datos = ['fineId' => $destino->id, 'bankTransactionId' => $credito->id, 'reasonCode' => 'wrong_fine', 'reason' => 'Era de otra'];

        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.correct', $pago), $datos)->assertForbidden();
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.void-verified', $pago), $datos)->assertForbidden();

        $this->actingAs($this->operador('administrador'))->post(route('multas.pagos.correct', $pago), $datos)->assertSessionHasNoErrors();

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided);
    }

    /** No se delega: ni la matriz ni la base dejan dárselo a otro rol. */
    public function test_the_permission_is_not_delegable(): void
    {
        expect(app(PermissionCatalog::class)->nonDelegable())->toContain('multas.corregir-pagos');

        $this->actingAs($this->operador('administrador'))
            ->from(route('configuracion.roles.index'))
            ->put(route('configuracion.roles.update', Role::findByName('contador', 'web')), [
                'permissions' => ['multas.corregir-pagos'],
            ])
            ->assertSessionHasErrors('permissions');

        $this->rechazaLaBase('no se delega', fn () => DB::table('role_has_permissions')->insert([
            'permission_id' => Permission::findByName('multas.corregir-pagos', 'web')->id,
            'role_id' => Role::findByName('contador', 'web')->id,
        ]));
    }

    // ── Cheque rechazado ────────────────────────────────────────────────

    /** Rechazado en la caja: la deuda vuelve, y lo que había que regularizar sale. */
    public function test_voiding_a_cheque_rejected_at_the_counter(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $custodia = $this->libro(LedgerAccount::ChequesInCustody);

        $this->anularCheque($pago);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided)
            ->and($this->reciboDe($pago)->status)->toBe(ReceiptStatus::Voided)
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($this->saldo($multa, 'pending_regularization'))->toBe('0.00')
            ->and($this->libro(LedgerAccount::RejectedCheques))->toBe('0.00')
            ->and($this->libro(LedgerAccount::CollectedFunds))->toBe('0.00')
            ->and($this->libro(LedgerAccount::UnassignedFunds))->toBe('0.00')
            ->and($this->libro(LedgerAccount::ChequesInCustody))->toBe($custodia)
            ->and(FundReceipt::query()->findOrFail($pago->application()->firstOrFail()->fund_receipt_id)->reversal_event_id)->toBeNull();

        $this->verificarPendientes();
    }

    /** Depositado y devuelto, sin acreditación registrada: tránsito no cambia. */
    public function test_voiding_a_bounced_cheque_leaves_transit_alone(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->depositar($pago);
        $this->rechazar($pago);
        $transito = $this->libro(LedgerAccount::CashInTransit);

        $this->anularCheque($pago);

        expect($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($this->libro(LedgerAccount::RejectedCheques))->toBe('0.00')
            ->and($this->libro(LedgerAccount::CashInTransit))->toBe($transito)
            ->and(ChequeRejection::query()->sole()->bank_status)->toBe(ChequeRejectionBankStatus::Unverified);

        $this->verificarPendientes();
    }

    /** Después se cobra por otro medio, o con otro cheque, con recibo propio. */
    public function test_after_voiding_it_is_collected_again_in_any_medium(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $this->anularCheque($pago);

        $efectivo = $this->cobrar($multa, '400000.00');
        $cheque = $this->cobrarCheque($multa, 'A-0002');

        expect($this->saldo($multa, 'debt'))->toBe('600000.00')
            ->and($this->reciboDe($efectivo)->formatted_number)->not->toBe($this->reciboDe($pago)->formatted_number)
            ->and($cheque->status)->toBe(FinePaymentStatus::Applied);
    }

    /** La planilla sigue mostrando el ingreso: el cheque entró y salió con el rechazo. */
    public function test_the_day_book_keeps_the_cheque_that_entered_and_left(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $hoy = BusinessDate::today();
        $caja = $this->cajaDe(CashBox::MULTAS);
        $antes = app(CashDayBook::class)->entries($caja, $hoy, $hoy);

        $this->anularCheque($pago);

        expect(app(CashDayBook::class)->entries($caja, $hoy, $hoy))->toEqual($antes);

        // Un cobro común anulado sigue sin aparecer.
        $otro = $this->cobrar($multa, '100000.00');
        $this->anularCobro($otro);
        expect(app(CashDayBook::class)->entries($caja, $hoy, $hoy))->toEqual($antes);
    }

    /** Acreditado y después rechazado: su revisión bancaria está pendiente y no se anula. */
    public function test_a_credited_cheque_is_not_voided(): void
    {
        $multa = $this->multa();
        $pago = $this->chequeAcreditado($multa);
        $this->rechazar($pago);

        $this->rechazaConMensaje('El banco acreditó', fn () => $this->anularCheque($pago));
        $this->rechazaLaBase('El banco acreditó', fn () => $this->anulacionDirectaDeRechazado($pago));

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    /** En cualquier punto de la cadena: si alguno figura acreditado, el cobro no se anula. */
    public function test_a_credited_cheque_anywhere_in_the_chain_blocks_it(): void
    {
        $multa = $this->multa();
        $pago = $this->chequeAcreditado($multa);
        $this->rechazar($pago);
        $this->reemplazar(ChequeRejection::query()->sole());
        $this->rechazar($pago);

        $this->rechazaConMensaje('El banco acreditó', fn () => $this->anularCheque($pago));
    }

    /** Con un reemplazo en curso no hay rechazo que anular. */
    public function test_a_replacement_in_custody_blocks_it(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $this->reemplazar(ChequeRejection::query()->sole());

        $this->rechazaConMensaje('sigue en curso', fn () => $this->anularCheque($pago));
    }

    /** Anulado, no se reemplaza: lo frena el Action y, sin pasar por él, la base. */
    public function test_a_voided_cheque_is_not_replaced(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $this->anularCheque($pago);
        $rechazo = ChequeRejection::query()->sole();

        $this->rechazaElAction('chequeNumber', fn () => $this->reemplazar($rechazo));
        $this->rechazaLaBase('ya no se reemplaza', fn () => app(ReplaceRejectedCheque::class)->handle(
            $rechazo, ['number' => 'B-1', 'bank' => null, 'issueDate' => null], (string) Str::uuid(), $this->quienAbre(),
        ));
    }

    /** El aviso y el papel siguen pendientes y se pueden completar, sin reemplazo. */
    public function test_the_notice_and_the_paper_can_be_completed_after_voiding(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->rechazar($pago);
        $this->anularCheque($pago);
        $pendientes = fn () => app(FineDeposits::class)->paymentsWithPendingRejections()->pluck('id')->all();

        expect($pendientes())->toContain($pago->id);

        $deposito = app(FineDeposits::class)->forPayments([$pago->refresh()->load('application')])[$pago->id];
        expect($deposito->voided)->toBeTrue()
            ->and($deposito->openRejectionId)->toBeNull()
            ->and($deposito->rejections)->toHaveCount(1);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.rechazos.completar', ChequeRejection::query()->sole()), [
                'companyNotifiedOn' => BusinessDate::today()->toDateString(),
                'notice' => UploadedFile::fake()->image('aviso.jpg'),
                'disposition' => 'voided_returned',
                'dispositionProof' => UploadedFile::fake()->image('papel.jpg'),
            ])
            ->assertSessionHasNoErrors();

        expect($pendientes())->not->toContain($pago->id);
    }

    /** Si el extracto trae después el crédito y el débito, se registran sin reactivar el pago ni pedir reemplazo. */
    public function test_a_late_credit_is_recorded_after_voiding(): void
    {
        config(['multas.cheque_bank_followup_enabled' => true]);
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->depositar($pago);
        $this->rechazar($pago);
        $this->anularCheque($pago);
        $rechazo = ChequeRejection::query()->sole();
        $banco = $this->libro(LedgerAccount::BankAccount);

        app(RecordFineChequeMovements::class)->handle(
            $rechazo,
            $this->movimiento($this->laCuenta, '2026-09-15', '400000.00'),
            $this->movimiento($this->laCuenta, '2026-09-15', '400000.00', TransactionDirection::Debit),
            (string) Str::uuid(),
            $this->quienAbre(),
        );

        expect($rechazo->refresh()->bank_status)->toBe(ChequeRejectionBankStatus::DebitRecorded)
            ->and($pago->refresh()->status)->toBe(FinePaymentStatus::Voided)
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($this->saldo($multa, 'pending_regularization'))->toBe('0.00')
            ->and($this->libro(LedgerAccount::BankAccount))->toBe($banco)
            ->and($this->libro(LedgerAccount::RejectedCheques))->toBe('0.00')
            ->and($this->libro(LedgerAccount::PendingBankDebits))->toBe('0.00');

        $this->verificarPendientes();
    }

    /** Con la revisión bancaria apagada, se rechaza como hoy. */
    public function test_the_late_credit_stays_off_by_default(): void
    {
        $multa = $this->multa();
        $pago = $this->cobrarCheque($multa);
        $this->depositar($pago);
        $this->rechazar($pago);
        $this->anularCheque($pago);

        $this->rechazaConMensaje('próxima etapa', fn () => app(RecordFineChequeMovements::class)->handle(
            ChequeRejection::query()->sole(),
            $this->movimiento($this->laCuenta, '2026-09-15', '400000.00'),
            null,
            (string) Str::uuid(),
            $this->quienAbre(),
        ));
    }

    /** «Se paga por otro medio» es el motivo de un cheque rechazado. */
    public function test_medium_change_is_only_for_a_rejected_cheque(): void
    {
        $pago = $this->cobrar($this->multa(), '100000.00');

        $this->rechazaElAction('reasonCode', fn () => app(VoidFineCollection::class)->handle(
            $pago, new Reason(FineReasonCode::MediumChange, null), $this->quienAbre(),
        ));
    }

    // ── Rendición provisoria ────────────────────────────────────────────

    /** Anulado dentro del mes en que se reconoció, no figura. */
    public function test_voided_within_its_month_it_is_not_rendered(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');

        $this->corregir($pago, $destino, $credito);

        $filas = $this->rendicion('2026-09-01', FineMonthlyReport::ON_TIME);

        expect($filas)->toHaveCount(1)
            ->and($filas[0]['amount'])->toBe('400000.00')
            ->and($filas[0]['receipt'])->toBe($this->reciboDe(FinePaymentCorrection::query()->sole()->newPayment()->firstOrFail())->formatted_number);
    }

    /** Anulado en un mes posterior: aquel no cambia, y este resta y suma. */
    public function test_voided_in_a_later_month_it_stays_and_this_month_reverses_it(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $septiembre = $this->rendicion('2026-09-01', FineMonthlyReport::ON_TIME);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
        $correccion = $this->corregir($pago, $destino, $credito);

        // Reemitir septiembre, si se reabre, da lo mismo.
        expect($this->rendicion('2026-09-01', FineMonthlyReport::ON_TIME))->toEqual($septiembre);

        $octubre = $this->rendicion('2026-10-01', FineMonthlyReport::LATE);
        $importes = array_column($octubre, 'amount');
        sort($importes);

        expect($importes)->toBe(['-400000.00', '400000.00'])
            ->and(array_column($octubre, 'date'))->toBe(['2026-09-15', '2026-09-15'])
            ->and($this->rendicion('2026-10-01', FineMonthlyReport::ON_TIME))->toBe([])
            ->and(array_column($octubre, 'receipt'))->toContain($this->reciboDe($pago)->formatted_number)
            ->and(array_column($octubre, 'receipt'))->toContain($this->reciboDe($correccion->newPayment()->firstOrFail())->formatted_number);
    }

    /** El pago nuevo va en el mes de su reconocimiento; en término si su crédito es de ese mes. */
    public function test_the_new_payment_goes_in_the_month_it_was_recognized(): void
    {
        $multa = $this->multa();
        [$pago] = $this->verificado($multa, '400000.00');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
        $otro = $this->movimiento($this->laCuenta, '2026-10-05', '500000.00');
        $this->corregir($pago, $multa, $otro);

        expect(array_column($this->rendicion('2026-10-01', FineMonthlyReport::ON_TIME), 'amount'))->toBe(['500000.00'])
            ->and(array_column($this->rendicion('2026-10-01', FineMonthlyReport::LATE), 'amount'))->toBe(['-400000.00']);
    }

    // ── Movimientos ─────────────────────────────────────────────────────

    /** Un pago por el banco no fue un cobro en caja; en créditos queda marcado y resta. */
    public function test_movements_show_it_in_credits_not_in_collections(): void
    {
        $multa = $this->multa();
        [$pago] = $this->verificado($multa, '400000.00');

        $this->anularVerificado($pago);

        $mes = CarbonImmutable::parse('2026-09-01');
        $movimientos = app(FineMovements::class);
        $creditos = $movimientos->summary('creditos', $mes, 10);

        expect($movimientos->summary('cobros', $mes, 10)['count'])->toBe(0)
            ->and($creditos['count'])->toBe(2)
            ->and($creditos['total'])->toBe('0.00')
            ->and(implode(' ', array_column($creditos['rows'], 'detail')))->toContain('Anulación del pago verificado');
    }

    // ── La base, sin pasar por el Action ────────────────────────────────

    /** Una anulación bancaria tiene que dejar libre su crédito. */
    public function test_the_database_requires_the_credit_to_be_released(): void
    {
        $multa = $this->multa();
        [$pago] = $this->verificado($multa, '400000.00');
        $aplicacion = $pago->application()->firstOrFail();
        $recepcion = FundReceipt::query()->findOrFail($aplicacion->fund_receipt_id);

        $this->rechazaLaBase('dejó su crédito imputado', function () use ($pago, $aplicacion, $recepcion): void {
            $reversion = $this->reversion($recepcion->financial_event_id);

            DB::table('fine_payment_voids')->insert([
                'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'data_entry_error',
                'application_reversal_event_id' => $this->reversion($aplicacion->application_event_id),
                'funds_exit_event_id' => $reversion,
            ]);
            DB::table('fund_receipts')->where('id', $recepcion->id)->update([
                'reversal_reason_code' => 'other', 'reversal_event_id' => $reversion, 'reversed_at' => now(), 'reversal_reason' => 'Directo',
            ]);
            DB::table('receipts')->where('id', $this->reciboDe($pago)->id)->update([
                'status' => 'voided', 'void_reason_code' => 'other', 'void_reason' => 'Directo', 'voided_at' => now(), 'voided_by' => $this->quienAbre(),
            ]);
            DB::table('fine_payments')->where('id', $pago->id)->update(['status' => 'voided']);
        });
    }

    /** La recepción de un cheque rechazado ocurrió: no se revierte al anular. */
    public function test_the_database_refuses_reversing_a_rejected_cheque_receipt(): void
    {
        $pago = $this->cobrarCheque($this->multa());
        $this->rechazar($pago);
        $recepcion = FundReceipt::query()->findOrFail($pago->application()->firstOrFail()->fund_receipt_id);

        $this->rechazaLaBase('no se revierte', function () use ($pago, $recepcion): void {
            $this->anulacionDirectaDeRechazado($pago);
            DB::table('fund_receipts')->where('id', $recepcion->id)->update([
                'reversal_reason_code' => 'other', 'reversal_event_id' => $this->reversion($recepcion->financial_event_id), 'reversed_at' => now(), 'reversal_reason' => 'Directo',
            ]);
            DB::table('receipts')->where('id', $this->reciboDe($pago)->id)->update([
                'status' => 'voided', 'void_reason_code' => 'other', 'void_reason' => 'Directo', 'voided_at' => now(), 'voided_by' => $this->quienAbre(),
            ]);
            DB::table('fine_payments')->where('id', $pago->id)->update(['status' => 'voided']);
        });
    }

    /** El asiento de salida de un cheque rechazado es el propio, por el importe entero. */
    public function test_the_database_requires_the_rejected_cheque_exit_entry(): void
    {
        $pago = $this->cobrarCheque($this->multa());
        $this->rechazar($pago);
        $aplicacion = $pago->application()->firstOrFail();
        $recepcion = FundReceipt::query()->findOrFail($aplicacion->fund_receipt_id);

        $this->rechazaLaBase('con su asiento propio', fn () => DB::table('fine_payment_voids')->insert([
            'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'medium_change',
            'application_reversal_event_id' => $this->reversion($aplicacion->application_event_id),
            'funds_exit_event_id' => $this->reversion($recepcion->financial_event_id),
        ]));
    }

    /** Una corrección une dos pagos bancarios coherentes. */
    public function test_the_database_refuses_an_incoherent_correction(): void
    {
        $origen = $this->multa();
        $otra = $this->multa('169608/24-0');
        [$vigente] = $this->verificado($origen, '400000.00');
        [$nuevo] = $this->verificado($origen, '100000.00');

        $this->rechazaLaBase('Una corrección une', fn () => DB::table('fine_payment_corrections')->insert([
            'corrected_payment_id' => $vigente->id, 'new_payment_id' => $nuevo->id,
            'from_fine_id' => $origen->id, 'to_fine_id' => $origen->id,
        ]));

        $credito = $this->credito('50000.00');
        $anterior = app(ReportFineBankPayment::class)->handle(
            $origen, FinePaymentMedium::BankTransfer, '50000.00', (string) Str::uuid(), $this->quienAbre(),
            presentedOn: CarbonImmutable::parse('2026-09-10'), presentationEvidence: 'Sello de Mesa de Entradas',
        );
        $anulado = app(VerifyFineBankPayment::class)->handle($anterior, $credito->id, $this->quienAbre());
        $this->anularVerificado($anulado);
        $this->verificarPendientes();

        $this->rechazaLaBase('conserva la presentación', fn () => DB::table('fine_payment_corrections')->insert([
            'corrected_payment_id' => $anulado->id, 'new_payment_id' => $nuevo->id,
            'from_fine_id' => $origen->id, 'to_fine_id' => $origen->id,
        ]));

        // El pago de un recurso se corrige dentro de su multa.
        [$delRecurso] = $this->depositoDeRecurso($otra, '280000.00');
        $deposito = app(VerifyFineBankPayment::class)->handle(
            app(ReportFineBankPayment::class)->handle($origen, FinePaymentMedium::DirectDeposit, '60000.00', (string) Str::uuid(), $this->quienAbre()),
            $this->credito('60000.00')->id,
            $this->quienAbre(),
        );
        $this->anularVerificado($delRecurso);
        $this->verificarPendientes();

        $this->rechazaLaBase('dentro de su recurso', fn () => DB::table('fine_payment_corrections')->insert([
            'corrected_payment_id' => $delRecurso->id, 'new_payment_id' => $deposito->id,
            'from_fine_id' => $otra->id, 'to_fine_id' => $origen->id,
        ]));
    }

    /** Sin cambiar la multa ni el crédito no hay corrección, aunque se escriba directo. */
    public function test_the_database_refuses_a_correction_that_changes_nothing(): void
    {
        $multa = $this->multa();
        [$anterior, $credito] = $this->verificado($multa, '400000.00');
        $this->anularVerificado($anterior);
        $this->verificarPendientes();
        $nuevo = app(VerifyFineBankPayment::class)->handle($this->informar($multa, '400000.00'), $credito->id, $this->quienAbre());

        $this->rechazaLaBase('cambia la multa o el crédito', fn () => DB::table('fine_payment_corrections')->insert([
            'corrected_payment_id' => $anterior->id, 'new_payment_id' => $nuevo->id,
            'from_fine_id' => $multa->id, 'to_fine_id' => $multa->id,
        ]));
    }

    /** Una corrección no se edita ni se borra. */
    public function test_a_correction_is_append_only(): void
    {
        $origen = $this->multa();
        $destino = $this->multa('169608/24-0');
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $correccion = $this->corregir($pago, $destino, $credito);

        $this->rechazaLaBase('', fn () => DB::table('fine_payment_corrections')->where('id', $correccion->id)->update(['corrected_by' => null]));
        $this->rechazaLaBase('', fn () => DB::table('fine_payment_corrections')->where('id', $correccion->id)->delete());
    }

    // ── Migración ───────────────────────────────────────────────────────

    /** Baja y vuelve a subir en modo diferido, como `artisan migrate`. */
    public function test_the_migration_goes_down_and_up(): void
    {
        $multa = $this->multa();
        $this->cobrar($multa, '100000.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_010000_add_verified_payment_corrections.php');

        $migracion->down();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $migracion->up();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        [$pago, $credito] = $this->verificado($multa, '200000.00');
        $this->corregir($pago, $this->multa('169608/24-0'), $credito);

        expect(FinePaymentCorrection::query()->count())->toBe(1);
    }

    /** Con correcciones hechas no baja: se perdería el registro. */
    public function test_the_migration_does_not_go_down_with_corrections(): void
    {
        $origen = $this->multa();
        [$pago, $credito] = $this->verificado($origen, '400000.00');
        $this->corregir($pago, $this->multa('169608/24-0'), $credito);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_010000_add_verified_payment_corrections.php');

        $this->expectExceptionMessage('Hay pagos corregidos');

        $migracion->down();
    }

    // ── Escenario ───────────────────────────────────────────────────────

    private function multa(string $expediente = '169607/24-0', string $registrada = '2026-05-07'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°'.random_int(1000, 9999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°'.random_int(100000, 999999), 'issuedOn' => null]],
            'caption' => 'EMPRESA '.$expediente,
            'registeredOn' => $registrada,
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function credito(string $importe): BankTransaction
    {
        return $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), $importe);
    }

    private function informar(Fine $multa, string $importe): FinePayment
    {
        return app(ReportFineBankPayment::class)->handle(
            $multa, FinePaymentMedium::BankTransfer, $importe, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    /** @return array{FinePayment, BankTransaction} */
    private function verificado(Fine $multa, string $importe): array
    {
        $credito = $this->credito($importe);
        $pago = app(VerifyFineBankPayment::class)->handle($this->informar($multa, $importe), $credito->id, $this->quienAbre());

        return [$pago, $credito];
    }

    /** @return array{FinePayment, BankTransaction} */
    private function depositoDeRecurso(Fine $multa, string $importe): array
    {
        $credito = $this->credito($importe);
        $informado = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, $importe);

        return [app(VerifyFineBankPayment::class)->handle($informado, $credito->id, $this->quienAbre()), $credito];
    }

    private function recurrir(Fine $multa, FinePaymentMedium $medio, string $externo): FinePayment
    {
        return app(RegisterFineAppeal::class)->handle(
            $multa, AppealKind::Hierarchical, $medio, 'R-2026-0042', $externo, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function corregir(FinePayment $pago, Fine $destino, BankTransaction $credito): FinePaymentCorrection
    {
        return app(CorrectVerifiedFinePayment::class)->handle(
            $pago, $destino, $credito->id, new Reason(FineReasonCode::DataEntryError, 'Se identificó mal'), null, null, $this->quienAbre(),
        );
    }

    private function anularVerificado(FinePayment $pago): void
    {
        app(VoidVerifiedFinePayment::class)->handle($pago, new Reason(FineReasonCode::CreditOfAnotherBox, 'Era de Aranceles'), $this->quienAbre());
    }

    private function cobrar(Fine $multa, string $importe): FinePayment
    {
        return app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, $importe, (string) Str::uuid(), $this->quienAbre());
    }

    private function cobrarCheque(Fine $multa, string $numero = 'A-0001'): FinePayment
    {
        return app(CollectFinePayment::class)->handle(
            $multa, FinePaymentMedium::Cheque, '400000.00', (string) Str::uuid(), $this->quienAbre(),
            cheque: ['number' => $numero, 'bank' => 'Banco Nación', 'issueDate' => null],
        );
    }

    private function depositar(FinePayment $pago): CashToBankTransfer
    {
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return CashToBankTransfer::query()->latest('id')->firstOrFail();
    }

    private function chequeAcreditado(Fine $multa): FinePayment
    {
        $pago = $this->cobrarCheque($multa);
        $traslado = $this->depositar($pago);
        app(ConfirmCashDepositCredit::class)->handle($traslado, $this->credito('400000.00'), (string) Str::uuid(), $this->quienAbre());

        return $pago;
    }

    private function rechazar(FinePayment $pago): void
    {
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.rechazo.store', $pago), [
            'rejectedOn' => BusinessDate::today()->toDateString(), 'reason' => 'Sin fondos',
            'evidence' => UploadedFile::fake()->image('aviso.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function reemplazar(ChequeRejection $rechazo): void
    {
        app(ReplaceFineCheque::class)->handle(
            $rechazo, ['number' => 'B-'.random_int(100, 999), 'bank' => null, 'issueDate' => null], (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function anularCheque(FinePayment $pago): void
    {
        app(VoidFineCollection::class)->handle($pago, new Reason(FineReasonCode::MediumChange, 'Paga en efectivo'), $this->quienAbre());
    }

    private function anularCobro(FinePayment $pago): void
    {
        app(VoidFineCollection::class)->handle($pago, new Reason(FineReasonCode::DataEntryError, 'Se tipeó mal'), $this->quienAbre());
    }

    /** La fila de anulación del cheque rechazado, con su asiento, sin pasar por el Action. */
    private function anulacionDirectaDeRechazado(FinePayment $pago): void
    {
        $aplicacion = $pago->application()->firstOrFail();
        $caja = $this->cajaDe(CashBox::MULTAS);

        $salida = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::RejectedChequeVoided,
            idempotencyKey: (string) Str::uuid(),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, '400000.00')->in(Currency::Ars)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::RejectedCheques, '400000.00')->in(Currency::Ars)->onCashBox($caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $caja,
            actorId: $this->quienAbre(),
        );

        DB::table('fine_payment_voids')->insert([
            'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'medium_change',
            'application_reversal_event_id' => $this->reversion($aplicacion->application_event_id),
            'funds_exit_event_id' => $salida->id,
        ]);
    }

    private function reviseRegistro(Fine $multa, string $fecha): void
    {
        $vigente = [
            'caption' => $multa->caption, 'person_id' => $multa->person_id,
            'registered_on' => $multa->registered_on?->toDateString(), 'registered_precision' => $multa->registered_precision->value,
        ];

        app(ReviseFineIdentifiers::class)->handle(
            $multa,
            [...$vigente, 'registered_on' => $fecha, 'registered_precision' => 'day'],
            $vigente,
            new Reason(FineReasonCode::DataEntryError, 'La fecha estaba mal'),
            true,
            $this->quienAbre(),
        );
    }

    /** @return list<array<string, mixed>> */
    private function rendicion(string $mes, string $tipo): array
    {
        return app(FineRendition::class)->rows((new PeriodClosing)->forceFill(['period_from' => $mes]), $tipo);
    }

    /** @return list<int> */
    private function disponibles(): array
    {
        return array_column(app(FineBankCredits::class)->available(''), 'id');
    }

    private function reciboDe(FinePayment $pago): Receipt
    {
        return $pago->paymentReceipt()->firstOrFail()->receipt()->firstOrFail();
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    private function libro(LedgerAccount $cuenta): string
    {
        return app(CashBalance::class)->of($cuenta, $this->cajaDe(CashBox::MULTAS), Currency::Ars, BusinessDate::today());
    }

    private function reversion(int $evento): int
    {
        return app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: (string) Str::uuid(),
            lines: app(InverseEntry::class)->of($evento),
            date: BusinessDate::today(),
            cashBoxId: $this->cajaDe(CashBox::MULTAS),
            actorId: $this->quienAbre(),
            reversalOfId: $evento,
            reversalReason: 'Directo',
        )->id;
    }

    private function cerrarDia(string $fecha): void
    {
        $quien = User::query()->findOrFail($this->quienAbre());
        $caja = $this->cajaDe(CashBox::MULTAS);

        $this->arqueoListoParaCerrar($caja, $fecha, $quien);

        app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($fecha),
            type: PeriodType::Daily,
            currency: Currency::Ars,
            actorId: $quien->id,
        );
    }

    /** Lo que correría al confirmar, sin salir de la transacción del test. */
    private function verificarPendientes(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function rechazaElAction(string $campo, Closure $accion): void
    {
        try {
            DB::transaction($accion);
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($campo);

            return;
        }

        $this->fail("El Action aceptó lo que tenía que rechazar ({$campo}).");
    }

    private function rechazaConMensaje(string $fragmento, Closure $accion): void
    {
        try {
            DB::transaction($accion);
        } catch (ValidationException $e) {
            expect(implode(' ', array_merge(...array_values($e->errors()))))->toContain($fragmento);

            return;
        }

        $this->fail("El Action aceptó lo que tenía que rechazar ({$fragmento}).");
    }

    private function rechazaLaBase(string $fragmento, Closure $accion): void
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
