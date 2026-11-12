<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\AdjustFeeCharge;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CorrectFeeCharge;
use App\Modules\Aranceles\Actions\CreateFeeCharge;
use App\Modules\Aranceles\Actions\IdentifyFeeCredits;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Actions\RecordVerifiedFeeBankPayment;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeProof;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Concerns\LoadsFeeChargeProof;
use Tests\TestCase;

/**
 * El pago de un arancel por el banco (reglas A50 a A63): pendiente, parcial, ajuste,
 * uno y varios créditos, de más y de menos, sin ventanilla, anular y corregir.
 */
class PagoPorDepositoDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;
    use LoadsFeeChargeProof;
    use RefreshDatabase;

    private User $admin;

    private int $caja;

    private BankAccount $banco;

    private int $clave = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->operador('administrador');
        $this->caja = $this->cajaDe(CashBox::ARANCELES);
        $this->banco = $this->cuenta();

        app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today()->subDays(10),
            bankAccountId: $this->banco->id,
            balances: [],
            denominations: [],
            notes: null,
            declaredEmpty: true,
            actorId: $this->admin->id,
        );
    }

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    /** Homologación, $ 120 con el precio sembrado, y con su comprobante. */
    private function pendiente(int $cantidad = 1): FeeCharge
    {
        return $this->conComprobante($this->sinComprobante($cantidad), $this->admin->id);
    }

    private function sinComprobante(int $cantidad = 1): FeeCharge
    {
        return app(CreateFeeCharge::class)->handle(
            'AG Max SRL', null,
            [['feeTypeId' => $this->homologacion(), 'quantity' => $cantidad]],
            'alta-'.(++$this->clave), $this->admin->id, true,
        );
    }

    /** @return array<string, mixed> */
    private function papel(array $cambios = []): array
    {
        return [
            'amount' => '120.00',
            'depositDate' => BusinessDate::today()->toDateString(),
            'depositTime' => '10:30',
            'operationNumber' => '995979830',
            'terminal' => 'T-12',
            'notes' => 'Depositó el apoderado',
            'ticket' => UploadedFile::fake()->create('comprobante.pdf', 50, 'application/pdf'),
            'idempotencyKey' => 'papel-'.(++$this->clave),
            ...$cambios,
        ];
    }

    /** Rechaza en la base, dentro de un punto de guardado: la prueba sigue después. */
    private function assertBaseRechaza(callable $accion, string $mensaje): void
    {
        try {
            DB::transaction($accion);
        } catch (QueryException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());

            return;
        }

        $this->fail('La base tenía que rechazarlo.');
    }

    private function homologacion(): int
    {
        return (int) FeeType::query()->where('code', 14)->value('id');
    }

    private function credito(string $importe, ?CarbonImmutable $fecha = null): BankTransaction
    {
        return $this->movimiento($this->banco, ($fecha ?? BusinessDate::today())->toDateString(), $importe);
    }

    /** @param  BankTransaction|list<BankTransaction>  $creditos */
    private function identificar(FeeCharge $cobro, BankTransaction|array $creditos, ?string $clave = null): FeeCharge
    {
        $ids = array_map(fn (BankTransaction $c): int => $c->id, is_array($creditos) ? $creditos : [$creditos]);

        return app(IdentifyFeeCredits::class)->handle($cobro, $ids, $clave ?? 'identifica-'.(++$this->clave), null, $this->admin->id);
    }

    private function saldo(LedgerAccount $cuenta): string
    {
        return Decimal::scale(app(CashBalance::class)->of($cuenta, $this->caja, Currency::Ars));
    }

    private function libre(BankTransaction $credito): string
    {
        return app(AllocatableAmount::class)->for($credito->refresh());
    }

    /** @param callable(): mixed $accion */
    private function assertRechazaEn(string $campo, callable $accion, string $mensaje): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->assertStringContainsString($mensaje, $e->errors()[$campo][0]);

            return;
        }

        $this->fail("Tenía que rechazar en {$campo}.");
    }

    /** @param callable(): mixed $escritura */
    private function assertLaBaseRechaza(callable $escritura, string $mensaje): void
    {
        try {
            DB::transaction(function () use ($escritura): void {
                $escritura();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());

            return;
        }

        $this->fail('La base tenía que rechazar la escritura: '.$mensaje);
    }

    private function motivo(FeeReasonCode $codigo = FeeReasonCode::Rounding): Reason
    {
        return new Reason($codigo, null);
    }

    // ── Pendiente ─────────────────────────────────────────────────────────

    public function test_a_pending_charge_moves_no_money_and_keeps_its_price(): void
    {
        $cobro = $this->pendiente();

        $this->assertSame(FeeChargeStatus::Pending, $cobro->status);
        $this->assertSame(FeeChargeMedium::Bank, $cobro->medium);
        $this->assertNull($cobro->paid_on);
        $this->assertSame('0.00', $this->saldo(LedgerAccount::BankAccount));
        $this->assertSame(0, FeeChargeReceipt::query()->count());

        FeeTypePrice::query()->create([
            'fee_type_id' => $this->homologacion(), 'valid_from' => BusinessDate::today()->addDay(), 'unit_price' => '999', 'created_by' => $this->admin->id,
        ]);
        $this->travelTo(BusinessDate::today()->addDays(2)->setTime(10, 0));

        // A57: un cambio de precio posterior no lo afecta.
        $this->identificar($cobro, $this->credito('120'));
        $this->assertSame(FeeChargeStatus::Paid, $cobro->refresh()->status);
    }

    public function test_the_form_sent_twice_creates_one_pending_charge(): void
    {
        $linea = [['feeTypeId' => $this->homologacion(), 'quantity' => 1]];

        $primero = app(CreateFeeCharge::class)->handle('AG', null, $linea, 'la-misma', $this->admin->id, true);
        $segundo = app(CreateFeeCharge::class)->handle('AG', null, $linea, 'la-misma', $this->admin->id, true);

        $this->assertSame($primero->id, $segundo->id);
    }

    // ── Identificar ───────────────────────────────────────────────────────

    public function test_an_exact_credit_pays_and_issues_the_receipt(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');

        $pagado = $this->identificar($cobro, $credito);

        $this->assertSame(FeeChargeStatus::Paid, $pagado->status);
        $this->assertSame(BusinessDate::today()->toDateString(), $pagado->paid_on?->toDateString());
        $this->assertSame('120.00', $this->saldo(LedgerAccount::BankAccount));
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CollectedFunds));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::UnassignedFunds));
        $this->assertSame(ReconciliationStatus::Reconciled, $credito->refresh()->reconciliation_status);

        $recibo = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail();
        $this->assertSame('bank', $recibo->receipt->medium_snapshot);
        $this->assertSame($this->banco->account_number, $recibo->snapshot['bankAccountNumber']);
    }

    /** A54: de cada crédito se toma lo necesario, y el resto sigue libre para otro. */
    public function test_a_larger_credit_leaves_the_rest_free_for_another_charge(): void
    {
        $credito = $this->credito('300');

        $this->identificar($this->pendiente(), $credito);

        $this->assertSame('180.00', $this->libre($credito));
        $this->assertSame(ReconciliationStatus::Partial, $credito->refresh()->reconciliation_status);
        $this->assertSame('120.00', $this->saldo(LedgerAccount::BankAccount));

        $this->identificar($this->pendiente(), $credito);

        $this->assertSame('60.00', $this->libre($credito));
        $this->assertSame('240.00', $this->saldo(LedgerAccount::BankAccount));
    }

    /** A55: lo parcial queda vinculado y protegido; al completar, un solo recibo con todo. */
    public function test_a_partial_payment_waits_and_one_receipt_documents_every_credit(): void
    {
        $cobro = $this->pendiente();
        $primero = $this->credito('100', BusinessDate::today()->subDay());

        $parcial = $this->identificar($cobro, $primero);

        $this->assertSame(FeeChargeStatus::Pending, $parcial->status);
        $this->assertSame('100.00', $this->saldo(LedgerAccount::UnassignedFunds));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CollectedFunds));
        $this->assertSame(0, FeeChargeReceipt::query()->count());
        $this->assertSame('0.00', $this->libre($primero));

        $segundo = $this->credito('50');
        $pagado = $this->identificar($cobro, $segundo);

        $this->assertSame(FeeChargeStatus::Paid, $pagado->status);
        $this->assertSame('30.00', $this->libre($segundo));
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CollectedFunds));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::UnassignedFunds));
        $this->assertSame(1, FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->count());

        $recibo = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->value('receipt_id');
        // Dos recepciones y dos aplicaciones.
        $this->assertSame(4, ReceiptFinancialEvent::query()->where('receipt_id', $recibo)->count());
    }

    public function test_several_credits_at_once_pay_from_the_oldest(): void
    {
        $cobro = $this->pendiente();
        $nuevo = $this->credito('100');
        $viejo = $this->credito('100', BusinessDate::today()->subDays(2));

        $this->identificar($cobro, [$nuevo, $viejo]);

        $this->assertSame('0.00', $this->libre($viejo));
        $this->assertSame('80.00', $this->libre($nuevo));
    }

    public function test_a_credit_that_is_not_needed_is_rejected(): void
    {
        $cobro = $this->pendiente();
        $alcanza = $this->credito('120', BusinessDate::today()->subDay());
        $sobra = $this->credito('50');

        $this->assertRechazaEn('credits', fn () => $this->identificar($cobro, [$alcanza, $sobra]), 'ya se completa');
        $this->assertSame('120.00', $this->libre($alcanza));
    }

    public function test_the_form_sent_twice_identifies_once(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('50');

        $this->identificar($cobro, $credito, 'la-misma');
        $this->identificar($cobro, $credito, 'la-misma');

        $this->assertSame(1, FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->count());
    }

    /** A58: desde la apertura y solo de las cuentas de la caja. */
    public function test_only_credits_of_the_box_after_its_opening(): void
    {
        $cobro = $this->pendiente();
        $anterior = $this->credito('120', BusinessDate::today()->subDays(20));
        $otraCuenta = $this->movimiento($this->cuenta('310000111222333'), BusinessDate::today()->toDateString(), '120');

        $this->assertRechazaEn('credits', fn () => $this->identificar($cobro, $anterior), 'ya no se puede identificar');
        $this->assertRechazaEn('credits', fn () => $this->identificar($cobro, $otraCuenta), 'ya no se puede identificar');
    }

    public function test_a_paid_charge_is_not_identified_again(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('120'));

        $this->assertRechazaEn('credits', fn () => $this->identificar($cobro, $this->credito('120')), 'ya no está pendiente');
    }

    // ── Sin ventanilla ────────────────────────────────────────────────────

    /** A33: la tarifa es la del más antiguo de los créditos vinculados. */
    public function test_without_a_window_the_price_is_of_the_oldest_credit(): void
    {
        FeeTypePrice::query()->create([
            'fee_type_id' => $this->homologacion(), 'valid_from' => BusinessDate::today(), 'unit_price' => '500', 'created_by' => $this->admin->id,
        ]);
        $viejo = $this->credito('100', BusinessDate::today()->subDays(2));
        $nuevo = $this->credito('100');

        $cobro = app(RecordVerifiedFeeBankPayment::class)->handle(
            'Sin ventanilla', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            [$viejo->id, $nuevo->id], 'sin-ventanilla', null, $this->admin->id, true,
        );

        $this->assertSame(FeeChargeStatus::Paid, $cobro->status);
        $this->assertSame($viejo->transaction_date?->toDateString(), $cobro->pricing_date->toDateString());
        $this->assertSame('120.00', FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->value('calculated_amount'));
        $this->assertSame('80.00', $this->libre($nuevo));
    }

    public function test_without_a_window_a_short_credit_stays_pending(): void
    {
        $cobro = app(RecordVerifiedFeeBankPayment::class)->handle(
            'Sin ventanilla', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            [$this->credito('100')->id], 'sin-ventanilla', null, $this->admin->id, true,
        );

        $this->assertSame(FeeChargeStatus::Pending, $cobro->status);
        $this->assertSame('100.00', $this->saldo(LedgerAccount::UnassignedFunds));
    }

    // ── Ajustes ───────────────────────────────────────────────────────────

    /** A60 y A61: se acepta lo recibido y sale el recibo, con el original conservado. */
    public function test_accepting_what_arrived_completes_the_charge(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $ajustado = app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '100', 'previous' => '120.00']], $this->motivo(), null, $this->admin->id);

        $this->assertSame(FeeChargeStatus::Paid, $ajustado->status);
        $this->assertSame('120.00', $renglon->refresh()->original_amount);
        $this->assertSame('100.00', $this->saldo(LedgerAccount::CollectedFunds));

        $papel = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->firstOrFail()->snapshot;
        $this->assertSame('100.00', $papel['lines'][0]['amount']);
    }

    public function test_adjusting_up_keeps_it_pending(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $ajustado = app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '150', 'previous' => '120.00']], $this->motivo(FeeReasonCode::Rounding), null, $this->admin->id);

        $this->assertSame(FeeChargeStatus::Pending, $ajustado->status);
        $this->identificar($cobro, $this->credito('150'));
        $this->assertSame('150.00', $this->saldo(LedgerAccount::CollectedFunds));
    }

    public function test_the_total_cannot_go_below_what_arrived(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->assertRechazaEn('lines', fn () => app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '90', 'previous' => '120.00']], $this->motivo(), null, $this->admin->id), 'Ya hay');
    }

    /** A26: el redondeo no tiene tope; si fue de más o de menos sale de lo calculado. */
    public function test_rounding_has_no_limit(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '50', 'previous' => '120.00']], $this->motivo(FeeReasonCode::Rounding), null, $this->admin->id);

        $this->assertDatabaseHas('fee_charge_item_adjustments', ['fee_charge_item_id' => $renglon->id, 'new_amount' => '50.00', 'change_reason_code' => 'rounding']);
    }

    /** Los motivos que se quitaron no entran ni por la pantalla ni por la base. */
    public function test_a_removed_reason_is_refused(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.ajustar', $cobro), [
            'lines' => [['itemId' => $renglon->id, 'amount' => '100', 'previousAmount' => '120.00']], 'reasonCode' => 'less_accepted',
        ])->assertSessionHasErrors('reasonCode');

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_item_adjustments')->insert([
            'fee_charge_item_id' => $renglon->id, 'previous_amount' => '120', 'new_amount' => '100', 'change_reason_code' => 'more_accepted',
        ]), 'fee_charge_item_adjustments_change_reason_code_check');
    }

    /** A63: en el Action y en la base. */
    public function test_a_charge_with_a_receipt_is_not_adjusted(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('120'));
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->assertRechazaEn('lines', fn () => app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '100', 'previous' => '120.00']], $this->motivo(), null, $this->admin->id), 'se corrige');

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_item_adjustments')->insert([
            'fee_charge_item_id' => $renglon->id, 'previous_amount' => '120', 'new_amount' => '100', 'change_reason_code' => 'rounding',
        ]), 'Solo se ajusta un cobro pendiente');
    }

    /** Ajustar no mueve dinero: el aviso no habla de un ingreso. */
    public function test_adjusting_a_pending_charge_says_what_happened(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.ajustar', $cobro), [
            'lines' => [['itemId' => $renglon->id, 'amount' => '100', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertRedirect(route('aranceles.cobros.index', ['estado' => 'pending']));

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['estado' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->hasFlash('toast', [
                'type' => 'success',
                'message' => 'Importe ajustado.',
                'description' => 'Faltan $ 100,00 para emitir el recibo.',
            ]));
    }

    /** Dos operadores con el mismo cobro abierto: el segundo no pisa al primero. */
    public function test_a_stale_adjustment_is_rejected_and_does_not_undo_another(): void
    {
        $cobro = app(CreateFeeCharge::class)->handle(
            'AG Max SRL', null, array_fill(0, 2, ['feeTypeId' => $this->homologacion(), 'quantity' => 1]),
            'dos-renglones', $this->admin->id, true,
        );
        [$primero, $segundo] = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->orderBy('id')->pluck('id')->all();
        $url = route('aranceles.pendientes.ajustar', $cobro);

        $this->actingAs($this->admin)->post($url, [
            'lines' => [['itemId' => $primero, 'amount' => '100', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertSessionHasNoErrors();

        // Quien abrió antes ve $ 120 en el primero, edita el segundo: no lo toca.
        $this->actingAs($this->admin)->post($url, [
            'lines' => [['itemId' => $segundo, 'amount' => '110', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['100.00', '110.00'], DB::table('fee_charge_item_amounts')->where('fee_charge_id', $cobro->id)->orderBy('fee_charge_item_id')->pluck('amount')->all());

        // Y si quiere cambiar el que otro ya cambió, se le avisa.
        $this->actingAs($this->admin)->post($url, [
            'lines' => [['itemId' => $primero, 'amount' => '90', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertSessionHasErrors('lines');

        $this->assertSame('100.00', DB::table('fee_charge_item_amounts')->where('fee_charge_item_id', $primero)->value('amount'));
    }

    public function test_the_screens_send_the_reasons_their_dialogs_need(): void
    {
        $this->pendiente();
        $credito = $this->credito('500');

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['estado' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page->has('motivos.amount_change.0.value')->has('motivos.collection_void.0.value'));

        $this->actingAs($this->admin)->get(route('aranceles.creditos.identificar', $credito))
            ->assertInertia(fn (Assert $page) => $page->has('motivos.amount_change.0.value'));
    }

    // ── Anular ────────────────────────────────────────────────────────────

    private function cerrarHoy(): void
    {
        $revisor = $this->operador('administrador');
        $arqueo = app(RecordCashCount::class)->handle($this->caja, BusinessDate::today(), [], actorId: $this->admin->id);
        app(ReviewCashCount::class)->handle($arqueo, $revisor->id);
        app(ClosePeriod::class)->handle($this->caja, BusinessDate::today(), actorId: $revisor->id);
    }

    /** Sin dinero no hay asiento: el día cerrado no se toca (excepción provisoria de la regla). */
    public function test_a_pending_charge_without_money_is_voided_with_the_day_closed(): void
    {
        $cobro = $this->pendiente();
        $this->cerrarHoy();

        $anulado = app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::Duplicate, null), $this->admin->id);

        $this->assertSame(FeeChargeStatus::Voided, $anulado->status);
    }

    /** Con un ingreso parcial sí hay asiento: el día cerrado lo rechaza. */
    public function test_a_pending_charge_with_money_is_not_voided_with_the_day_closed(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));
        $this->cerrarHoy();

        try {
            app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::Duplicate, null), $this->admin->id);
            $this->fail('Con el día cerrado tenía que rechazarse.');
        } catch (ClosedPeriodException) {
            $this->assertSame(FeeChargeStatus::Pending, $cobro->refresh()->status);
        }
    }

    /** A71: se revierte la recepción y el crédito vuelve a quedar libre. */
    public function test_voiding_a_partial_frees_the_credit(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('100');
        $this->identificar($cobro, $credito);

        $anulado = app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $this->assertSame(FeeChargeStatus::Voided, $anulado->status);
        $this->assertSame('100.00', $this->libre($credito));
        $this->assertSame(ReconciliationStatus::Pending, $credito->refresh()->reconciliation_status);
        $this->assertSame('0.00', $this->saldo(LedgerAccount::BankAccount));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::UnassignedFunds));
    }

    public function test_voiding_without_money_only_closes_it(): void
    {
        $anulado = app(VoidFeeCollection::class)->handle($this->pendiente(), new Reason(FeeReasonCode::Duplicate, null), $this->admin->id);

        $this->assertSame(FeeChargeStatus::Voided, $anulado->status);
    }

    public function test_voiding_a_verified_payment_frees_the_credit_and_the_receipt_keeps_its_number(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');
        $this->identificar($cobro, $credito);

        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $this->assertSame('120.00', $this->libre($credito));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CollectedFunds));
        $recibo = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail();
        $this->assertSame(ReceiptStatus::Voided, $recibo->receipt->status);
    }

    // ── Corregir ──────────────────────────────────────────────────────────

    private function corregir(FeeCharge $cobro, int $cantidad): FeeCharge
    {
        return app(CorrectFeeCharge::class)->handle(
            $cobro, 'AG Max SRL', null,
            [['feeTypeId' => $this->homologacion(), 'quantity' => $cantidad]],
            new Reason(FeeReasonCode::WrongQuantity, null), null, $this->admin->id, true,
        );
    }

    public function test_correcting_with_the_same_amount_keeps_the_money(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');
        $this->identificar($cobro, $credito);
        $recepcion = FundReceipt::query()->where('cash_box_id', $this->caja)->value('id');

        $nuevo = app(CorrectFeeCharge::class)->handle(
            $cobro, 'AG Max SA', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            new Reason(FeeReasonCode::Other, 'Otro detalle'), null, $this->admin->id, true,
        );

        $this->assertSame(FeeChargeStatus::Paid, $nuevo->status);
        $this->assertSame(FeeChargeMedium::Bank, $nuevo->medium);
        $this->assertSame($recepcion, FeeChargeFunding::query()->where('fee_charge_id', $nuevo->id)->value('fund_receipt_id'));
        $this->assertSame('0.00', $this->libre($credito));
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CollectedFunds));
    }

    /** A77: los mismos créditos pagan el cobro nuevo, y lo que sobra queda libre. */
    public function test_correcting_to_less_uses_the_same_credit_and_frees_the_rest(): void
    {
        $cobro = $this->pendiente(2);
        $credito = $this->credito('240');
        $this->identificar($cobro, $credito);

        $nuevo = $this->corregir($cobro, 1);

        $this->assertSame(FeeChargeStatus::Paid, $nuevo->status);
        $this->assertSame(FeeChargeStatus::Voided, $cobro->refresh()->status);
        $this->assertSame('120.00', $this->libre($credito));
        $this->assertSame('120.00', $this->saldo(LedgerAccount::BankAccount));
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CollectedFunds));
    }

    public function test_correcting_to_more_stays_pending_with_what_is_missing(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');
        $this->identificar($cobro, $credito);

        $nuevo = $this->corregir($cobro, 2);

        $this->assertSame(FeeChargeStatus::Pending, $nuevo->status);
        $this->assertSame('120.00', $this->saldo(LedgerAccount::UnassignedFunds));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CollectedFunds));
        $this->assertSame('0.00', $this->libre($credito));
    }

    // ── El libro del día ──────────────────────────────────────────────────

    /** Cada día muestra lo que entró ese día, con recibo o sin él. */
    public function test_the_day_book_shows_what_entered_each_day(): void
    {
        $ayer = BusinessDate::today();
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));

        $libro = app(CashDayBook::class)->entries($this->caja, $ayer, $ayer);
        $this->assertCount(1, $libro['income']);
        $this->assertStringContainsString('sin recibo', $libro['income'][0]['number']);
        $this->assertSame('100.00', $libro['income'][0]['bank']);

        $this->travelTo($ayer->addDay()->setTime(10, 0));
        $hoy = BusinessDate::today();
        $this->identificar($cobro, $this->credito('20'));

        $delDia = app(CashDayBook::class)->entries($this->caja, $hoy, $hoy);
        $this->assertCount(1, $delDia['income']);
        $this->assertSame('20.00', $delDia['income'][0]['bank']);
        $this->assertNotNull($delDia['income'][0]['receiptId']);

        // El día anterior conserva su importe, ahora con el número del recibo.
        $anterior = app(CashDayBook::class)->entries($this->caja, $ayer, $ayer);
        $this->assertSame('100.00', $anterior['income'][0]['bank']);
        $this->assertSame($delDia['income'][0]['receiptId'], $anterior['income'][0]['receiptId']);
    }

    public function test_the_day_book_shows_the_reversal_of_a_partial_voided_another_day(): void
    {
        $ayer = BusinessDate::today();
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));

        $this->travelTo($ayer->addDay()->setTime(10, 0));
        $hoy = BusinessDate::today();
        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $delDia = app(CashDayBook::class)->entries($this->caja, $hoy, $hoy);

        $this->assertStringContainsString('Reversión', $delDia['income'][0]['number']);
        $this->assertSame('-100.00', $delDia['income'][0]['bank']);
    }

    /** En un rango de días, lo revertido el mismo día no se suma: el detalle cierra con el libro. */
    public function test_the_period_detail_matches_the_ledger_after_a_void(): void
    {
        $primero = BusinessDate::today();
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));

        $this->travelTo($primero->addDay()->setTime(10, 0));
        $segundo = BusinessDate::today();
        $this->identificar($cobro, $this->credito('20'));
        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $suma = fn (CarbonImmutable $desde, CarbonImmutable $hasta): string => array_reduce(
            app(CashDayBook::class)->entries($this->caja, $desde, $hasta)['income'],
            fn (string $total, array $fila): string => Decimal::add($total, $fila['bank']),
            '0.00',
        );

        $this->assertSame('100.00', $suma($primero, $primero));
        $this->assertSame('-100.00', $suma($segundo, $segundo));
        $this->assertSame('0.00', $suma($primero, $segundo));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::BankAccount));
    }

    // ── La base ───────────────────────────────────────────────────────────

    public function test_the_database_requires_a_funding_to_be_born_unapplied(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');

        $this->assertLaBaseRechaza(function () use ($cobro, $credito): void {
            $recepcion = app(RegisterBankFundReceipt::class)->handle($credito, '120', 'aplicacion-falsa', $this->caja, actorId: $this->admin->id);

            FeeChargeFunding::query()->create([
                'fee_charge_id' => $cobro->id, 'fund_receipt_id' => $recepcion->id, 'amount' => '120',
                'application_event_id' => $recepcion->financial_event_id, 'created_by' => $this->admin->id,
            ]);
        }, 'nace sin aplicar');
    }

    public function test_the_database_requires_the_whole_receipt_to_be_linked(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('150');

        $this->assertLaBaseRechaza(function () use ($cobro, $credito): void {
            $recepcion = app(RegisterBankFundReceipt::class)->handle($credito, '150', 'remanente-trabado', $this->caja, actorId: $this->admin->id);

            app(FeeChargeFunds::class)->link($cobro, $recepcion, '100', $this->admin->id);
        }, 'por todo su importe');
    }

    public function test_the_database_requires_a_charge_for_bank_money(): void
    {
        $credito = $this->credito('120');

        $this->assertLaBaseRechaza(fn () => app(RegisterBankFundReceipt::class)->handle(
            $credito, '120', 'sin-cobro', $this->caja, actorId: $this->admin->id,
        ), 'no respalda ningún cobro');
    }

    public function test_the_database_keeps_a_pending_charge_without_a_payment_date(): void
    {
        $cobro = $this->pendiente();

        $this->assertLaBaseRechaza(fn () => $cobro->forceFill(['paid_on' => BusinessDate::today()])->save(), 'fee_charges_pending_has_no_payment_date');
    }

    public function test_the_database_does_not_mark_paid_without_applying_the_money(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, $this->credito('100'));
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->assertLaBaseRechaza(function () use ($cobro, $renglon): void {
            DB::table('fee_charge_item_adjustments')->insert([
                'fee_charge_item_id' => $renglon->id, 'previous_amount' => '120', 'new_amount' => '100', 'change_reason_code' => 'rounding',
            ]);
            $cobro->forceFill(['status' => FeeChargeStatus::Paid, 'paid_on' => BusinessDate::today()])->save();
        }, 'Un cobro pagado');
    }

    // ── Banco › Movimientos ───────────────────────────────────────────────

    /** Una cuenta compartida ofrece lo de cada caja; uno usado en parte, solo lo de Aranceles. */
    public function test_a_shared_account_offers_both_boxes(): void
    {
        $this->abrirMultas($this->banco, BusinessDate::today()->subDays(10)->toDateString());
        $libre = $this->credito('500');
        $usado = $this->credito('500');
        $this->identificar($this->pendiente(), $usado);

        $this->actingAs($this->admin)->get(route('banco.movimientos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where("creditActions.{$libre->id}.0.label", 'Identificar como multa')
                ->where("creditActions.{$libre->id}.1.label", 'Identificar como arancel')
                ->where("creditActions.{$libre->id}.1.href", route('aranceles.creditos.identificar', $libre, absolute: false))
                ->has("creditActions.{$usado->id}", 1)
                ->where("creditActions.{$usado->id}.0.label", 'Identificar como arancel'));
    }

    public function test_a_note_gives_way_to_the_box_that_can_identify_it(): void
    {
        $this->abrirMultas($this->banco, BusinessDate::today()->subDays(30)->toDateString());
        $anterior = $this->credito('500', BusinessDate::today()->subDays(20));

        $this->actingAs($this->admin)->get(route('banco.movimientos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has("creditActions.{$anterior->id}", 1)
                ->where("creditActions.{$anterior->id}.0.label", 'Identificar como multa'));
    }

    // ── Pantallas ─────────────────────────────────────────────────────────

    public function test_the_whole_circuit_through_the_screens(): void
    {
        $this->actingAs($this->admin)->post(route('aranceles.pendientes.store'), [
            'payerName' => 'AG Max SRL', 'items' => [['feeTypeId' => $this->homologacion(), 'quantity' => 1]], 'idempotencyKey' => 'pantalla',
        ])->assertRedirect();

        $cobro = FeeCharge::query()->firstOrFail();
        $credito = $this->credito('100');
        $this->cargarComprobante($cobro, $this->admin->id);

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['estado' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/cobros', false)
                ->where('charges.data.0.missing', '120.00')
                ->where('can.identify', true));

        $this->actingAs($this->admin)->getJson(route('aranceles.creditos.index', ['q' => '100']))
            ->assertOk()
            ->assertJsonPath('0.id', $credito->id)
            ->assertJsonPath('0.free', '100.00');

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.identificar', $cobro), [
            'credits' => [$credito->id], 'idempotencyKey' => 'pantalla-1',
        ])->assertRedirect(route('aranceles.cobros.index', ['estado' => 'pending']));

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['estado' => 'pending']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.data.0.missing', '20.00')
                ->where('charges.data.0.fundings.0.amount', '100.00'));

        $segundo = $this->credito('50');

        $this->actingAs($this->admin)->get(route('aranceles.creditos.identificar', $segundo))
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/identificar-credito', false)
                ->where('credit.free', '50.00')
                ->where('charges.0.id', $cobro->id));

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.identificar', $cobro), [
            'credits' => [$segundo->id], 'idempotencyKey' => 'pantalla-2',
        ])->assertRedirect();

        $recibo = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->value('receipt_id');

        $this->actingAs($this->admin)->get(route('aranceles.recibos.view', $recibo))->assertOk();
        $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))
            ->assertInertia(fn (Assert $page) => $page->where('charges.data.0.medium', 'bank')->where('charges.data.0.receiptId', $recibo));

        // Ya sin saldo que le falte al crédito usado entero, vuelve a la lista.
        $this->actingAs($this->admin)->get(route('aranceles.creditos.identificar', $credito))->assertRedirect();
    }

    /** Una sola pantalla: el filtro arma la cola, y el contador no depende de él. */
    public function test_the_list_filters_by_status_and_counts_the_pending_ones(): void
    {
        $viejo = $this->pendiente();
        $pagado = $this->pendiente();
        $nuevo = $this->pendiente();

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.identificar', $pagado), [
            'credits' => [$this->credito('120')->id], 'idempotencyKey' => 'filtro',
        ])->assertRedirect();

        $ids = fn (array $query = []) => $this->actingAs($this->admin)->get(route('aranceles.cobros.index', $query))
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/cobros', false)
                ->where('pendingCount', 2)
                ->where('can.identify', true)
                ->where('can.adjust', true));

        $ids()->assertInertia(fn (Assert $page) => $page
            ->where('filters.estado', null)
            ->where('charges.data.0.id', $nuevo->id)
            ->where('charges.data.1.id', $pagado->id)
            ->where('charges.data.2.id', $viejo->id));

        // La cola va del que más espera al más nuevo.
        $ids(['estado' => 'pending'])->assertInertia(fn (Assert $page) => $page
            ->where('filters.estado', 'pending')
            ->has('charges.data', 2)
            ->where('charges.data.0.id', $viejo->id)
            ->where('charges.data.1.id', $nuevo->id));

        $ids(['estado' => 'paid'])->assertInertia(fn (Assert $page) => $page
            ->has('charges.data', 1)
            ->where('charges.data.0.id', $pagado->id));

        $ids(['estado' => 'voided'])->assertInertia(fn (Assert $page) => $page->has('charges.data', 0));

        // Lo que no es un estado no filtra ni rompe.
        foreach (['otro', ['pending']] as $raro) {
            $ids(['estado' => $raro])->assertInertia(fn (Assert $page) => $page
                ->where('filters.estado', null)
                ->has('charges.data', 3));
        }
    }

    public function test_the_filter_combines_with_the_search(): void
    {
        $this->pendiente();
        $otro = app(CreateFeeCharge::class)->handle(
            'Otra Empresa SA', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            'otra-empresa', $this->admin->id, true,
        );

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['estado' => 'pending', 'q' => 'otra']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('charges.data', 1)
                ->where('charges.data.0.id', $otro->id)
                ->where('filters.q', 'otra')
                ->where('filters.estado', 'pending')
                ->where('pendingCount', 2));
    }

    // ── Comprobante ───────────────────────────────────────────────────────

    public function test_registering_a_pending_charge_leads_to_the_proof_screen(): void
    {
        $respuesta = $this->actingAs($this->admin)->post(route('aranceles.pendientes.store'), [
            'payerName' => 'AG Max SRL', 'items' => [['feeTypeId' => $this->homologacion(), 'quantity' => 1]], 'idempotencyKey' => 'al-comprobante',
        ]);

        $cobro = FeeCharge::query()->where('idempotency_key', 'al-comprobante')->firstOrFail();

        $respuesta->assertRedirect(route('aranceles.cobros.comprobante.create', $cobro));

        $this->actingAs($this->admin)->get(route('aranceles.cobros.comprobante.create', $cobro))
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/comprobante-de-cobro', false)
                ->where('cobro.payerName', 'AG Max SRL')
                ->where('cobro.total', '120.00')
                ->missing('cobro.missing'));
    }

    /** El comprobante es opcional: el dinero se verifica contra el extracto. */
    public function test_a_pending_charge_without_proof_is_identified_and_can_load_it_later(): void
    {
        $cobro = $this->sinComprobante();
        $credito = $this->credito('120');

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.identificar', $cobro), [
            'credits' => [$credito->id], 'idempotencyKey' => 'sin-papel',
        ])->assertSessionHasNoErrors();

        $this->assertSame(FeeChargeStatus::Paid, $cobro->refresh()->status);
        $this->assertSame(1, FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->count());
        $this->assertSame(0, FeeChargeProof::query()->where('fee_charge_id', $cobro->id)->count());

        $this->actingAs($this->admin)->post(route('aranceles.cobros.comprobante.store', $cobro), $this->papel())->assertSessionHasNoErrors();
        $this->assertSame(1, FeeChargeProof::query()->where('fee_charge_id', $cobro->id)->count());
    }

    /** Lo que nace del crédito no pasó por ventanilla: el extracto ya es su respaldo. */
    public function test_a_payment_born_from_a_credit_does_not_need_a_proof(): void
    {
        $credito = $this->credito('120');

        $this->actingAs($this->admin)->post(route('aranceles.creditos.registrar'), [
            'payerName' => 'Sin ventanilla', 'items' => [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            'credits' => [$credito->id], 'idempotencyKey' => 'nace-del-credito',
        ])->assertSessionHasNoErrors();

        $cobro = FeeCharge::query()->where('idempotency_key', 'nace-del-credito')->firstOrFail();
        $this->assertSame(FeeChargeStatus::Paid, $cobro->status);

        // Y se le puede cargar después, aunque ya tenga recibo.
        $this->actingAs($this->admin)->post(route('aranceles.cobros.comprobante.store', $cobro), $this->papel())->assertSessionHasNoErrors();
        $this->assertSame(FeeChargeStatus::Paid, $cobro->refresh()->status);
        $this->assertSame(1, FeeChargeProof::query()->where('fee_charge_id', $cobro->id)->count());
    }

    public function test_the_proof_keeps_what_the_paper_says(): void
    {
        $cobro = $this->sinComprobante();
        // El importe del papel puede diferir: el dinero se verifica contra el extracto.
        $papel = $this->papel(['amount' => '150.00']);

        $this->actingAs($this->admin)->post(route('aranceles.cobros.comprobante.store', $cobro), $papel)
            ->assertRedirect(route('aranceles.cobros.index', ['cobro' => $cobro->id]));
        // El mismo envío dos veces no guarda el papel dos veces.
        $this->actingAs($this->admin)->post(route('aranceles.cobros.comprobante.store', $cobro), $papel)->assertSessionHasNoErrors();

        $comprobante = FeeChargeProof::query()->where('fee_charge_id', $cobro->id)->sole();

        $this->assertSame('150.00', $comprobante->amount);
        $this->assertSame('995979830', $comprobante->operation_number);
        $this->assertSame('T-12', $comprobante->terminal);
        $this->assertSame('Depositó el apoderado', $comprobante->notes);
        $this->assertSame('10:30:00', $comprobante->deposit_time);
        $this->assertSame(BusinessDate::today()->toDateString(), $comprobante->deposit_date->toDateString());

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['cobro' => $cobro->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('charges.data.0.depositProof.attachment.id', $comprobante->attachment_id)
                ->where('charges.data.0.depositProof.amount', '150.00')
                ->where('charges.data.0.depositProof.operationNumber', '995979830'));
        $this->assertFalse(FinancialEvent::query()->where('idempotency_key', 'like', '%'.$papel['idempotencyKey'].'%')->exists());
    }

    public function test_changing_the_proof_keeps_the_previous_one_and_shows_the_last(): void
    {
        $cobro = $this->sinComprobante();

        $primero = $this->cargarComprobante($cobro, $this->admin->id, '100');
        $segundo = $this->cargarComprobante($cobro, $this->admin->id, '120');

        $this->assertSame(2, FeeChargeProof::query()->where('fee_charge_id', $cobro->id)->count());
        $this->assertNotSame($primero->attachment_id, $segundo->attachment_id);

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index', ['cobro' => $cobro->id]))
            ->assertInertia(fn (Assert $page) => $page->where('charges.data.0.depositProof.attachment.id', $segundo->attachment_id));

        $this->assertSame(1, AuditEvent::query()->where('action', 'arancel.comprobante-cargado')->count());
        $this->assertSame(1, AuditEvent::query()->where('action', 'arancel.comprobante-cambiado')->count());
    }

    public function test_the_proof_is_refused_without_its_photo_or_on_a_voided_or_cash_charge(): void
    {
        $cobro = $this->sinComprobante();
        $url = route('aranceles.cobros.comprobante.store', $cobro);

        $this->actingAs($this->admin)->post($url, $this->papel(['ticket' => null]))->assertSessionHasErrors('ticket');
        $this->actingAs($this->admin)->post($url, $this->papel(['ticket' => UploadedFile::fake()->create('virus.exe', 50)]))->assertSessionHasErrors('ticket');
        $this->actingAs($this->admin)->post($url, $this->papel(['amount' => '0']))->assertSessionHasErrors('amount');
        $this->actingAs($this->admin)->post($url, $this->papel(['depositDate' => BusinessDate::today()->addDay()->toDateString()]))->assertSessionHasErrors('depositDate');

        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::Duplicate, null), $this->admin->id);

        $this->actingAs($this->admin)->post($url, $this->papel())->assertSessionHasErrors('ticket');
        $this->actingAs($this->admin)->get(route('aranceles.cobros.comprobante.create', $cobro))->assertNotFound();

        $efectivo = app(CollectFeeInCash::class)->handle(
            'En efectivo', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            'cobro-efectivo', $this->admin->id, null, null, null, true,
        );

        $this->actingAs($this->admin)->post(route('aranceles.cobros.comprobante.store', $efectivo), $this->papel())->assertSessionHasErrors('ticket');
        $this->actingAs($this->admin)->get(route('aranceles.cobros.comprobante.create', $efectivo))->assertNotFound();

        $this->assertSame(0, FeeChargeProof::query()->count());
    }

    public function test_loading_the_proof_needs_the_permission_to_collect(): void
    {
        $cobro = $this->sinComprobante();
        $contador = $this->operador('contador');

        $this->assertFalse($contador->can('aranceles.cobrar'));
        $this->actingAs($contador)->get(route('aranceles.cobros.comprobante.create', $cobro))->assertForbidden();
        $this->actingAs($contador)->post(route('aranceles.cobros.comprobante.store', $cobro), $this->papel())->assertForbidden();
    }

    public function test_the_database_guards_the_proofs(): void
    {
        $cobro = $this->sinComprobante();
        $otro = $this->sinComprobante();
        $comprobante = $this->cargarComprobante($cobro, $this->admin->id);
        $fila = [
            'fee_charge_id' => $otro->id, 'attachment_id' => $comprobante->attachment_id, 'amount' => '10',
            'deposit_date' => BusinessDate::today()->toDateString(), 'idempotency_key' => 'directo',
        ];

        $this->assertBaseRechaza(fn () => DB::table('fee_charge_proofs')->where('id', $comprobante->id)->update(['amount' => '1']), 'no se edita');
        $this->assertBaseRechaza(fn () => DB::table('fee_charge_proofs')->where('id', $comprobante->id)->delete(), 'no se edita');
        $this->assertBaseRechaza(fn () => DB::table('fee_charge_proofs')->insert($fila), 'no es de ese cobro');
        $this->assertBaseRechaza(
            fn () => DB::table('fee_charge_proofs')->insert([...$fila, 'fee_charge_id' => $cobro->id, 'amount' => '0', 'idempotency_key' => 'cero']),
            'fee_charge_proofs_amount_positive',
        );

        $efectivo = app(CollectFeeInCash::class)->handle(
            'En efectivo', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            'efectivo-base', $this->admin->id, null, null, null, true,
        );
        $this->assertBaseRechaza(fn () => DB::table('fee_charge_proofs')->insert([...$fila, 'fee_charge_id' => $efectivo->id]), 'por banco');

        app(VoidFeeCollection::class)->handle($otro, new Reason(FeeReasonCode::Duplicate, null), $this->admin->id);
        $this->assertBaseRechaza(fn () => DB::table('fee_charge_proofs')->insert($fila), 'anulado');

        $this->assertBaseRechaza(
            fn () => DB::table('attachments')->insert([
                'subject_type' => 'otra_cosa', 'subject_id' => 1, 'document_type' => 'x', 'title' => 'x', 'storage_disk' => 'local', 'object_key' => 'k',
                'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
                'source' => 'scanned', 'confidentiality' => 'internal',
            ]),
            'attachments_subject_type_check',
        );
    }

    public function test_a_verified_payment_and_an_adjustment_through_the_screens(): void
    {
        $credito = $this->credito('100');

        $this->actingAs($this->admin)->post(route('aranceles.creditos.registrar'), [
            'payerName' => 'Sin ventanilla', 'items' => [['feeTypeId' => $this->homologacion(), 'quantity' => 1]],
            'credits' => [$credito->id], 'idempotencyKey' => 'sin-ventanilla',
        ])->assertRedirect(route('aranceles.cobros.index', ['estado' => 'pending']));

        $cobro = FeeCharge::query()->firstOrFail();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $this->actingAs($this->admin)->post(route('aranceles.pendientes.ajustar', $cobro), [
            'lines' => [['itemId' => $renglon->id, 'amount' => '100', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertRedirect();

        $this->assertSame(FeeChargeStatus::Paid, $cobro->refresh()->status);
    }

    public function test_each_act_needs_its_permission(): void
    {
        $cobro = $this->pendiente();
        $credito = $this->credito('120');
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();

        $administrativo = $this->operador('administrativo');
        $this->actingAs($administrativo)->post(route('aranceles.pendientes.identificar', $cobro), ['credits' => [$credito->id], 'idempotencyKey' => 'x'])->assertForbidden();
        $this->actingAs($administrativo)->getJson(route('aranceles.creditos.index'))->assertForbidden();
        $this->actingAs($administrativo)->get(route('aranceles.creditos.identificar', $credito))->assertForbidden();
        $this->actingAs($administrativo)->post(route('aranceles.cobros.anular', $cobro), ['reasonCode' => 'duplicate'])->assertForbidden();

        $contador = $this->operador('contador');
        $this->actingAs($contador)->post(route('aranceles.pendientes.store'), [
            'payerName' => 'AG', 'items' => [['feeTypeId' => $this->homologacion(), 'quantity' => 1]], 'idempotencyKey' => 'y',
        ])->assertForbidden();
        $this->actingAs($contador)->post(route('aranceles.pendientes.ajustar', $cobro), [
            'lines' => [['itemId' => $renglon->id, 'amount' => '100', 'previousAmount' => '120.00']], 'reasonCode' => 'rounding',
        ])->assertForbidden();
    }

    public function test_a_price_change_without_permission_is_rejected_when_pending(): void
    {
        $this->assertRechazaEn('items.0.amount', fn () => app(CreateFeeCharge::class)->handle(
            'AG', null, [['feeTypeId' => $this->homologacion(), 'quantity' => 1, 'amount' => '100', 'reasonCode' => 'rounding']],
            'sin-permiso', $this->admin->id, false,
        ), 'No tenés permiso');
    }
}
