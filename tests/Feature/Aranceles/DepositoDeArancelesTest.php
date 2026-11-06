<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CorrectFeeCharge;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\FindCashDepositCandidates;
use App\Modules\Banking\Actions\RecordTransferTicket;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * El efectivo de Aranceles camino al banco, en dos pasos (reglas A82 a A89a): el
 * traspaso, el comprobante y la acreditación, en el Action y en la base.
 */
class DepositoDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;
    use CollectsInstallments {
        cuota as cuotaDeHaberes;
        cobrar as cobrarCuota;
    }
    use RefreshDatabase;

    private User $admin;

    private int $caja;

    private BankAccount $banco;

    private int $clave = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = $this->operador('administrador');
        $this->caja = (int) CashBox::query()->where('code', CashBox::ARANCELES)->value('id');
    }

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    private function abrir(string $efectivo = '0'): void
    {
        $hayEfectivo = ! Decimal::equals($efectivo, '0');
        $this->banco = $this->cuenta();

        app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today()->subDays(10),
            bankAccountId: $this->banco->id,
            balances: $hayEfectivo ? [LedgerAccount::CashOnHand->value => $efectivo] : [],
            denominations: $hayEfectivo ? $this->billetesPara($efectivo) : [],
            notes: null,
            declaredEmpty: ! $hayEfectivo,
            actorId: $this->admin->id,
        );
    }

    /** Un cobro de $ 120 (Homologación, código 14), hoy o en otra fecha con su talonario. */
    private function cobrarArancel(?CarbonImmutable $fecha = null): FeeCharge
    {
        return app(CollectFeeInCash::class)->handle(
            'AG Max SRL',
            null,
            [['feeTypeId' => FeeType::query()->where('code', 14)->value('id'), 'quantity' => 1]],
            'clave-'.(++$this->clave),
            $this->admin->id,
            $fecha,
            $fecha === null ? null : 'T-'.$this->clave,
            null,
            true,
        );
    }

    private function recepcionDe(FeeCharge $cobro): FundReceipt
    {
        return FundReceipt::query()->findOrFail(
            FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->value('fund_receipt_id'),
        );
    }

    private function traspasar(string $importe, ?CarbonImmutable $fecha = null, ?string $clave = null): CashToBankTransfer
    {
        return app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, $importe, $fecha ?? BusinessDate::today(), null, null,
            $clave ?? 'traspaso-'.(++$this->clave), $this->admin->id,
        );
    }

    private function cargarComprobante(CashToBankTransfer $traslado, ?string $importe = null, ?CarbonImmutable $fecha = null, ?string $clave = null): CashToBankTransfer
    {
        return app(RecordTransferTicket::class)->handle(
            $traslado,
            $importe ?? $traslado->amount,
            ['depositDate' => $fecha ?? BusinessDate::today(), 'operationNumber' => '995979830'],
            UploadedFile::fake()->image('comprobante.jpg'),
            $clave ?? 'comprobante-'.(++$this->clave),
            $this->admin->id,
        );
    }

    private function saldo(LedgerAccount $cuenta): string
    {
        return Decimal::scale(app(CashBalance::class)->of($cuenta, $this->caja, Currency::Ars));
    }

    /** @return array<int, string> por recepción */
    private function reparto(CashToBankTransfer $traslado): array
    {
        return CashToBankTransferItem::query()
            ->where('cash_to_bank_transfer_id', $traslado->id)
            ->orderBy('id')
            ->pluck('amount', 'fund_receipt_id')
            ->map(fn (mixed $v): string => (string) $v)
            ->all();
    }

    /** @param callable(): mixed $accion */
    private function assertRechazaEn(string $campo, callable $accion, ?string $mensaje = null): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            if ($mensaje !== null) {
                $this->assertStringContainsString($mensaje, $e->errors()[$campo][0]);
            }

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

    private function faltante(string $importe, CarbonImmutable $fecha): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashAdjustment,
            idempotencyKey: 'faltante-'.(++$this->clave),
            lines: [
                EntryLine::debit(LedgerAccount::CashDifference, $importe)->in(Currency::Ars)->onCashBox($this->caja),
                EntryLine::credit(LedgerAccount::CashOnHand, $importe)->in(Currency::Ars)->onCashBox($this->caja),
            ],
            date: $fecha,
            cashBoxId: $this->caja,
            actorId: $this->admin->id,
        );
    }

    private function assertNegativoEnNingunDia(): void
    {
        foreach (app(CashBalance::class)->runningTotals(LedgerAccount::CashOnHand, $this->caja) as $dia => $saldo) {
            $this->assertFalse(Decimal::isNegative($saldo), "El {$dia} la caja queda en {$saldo}.");
        }
    }

    private function motivo(): Reason
    {
        return new Reason(FeeReasonCode::DataEntryError, null);
    }

    // ── Traspaso ──────────────────────────────────────────────────────────

    public function test_a_partial_transfer_takes_the_oldest_money_and_leaves_the_rest(): void
    {
        $this->abrir('100');
        $primero = $this->cobrarArancel();
        $segundo = $this->cobrarArancel();
        $apertura = FundReceipt::query()->where('cash_box_id', $this->caja)->where('origin', FundReceiptOrigin::Opening)->firstOrFail();

        $traslado = $this->traspasar('150');

        $this->assertSame(CashTransferStatus::AwaitingTicket, $traslado->status);
        $this->assertSame([$apertura->id => '100.00', $this->recepcionDe($primero)->id => '50.00'], $this->reparto($traslado));
        $this->assertSame('190.00', $this->saldo(LedgerAccount::CashOnHand));
        $this->assertSame('150.00', $this->saldo(LedgerAccount::CashInTransit));

        // Lo que quedó de la primera recepción y la segunda entera.
        $otro = $this->traspasar('190');

        $this->assertSame([$this->recepcionDe($primero)->id => '70.00', $this->recepcionDe($segundo)->id => '120.00'], $this->reparto($otro));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CashOnHand));
    }

    public function test_a_transfer_is_not_collection_again(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $recaudado = $this->saldo(LedgerAccount::CollectedFunds);

        $this->traspasar('120');

        $this->assertSame($recaudado, $this->saldo(LedgerAccount::CollectedFunds));
    }

    public function test_it_cannot_take_more_than_is_available(): void
    {
        $this->abrir();
        $this->cobrarArancel();

        $this->assertRechazaEn('amount', fn () => $this->traspasar('120.01'), 'hay $ 120,00');
        $this->assertSame(0, CashToBankTransfer::query()->count());
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CashOnHand));
    }

    /** A89: un traspaso atrasado no toma lo que entró después. */
    public function test_a_past_transfer_only_takes_money_up_to_that_date(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $viejo = $this->cobrarArancel($ayer);
        $this->cobrarArancel();

        $this->assertRechazaEn('amount', fn () => $this->traspasar('240', $ayer), 'hay $ 120,00');

        $traslado = $this->traspasar('120', $ayer);

        $this->assertSame([$this->recepcionDe($viejo)->id => '120.00'], $this->reparto($traslado));
    }

    /** A89a: un faltante ajustado baja el libro y no las recepciones. */
    public function test_an_adjusted_shortage_caps_the_transfer(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $this->faltante('20', BusinessDate::today());

        $this->assertRechazaEn('amount', fn () => $this->traspasar('120'), 'faltan $ 20,00');
        $this->assertSame('100.00', $this->traspasar('100')->amount);
    }

    /** R4: la pantalla ofrece lo mismo que el traspaso acepta. */
    public function test_the_screen_offers_only_what_the_transfer_accepts(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $this->faltante('20', BusinessDate::today());

        $this->actingAs($this->admin)->get(route('aranceles.depositos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('available', '100.00'));

        $this->actingAs($this->admin)->getJson(route('aranceles.depositos.reparto', ['amount' => '120']))
            ->assertOk()
            ->assertJsonPath('available', '100.00')
            ->assertJsonPath('exceeds', true)
            ->assertJsonPath('shares.0.amount', '100.00');

        $this->actingAs($this->admin)->getJson(route('aranceles.depositos.reparto'))
            ->assertJsonPath('exceeds', false)
            ->assertJsonPath('shares.0.amount', '100.00');
    }

    /** El tope de la vista previa también mira los días siguientes a la fecha elegida. */
    public function test_the_preview_of_a_past_date_looks_at_the_days_after_it(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(5));
        $this->faltante('20', $hoy->subDays(3));

        $this->actingAs($this->admin)
            ->getJson(route('aranceles.depositos.reparto', ['transferDate' => $hoy->subDays(4)->toDateString()]))
            ->assertJsonPath('available', '100.00');
    }

    // ── La fecha ──────────────────────────────────────────────────────────

    public function test_the_system_takes_the_date_for_whoever_cannot_choose_it(): void
    {
        $this->abrir();
        $this->cobrarArancel(BusinessDate::today()->subDays(2));
        $administrativo = $this->operador('administrativo');

        $this->actingAs($administrativo)->get(route('aranceles.depositos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.adjustTransfer', false)->where('can.cancel', true));

        $this->actingAs($administrativo)->post(route('aranceles.depositos.store'), [
            'transferDate' => BusinessDate::today()->subDay()->toDateString(), 'amount' => '120', 'idempotencyKey' => 'con-fecha',
        ])->assertSessionHasErrors('transferDate');
        $this->assertSame(0, CashToBankTransfer::query()->count());

        $this->actingAs($administrativo)->post(route('aranceles.depositos.store'), [
            'amount' => '120', 'idempotencyKey' => 'sin-fecha',
        ])->assertRedirect();

        $this->assertSame(BusinessDate::today()->toDateString(), CashToBankTransfer::query()->firstOrFail()->deposit_date->toDateString());
    }

    public function test_whoever_cannot_adjust_it_transfers_everything_available(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $this->cobrarArancel();
        $administrativo = $this->operador('administrativo');

        $this->actingAs($administrativo)->post(route('aranceles.depositos.store'), [
            'amount' => '120', 'idempotencyKey' => 'una-parte',
        ])->assertSessionHasErrors(['amount' => 'Lo disponible ahora es $ 240,00. Revisá el importe y volvé a confirmar.']);
        $this->assertSame(0, CashToBankTransfer::query()->count());

        $envio = ['amount' => '240', 'idempotencyKey' => 'todo'];

        $this->actingAs($administrativo)->post(route('aranceles.depositos.store'), $envio)->assertSessionHasNoErrors();
        // El mismo envío dos veces no se rechaza porque lo disponible ya cambió.
        $this->actingAs($administrativo)->post(route('aranceles.depositos.store'), $envio)->assertSessionHasNoErrors();

        $this->assertSame('240.00', CashToBankTransfer::query()->sole()->amount);
    }

    public function test_only_the_administrator_can_transfer_a_part(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $this->cobrarArancel();

        $this->actingAs($this->admin)->get(route('aranceles.depositos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('can.adjustTransfer', true));
        $this->actingAs($this->admin)->post(route('aranceles.depositos.store'), [
            'amount' => '120', 'idempotencyKey' => 'una-parte',
        ])->assertSessionHasNoErrors();

        $this->assertSame('120.00', CashToBankTransfer::query()->sole()->amount);
        $this->assertFalse($this->operador('contador')->can('aranceles.ajustar-traspaso'));
        $this->assertFalse($this->operador('administrativo')->can('aranceles.ajustar-traspaso'));
    }

    public function test_the_preview_ignores_a_date_for_whoever_cannot_choose_it(): void
    {
        $this->abrir();
        $this->cobrarArancel(BusinessDate::today()->subDays(2));
        $this->cobrarArancel();

        $this->actingAs($this->operador('administrativo'))
            ->getJson(route('aranceles.depositos.reparto', ['transferDate' => BusinessDate::today()->subDays(2)->toDateString()]))
            ->assertJsonPath('available', '240.00');
    }

    public function test_an_administrator_can_register_a_past_date(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $this->cobrarArancel($ayer);

        $this->actingAs($this->admin)->post(route('aranceles.depositos.store'), [
            'transferDate' => $ayer->toDateString(), 'amount' => '120', 'idempotencyKey' => 'admin',
        ])->assertRedirect();

        $this->assertSame($ayer->toDateString(), CashToBankTransfer::query()->firstOrFail()->deposit_date->toDateString());
    }

    public function test_the_screen_shows_when_the_transfer_was_registered(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        $this->actingAs($this->admin)->get(route('aranceles.depositos.index'))
            ->assertInertia(fn (Assert $page) => $page->where('sinComprobante.0.registeredAt', $traslado->created_at->toIso8601String()));
    }

    /** Un traspaso cancelado sigue restando hasta su cancelación: otro anterior dejaría el medio en negativo. */
    public function test_a_past_transfer_cannot_leave_a_day_in_between_negative(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(5));
        $primero = $this->traspasar('120', $hoy->subDays(3));
        app(CancelCashToBankTransfer::class)->handle($primero, 'Se cargó por error', 'cancelar', $this->admin->id);

        $this->assertRechazaEn('amount', fn () => $this->traspasar('120', $hoy->subDays(4)), 'al '.$hoy->subDays(3)->format('d/m/Y').' faltan $ 120,00');

        $this->assertSame(1, CashToBankTransfer::query()->count());
        $this->assertSame('120.00', $this->traspasar('120')->amount);
        $this->assertNegativoEnNingunDia();
    }

    /** La misma falla sin cancelaciones: un faltante en el medio, cubierto por un cobro posterior. */
    public function test_a_past_transfer_sees_a_shortage_covered_later(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(5));
        $this->faltante('20', $hoy->subDays(3));
        $this->cobrarArancel();

        $this->assertRechazaEn('amount', fn () => $this->traspasar('120', $hoy->subDays(4)), 'faltan $ 20,00');
        $this->assertSame('100.00', $this->traspasar('100', $hoy->subDays(4))->amount);
        $this->assertNegativoEnNingunDia();
    }

    public function test_a_second_send_of_the_form_returns_the_same_transfer(): void
    {
        $this->abrir();
        $this->cobrarArancel();

        $primero = $this->traspasar('120', clave: 'el-mismo');
        $segundo = $this->traspasar('120', clave: 'el-mismo');

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, CashToBankTransfer::query()->count());
    }

    public function test_another_cash_box_does_not_transfer_in_two_steps(): void
    {
        $this->assertRechazaEn('amount', fn () => app(StartCashTransfer::class)->handle(
            $this->cajaDe(CashBox::MULTAS), Currency::Ars, '100', BusinessDate::today(), null, null, 'clave', $this->admin->id,
        ), 'dos pasos');
    }

    // ── El día sigue ──────────────────────────────────────────────────────

    /** A87: el arqueo coincide, el día se cierra y el comprobante llega después sin reabrirlo. */
    public function test_a_pending_ticket_does_not_stop_the_day(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('100');

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja,
            countedOn: BusinessDate::today(),
            denominations: [20 => 1],
            actorId: $this->admin->id,
        );
        $this->assertTrue($arqueo->isBalanced());
        app(ReviewCashCount::class)->handle($arqueo, $this->admin->id);

        $cierre = app(ClosePeriod::class)->handle($this->caja, BusinessDate::today(), actorId: $this->admin->id);

        $this->assertSame('20.00', $cierre->closing_cash);
        $this->assertSame('100.00', $cierre->deposited_to_bank_cash);

        // Al otro día: se cobra y llega el comprobante, con la caja de ayer cerrada.
        $this->travelTo(BusinessDate::today()->addDay()->setTime(10, 0));
        $this->cobrarArancel();

        $this->assertSame(CashTransferStatus::Deposited, $this->cargarComprobante($traslado)->status);
        $this->assertSame('140.00', $this->saldo(LedgerAccount::CashOnHand));
        $this->assertSame('closed', $cierre->refresh()->status->value);
    }

    // ── Comprobante ───────────────────────────────────────────────────────

    public function test_the_ticket_must_say_the_amount_of_the_transfer(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        $this->assertRechazaEn('amount', fn () => $this->cargarComprobante($traslado, '119.99'), 'cancelá el traspaso');
        $this->assertSame(CashTransferStatus::AwaitingTicket, $traslado->refresh()->status);
    }

    /** Un traspaso cancelado por error se registra de nuevo hoy, y el papel conserva su fecha. */
    public function test_the_ticket_can_be_older_than_the_transfer(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(5));
        $cancelado = $this->traspasar('120', $hoy->subDays(3));
        app(CancelCashToBankTransfer::class)->handle($cancelado, 'Se canceló por error', 'cancelar', $this->admin->id);

        $traslado = $this->cargarComprobante($this->traspasar('120'), fecha: $hoy->subDays(3));

        $this->assertSame(CashTransferStatus::Deposited, $traslado->status);
        $this->assertSame($hoy->subDays(3)->toDateString(), $traslado->bankDate()->toDateString());
    }

    public function test_the_ticket_cannot_be_older_than_the_cash_it_carries(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(5));
        $this->cobrarArancel($hoy->subDays(2));
        $traslado = $this->traspasar('240');

        $this->assertRechazaEn('depositDate', fn () => $this->cargarComprobante($traslado, fecha: $hoy->subDays(3)), 'anterior al efectivo');
        $this->assertSame(CashTransferStatus::Deposited, $this->cargarComprobante($traslado, fecha: $hoy->subDays(2))->status);
    }

    public function test_the_ticket_is_loaded_once(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        $this->cargarComprobante($traslado, clave: 'la-misma');
        $this->assertSame(CashTransferStatus::Deposited, $this->cargarComprobante($traslado, clave: 'la-misma')->status);
        $this->assertSame(1, DB::table('attachments')->where('subject_type', 'cash_transfer')->count());

        $this->assertRechazaEn('transferId', fn () => $this->cargarComprobante($traslado, clave: 'otra'), 'ya tiene su comprobante');
    }

    // ── Acreditar ─────────────────────────────────────────────────────────

    public function test_it_is_credited_only_with_the_ticket_and_the_rest_stays_in_the_box(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('100');
        $credito = $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '100.00');

        $this->assertRechazaEn('transferId', fn () => app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, 'a', $this->admin->id), 'comprobante');

        $traslado = $this->cargarComprobante($traslado);
        $acreditado = app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, 'a', $this->admin->id);

        $this->assertSame(CashTransferStatus::BankConfirmed, $acreditado->status);
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CashInTransit));
        $this->assertSame('20.00', $this->saldo(LedgerAccount::CashOnHand));
    }

    public function test_the_credit_is_searched_around_the_date_of_the_ticket(): void
    {
        $this->abrir();
        $hace = BusinessDate::today()->subDays(8);
        $this->cobrarArancel($hace);
        $traslado = $this->traspasar('120', $hace);
        $traslado = $this->cargarComprobante($traslado);
        $credito = $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '120.00');

        $candidatos = app(FindCashDepositCandidates::class)->handle($traslado);

        $this->assertSame([$credito->id], array_map(fn ($c) => $c->transaction->id, $candidatos));
    }

    // ── Cancelar y corregir ───────────────────────────────────────────────

    public function test_cancelling_frees_the_whole_transfer_with_or_without_a_ticket(): void
    {
        $this->abrir();
        $this->cobrarArancel();

        $sinComprobante = $this->traspasar('120');
        app(CancelCashToBankTransfer::class)->handle($sinComprobante, 'Se cargó de más', 'c1', $this->admin->id);
        $this->assertSame('120.00', $this->saldo(LedgerAccount::CashOnHand));

        $conComprobante = $this->cargarComprobante($this->traspasar('120'));
        app(CancelCashToBankTransfer::class)->handle($conComprobante, 'El comprobante era otro', 'c2', $this->admin->id);

        $this->assertSame('120.00', $this->saldo(LedgerAccount::CashOnHand));
        $this->assertSame('0.00', $this->saldo(LedgerAccount::CashInTransit));
        $this->assertSame('120.00', $this->traspasar('120')->amount);
    }

    public function test_transferred_cash_cannot_be_voided_until_it_is_cancelled(): void
    {
        $this->abrir();
        $cobro = $this->cobrarArancel();
        $traslado = $this->traspasar('50');

        $this->assertRechazaEn('reason', fn () => app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id), 'salió de la caja');

        app(CancelCashToBankTransfer::class)->handle($traslado, 'Se cargó por error', 'c', $this->admin->id);
        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $this->assertSame(FeeChargeStatus::Voided, $cobro->refresh()->status);
    }

    public function test_cancelling_lets_the_charge_be_corrected(): void
    {
        $this->abrir();
        $cobro = $this->cobrarArancel();
        $traslado = $this->traspasar('120');
        // Notificaciones: otro importe.
        $otro = [['feeTypeId' => FeeType::query()->where('code', 25)->value('id'), 'quantity' => 1]];

        $this->assertRechazaEn('items', fn () => app(CorrectFeeCharge::class)->handle($cobro, 'AG', null, $otro, new Reason(FeeReasonCode::WrongAmount, null), null, $this->admin->id, true), 'cancelá el traspaso');

        app(CancelCashToBankTransfer::class)->handle($traslado, 'Hay que corregir', 'c', $this->admin->id);
        $nuevo = app(CorrectFeeCharge::class)->handle($cobro, 'AG', null, $otro, new Reason(FeeReasonCode::WrongAmount, null), null, $this->admin->id, true);

        $this->assertSame(FeeChargeStatus::Paid, $nuevo->status);
        $this->assertSame('50.00', $this->traspasar('50')->amount);
    }

    // ── La base ───────────────────────────────────────────────────────────

    public function test_the_database_keeps_the_money_within_the_receipt(): void
    {
        $this->abrir();
        $cobro = $this->cobrarArancel();
        $recepcion = $this->recepcionDe($cobro);
        $traslado = $this->traspasar('120');

        $this->assertLaBaseRechaza(fn () => CashToBankTransferItem::query()->create([
            'cash_to_bank_transfer_id' => $traslado->id, 'fund_receipt_id' => $recepcion->id, 'amount' => '0.01',
        ]), 'ya se depositó');
    }

    #[DataProvider('cajasCruzadas')]
    public function test_the_database_keeps_a_transfer_within_its_own_box(string $cajaDelTraslado, string $cajaDeLaRecepcion): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $this->abrirLibrosSinSaldo();
        $recepcion = app(RegisterCashFundReceipt::class)->handle('100', 'otra-caja', $this->cajaDe(CashBox::HABERES), BusinessDate::today(), actorId: $this->admin->id);
        $recepciones = [
            CashBox::ARANCELES => FundReceipt::query()->where('cash_box_id', $this->caja)->firstOrFail(),
            CashBox::HABERES => $recepcion,
        ];
        $traslado = $this->traspasar('10');

        if ($cajaDelTraslado === CashBox::HABERES) {
            $evento = app(PostJournalEntry::class)->handle(
                type: FinancialEventType::FundsReceived,
                idempotencyKey: 'evento-del-traslado',
                lines: [
                    EntryLine::debit(LedgerAccount::CashOnHand, '10')->in(Currency::Ars)->onCashBox($this->cajaDe(CashBox::HABERES)),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, '10')->in(Currency::Ars)->onCashBox($this->cajaDe(CashBox::HABERES)),
                ],
                date: BusinessDate::today(),
                cashBoxId: $this->cajaDe(CashBox::HABERES),
                actorId: $this->admin->id,
            );
            $traslado = CashToBankTransfer::query()->create([
                'deposit_event_id' => $evento->id, 'cash_box_id' => $this->cajaDe(CashBox::HABERES), 'bank_account_id' => $traslado->bank_account_id,
                'amount' => '10', 'deposit_date' => BusinessDate::today(), 'status' => CashTransferStatus::Deposited,
            ]);
        }

        $this->assertLaBaseRechaza(fn () => CashToBankTransferItem::query()->create([
            'cash_to_bank_transfer_id' => $traslado->id, 'fund_receipt_id' => $recepciones[$cajaDeLaRecepcion]->id, 'amount' => '1',
        ]), 'de su propia caja');
    }

    /** @return array<string, array{string, string}> */
    public static function cajasCruzadas(): array
    {
        return [
            'efectivo de Haberes en un traslado de Aranceles' => [CashBox::ARANCELES, CashBox::HABERES],
            'efectivo de Aranceles en un traslado de Haberes' => [CashBox::HABERES, CashBox::ARANCELES],
        ];
    }

    public function test_the_database_keeps_the_ticket_after_the_cash(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $this->cobrarArancel($hoy->subDays(2));
        $traslado = $this->traspasar('120');

        $this->assertLaBaseRechaza(fn () => $traslado->forceFill([
            'status' => CashTransferStatus::Deposited,
            'ticket_date' => $hoy->subDays(3),
            'ticket_loaded_at' => now(),
            'ticket_loaded_by' => $this->admin->id,
            'ticket_idempotency_key' => 'antes-del-cobro',
        ])->save(), 'anterior al efectivo');
    }

    public function test_the_database_keeps_the_items_equal_to_the_transfer(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('100');
        $recepcion = CashToBankTransferItem::query()->where('cash_to_bank_transfer_id', $traslado->id)->value('fund_receipt_id');

        $this->assertLaBaseRechaza(fn () => CashToBankTransferItem::query()->create([
            'cash_to_bank_transfer_id' => $traslado->id, 'fund_receipt_id' => $recepcion, 'amount' => '10',
        ]), 'sus ítems suman');
    }

    public function test_the_database_keeps_a_transferred_receipt_from_being_reversed(): void
    {
        $this->abrir('100');
        $apertura = FundReceipt::query()->where('cash_box_id', $this->caja)->where('origin', FundReceiptOrigin::Opening)->firstOrFail();
        $this->traspasar('100');

        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: 'reversion-apertura',
            lines: [
                EntryLine::debit(LedgerAccount::LegacyFunds, '100')->in(Currency::Ars)->onCashBox($this->caja),
                EntryLine::credit(LedgerAccount::CashInTransit, '100')->in(Currency::Ars)->onCashBox($this->caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $this->caja,
            actorId: $this->admin->id,
            reversalOfId: $apertura->financial_event_id,
            reversalReason: 'Prueba',
        );

        $this->assertLaBaseRechaza(fn () => $apertura->forceFill([
            'reversal_event_id' => $evento->id, 'reversed_at' => now(), 'reversed_by' => $this->admin->id, 'reversal_reason' => 'Prueba',
        ])->save(), 'viajó en un traslado vigente');
    }

    public function test_the_database_requires_the_two_steps_of_the_box(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        $this->assertLaBaseRechaza(fn () => CashToBankTransfer::query()->create([
            'deposit_event_id' => $traslado->deposit_event_id, 'cash_box_id' => $this->caja, 'bank_account_id' => $traslado->bank_account_id,
            'amount' => '1', 'deposit_date' => BusinessDate::today(), 'status' => CashTransferStatus::Deposited,
        ]), 'no nace deposited');

        $this->assertLaBaseRechaza(fn () => $traslado->forceFill([
            'status' => CashTransferStatus::BankConfirmed,
            'credit_event_id' => $traslado->deposit_event_id,
        ])->save(), 'no pasa a bank_confirmed');
    }

    public function test_the_database_requires_the_photo_with_the_ticket(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        $this->assertLaBaseRechaza(fn () => $traslado->forceFill([
            'status' => CashTransferStatus::Deposited,
            'ticket_date' => BusinessDate::today(),
            'ticket_loaded_at' => now(),
            'ticket_loaded_by' => $this->admin->id,
            'ticket_idempotency_key' => 'sin-foto',
        ])->save(), 'no tiene su foto');

        $this->assertLaBaseRechaza(fn () => $traslado->refresh()->forceFill([
            'status' => CashTransferStatus::Deposited,
        ])->save(), 'una sola vez');
    }

    public function test_the_database_freezes_a_loaded_ticket(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->cargarComprobante($this->traspasar('120'));

        $this->assertLaBaseRechaza(fn () => $traslado->forceFill(['deposit_operation_number' => '1'])->save(), 'no se edita');
        $this->assertLaBaseRechaza(fn () => $traslado->refresh()->forceFill(['ticket_date' => BusinessDate::today()->addDay()])->save(), 'no se edita');
    }

    public function test_haberes_and_multas_keep_starting_as_deposited(): void
    {
        $this->assertFalse((bool) CashBox::query()->where('code', CashBox::HABERES)->value('deposits_in_two_steps'));
        $this->assertFalse((bool) CashBox::query()->where('code', CashBox::MULTAS)->value('deposits_in_two_steps'));
        $this->assertTrue((bool) CashBox::query()->where('code', CashBox::ARANCELES)->value('deposits_in_two_steps'));
    }

    // ── Pantalla ──────────────────────────────────────────────────────────

    public function test_the_screen_shows_what_is_available_and_where_it_comes_from(): void
    {
        $this->abrir('100');
        $cobro = $this->cobrarArancel();
        $this->traspasar('150');

        $this->actingAs($this->admin)->get(route('aranceles.depositos.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/depositos', false)
                ->where('tab', 'comprobante')
                ->where('available', '70.00')
                ->has('sinComprobante', 1)
                ->where('sinComprobante.0.amount', '150.00')
                ->where('sinComprobante.0.shares.0.opening', true)
                ->where('sinComprobante.0.shares.1.amount', '50.00')
                ->where('sinComprobante.0.shares.1.payerName', 'AG Max SRL')
                ->where('sinComprobante.0.shares.1.receiptNumber', $cobro->chargeReceipts()->with('receipt')->firstOrFail()->receipt->formatted_number)
                ->where('can.deposit', true));

        $this->actingAs($this->admin)
            ->getJson(route('aranceles.depositos.reparto', ['transferDate' => BusinessDate::today()->toDateString(), 'amount' => '71']))
            ->assertOk()
            ->assertJsonPath('available', '70.00')
            ->assertJsonPath('exceeds', true)
            ->assertJsonPath('shares.0.amount', '70.00');
    }

    public function test_the_whole_circuit_through_the_screens(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $hoy = BusinessDate::today()->toDateString();

        $this->actingAs($this->admin)->post(route('aranceles.depositos.store'), [
            'transferDate' => $hoy, 'amount' => '120', 'idempotencyKey' => 'pantalla',
        ])->assertRedirect(route('aranceles.depositos.index', ['pestana' => 'comprobante']));

        $traslado = CashToBankTransfer::query()->firstOrFail();

        $this->actingAs($this->admin)->get(route('aranceles.depositos.comprobante.create', $traslado))
            ->assertInertia(fn (Assert $page) => $page->component('aranceles/comprobante-de-deposito', false));

        $this->actingAs($this->admin)->post(route('aranceles.depositos.comprobante.store', $traslado), [
            'amount' => '120', 'depositDate' => $hoy, 'ticket' => UploadedFile::fake()->image('c.jpg'), 'idempotencyKey' => 'pantalla-2',
        ])->assertRedirect(route('aranceles.depositos.index', ['pestana' => 'transito']));

        $credito = $this->movimiento($this->banco, $hoy, '120.00');

        $this->actingAs($this->admin)->getJson(route('aranceles.depositos.candidates', $traslado))
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $credito->id);

        $this->actingAs($this->admin)->post(route('aranceles.depositos.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => 'pantalla-3',
        ])->assertRedirect();

        $this->assertSame(CashTransferStatus::BankConfirmed, $traslado->refresh()->status);
    }

    public function test_each_act_needs_its_permission(): void
    {
        $this->abrir();
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');
        $hoy = BusinessDate::today()->toDateString();

        $consulta = $this->operador('consulta');
        $this->actingAs($consulta)->post(route('aranceles.depositos.cancel', $traslado), ['reason' => 'No corresponde', 'idempotencyKey' => 'x'])->assertForbidden();

        // Decisión del usuario: quien hace el traspaso también lo puede cancelar.
        $administrativo = $this->operador('administrativo');
        $this->actingAs($administrativo)->post(route('aranceles.depositos.cancel', $traslado), ['reason' => 'No corresponde', 'idempotencyKey' => 'x'])->assertRedirect();
        $this->assertSame(CashTransferStatus::Cancelled, $traslado->refresh()->status);

        $otro = $this->traspasar('120');
        $this->actingAs($administrativo)->post(route('aranceles.depositos.confirm', $otro), ['bankTransactionId' => 1, 'idempotencyKey' => 'z'])->assertForbidden();

        $contador = $this->operador('contador');
        $this->actingAs($contador)->post(route('aranceles.depositos.store'), ['transferDate' => $hoy, 'amount' => '1', 'idempotencyKey' => 'y'])->assertForbidden();
        $this->actingAs($contador)->get(route('aranceles.depositos.comprobante.create', $otro))->assertForbidden();
    }

    public function test_a_transfer_of_another_box_does_not_exist_here(): void
    {
        $cuenta = $this->cuenta('310000111222333');
        $this->abrirMultas($cuenta, BusinessDate::today()->subDays(5)->toDateString());
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'ajeno',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '10')->in(Currency::Ars)->onCashBox($this->cajaDe(CashBox::MULTAS)),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '10')->in(Currency::Ars)->onCashBox($this->cajaDe(CashBox::MULTAS)),
            ],
            date: BusinessDate::today(),
            cashBoxId: $this->cajaDe(CashBox::MULTAS),
            actorId: $this->admin->id,
        );
        $ajeno = CashToBankTransfer::query()->create([
            'deposit_event_id' => $evento->id, 'cash_box_id' => $this->cajaDe(CashBox::MULTAS), 'bank_account_id' => $cuenta->id,
            'amount' => '10', 'deposit_date' => BusinessDate::today(), 'status' => CashTransferStatus::Deposited,
        ]);

        $this->actingAs($this->admin)->get(route('aranceles.depositos.comprobante.create', $ajeno))->assertNotFound();
        $this->actingAs($this->admin)->getJson(route('aranceles.depositos.candidates', $ajeno))->assertNotFound();
    }
}
