<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La caja del día de Multas (Etapa 6b): las pantallas de Caja con la caja de
 * Multas, sus permisos propios y su planilla.
 */
class PantallasDeCajaDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    /** Contar, revisar, cerrar el día, bajar la planilla, reabrir y cerrar el mes. */
    public function test_the_multas_box_counts_closes_and_reopens_from_its_screens(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
        $contador = $this->operador('contador');

        $this->actingAs($this->operador())->post('/multas/caja/arqueos', [
            'cashBoxId' => $this->multas(),
            'countedOn' => '2026-09-15',
            'currency' => 'ARS',
            'denominations' => [1000 => 5],
        ])->assertSessionHasNoErrors();
        $arqueo = CashCount::query()->sole();

        $this->actingAs($contador)->post("/multas/caja/arqueos/{$arqueo->id}/revisar")->assertSessionHasNoErrors();
        $this->cerrar($contador, '2026-09-15', 'daily');

        $dia = PeriodClosing::query()->where('period_type', 'daily')->sole();
        expect($dia->cash_box_id)->toBe($this->multas())
            ->and($dia->closing_cash)->toBe('5000.00');

        $this->actingAs($contador)->get("/multas/caja/cierres/{$dia->id}/planilla")->assertRedirect();
        expect($dia->refresh()->sheet_attachment_id)->not->toBeNull();

        $this->actingAs($contador)->post("/multas/caja/cierres/{$dia->id}/reabrir", ['reason' => 'Revisar el arqueo del día'])
            ->assertSessionHasNoErrors();
        expect($dia->refresh()->status)->toBe(PeriodClosingStatus::Reopened);

        $this->arqueoListoParaCerrar($this->multas(), '2026-09-15', $contador);
        $this->cerrar($contador, '2026-09-15', 'daily');
        $this->cerrar($contador, '2026-09-30', 'monthly');

        $mes = PeriodClosing::query()->where('period_type', 'monthly')->sole();
        expect($mes->status)->toBe(PeriodClosingStatus::Closed)
            ->and($mes->received_cash)->toBe('5000.00');
    }

    /** Las pantallas dicen de qué caja son, y cada recibo abre su multa. */
    public function test_the_day_screen_is_the_multas_box_and_links_each_receipt_to_its_fine(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');

        $this->actingAs($this->operador('contador'))
            ->get('/multas/caja/dia?fecha=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->component('caja/index')
                ->where('selected.cashBoxCode', CashBox::MULTAS)
                ->where('selected.cashBoxId', $this->multas())
                ->where('book.income.0.cash', '5000.00')
                ->where('bookLinks.'.$this->reciboDe($multa), "/multas/{$multa->id}")
                ->where('can.close', true)
                ->where('can.open', false));

        foreach (['/multas/caja/arqueos', '/multas/caja/cierres', '/multas/caja/calendario'] as $pantalla) {
            $this->actingAs($this->operador('consulta'))
                ->get($pantalla)
                ->assertInertia(fn (Assert $page) => $page->where('selected.cashBoxCode', CashBox::MULTAS));
        }
    }

    /** Cerrar una caja no habilita a cerrar la otra, ni en la ruta ni en los botones. */
    public function test_each_box_is_closed_with_its_own_permission(): void
    {
        $this->escenario();

        $soloHaberes = User::factory()->create();
        $soloHaberes->givePermissionTo(['caja.ver', 'cierres.ver', 'cierres.cerrar']);
        $soloMultas = User::factory()->create();
        $soloMultas->givePermissionTo(['multas.caja.ver', 'multas.caja.cerrar']);

        $cierre = ['date' => '2026-09-15', 'periodType' => 'daily', 'currency' => 'ARS'];

        $this->actingAs($soloHaberes)->get('/multas/caja/dia')->assertForbidden();
        $this->actingAs($soloHaberes)->post('/multas/caja/cierres', $cierre)->assertForbidden();
        $this->actingAs($soloMultas)->get('/caja/dia')->assertForbidden();
        $this->actingAs($soloMultas)->post('/caja/cierres', $cierre)->assertForbidden();

        $this->actingAs($soloMultas)
            ->get('/multas/caja/dia')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.close', true)
                ->where('can.count', false)
                ->where('can.export', false));

        $this->actingAs($soloHaberes)
            ->get('/caja/dia')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.close', true)
                ->where('can.count', false));
    }

    /** La cartera de hoy, con la multa que paga cada cheque. */
    public function test_the_cheque_inventory_names_the_fine_of_each_cheque(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => 'cheque', 'amount' => '20000.00',
            'cheque' => ['number' => 'A-0001', 'bank' => 'Banco Nación', 'issueDate' => null],
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operador('consulta'))
            ->getJson('/multas/caja/cheques-en-custodia')
            ->assertOk()
            ->assertJsonPath('cheques.0.number', 'A-0001')
            ->assertJsonPath('cheques.0.fineId', $multa->id)
            ->assertJsonPath('cheques.0.caption', 'CRISTOMAX S.A.S')
            ->assertJsonPath('total', '20000.00');
    }

    /** Un crédito de la cuenta compartida sin identificar no frena el cierre de Multas. */
    public function test_an_unidentified_credit_does_not_block_the_closing(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');
        $this->movimiento($this->laCuenta, '2026-09-15', '7300.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->multas(), '2026-09-15', $contador);
        $this->cerrar($contador, '2026-09-15', 'daily');

        expect(PeriodClosing::query()->sole()->status)->toBe(PeriodClosingStatus::Closed);
    }

    /** Lo juzgado fuera de término queda así: reabrir el mes no lo reclasifica (§6.5). */
    public function test_reopening_the_month_does_not_reclassify_a_late_payment(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00'));
        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->multas(), '2026-06-30', $contador);
        $this->cerrar($contador, '2026-06-30', 'monthly');

        $pago = $this->cobrarEfectivo($multa, '1000.00');
        expect($pago->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);

        app(ReopenPeriod::class)->handle(
            PeriodClosing::query()->where('period_type', 'monthly')->sole(),
            $contador->id,
            'Corregir el cierre de junio',
        );

        expect($pago->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);
    }

    /** El período cerrado de Multas lleva a los cierres de Multas, a quien puede verlos. */
    public function test_a_closed_period_notice_belongs_to_its_box(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-07-01 10:00'));
        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->multas(), '2026-06-30', $contador);
        $this->cerrar($contador, '2026-06-30', 'monthly');

        $this->actingAs($this->operador())
            ->from("/multas/{$multa->id}")
            ->post(route('multas.cobros.store', $multa), [
                'medium' => 'cash', 'amount' => '1000.00', 'receivedOn' => '2026-06-20',
                'talonarioNumber' => '18467', 'idempotencyKey' => (string) Str::uuid(),
            ])
            ->assertSessionHas('closedPeriod.cashBoxCode', CashBox::MULTAS);

        $soloHaberes = User::factory()->create();
        $soloHaberes->givePermissionTo(['multas.ver', 'cierres.ver']);

        $this->actingAs($soloHaberes)
            ->withSession(['closedPeriod' => ['cashBoxCode' => CashBox::MULTAS, 'periodType' => 'Mensual', 'from' => '2026-06-01', 'to' => '2026-06-30', 'attemptedOn' => '2026-06-20', 'message' => 'Cerrado']])
            ->get("/multas/{$multa->id}")
            ->assertInertia(fn (Assert $page) => $page->where('flash.closedPeriod.canSeeClosings', false));

        $this->actingAs($this->operador('consulta'))
            ->withSession(['closedPeriod' => ['cashBoxCode' => CashBox::MULTAS, 'periodType' => 'Mensual', 'from' => '2026-06-01', 'to' => '2026-06-30', 'attemptedOn' => '2026-06-20', 'message' => 'Cerrado']])
            ->get("/multas/{$multa->id}")
            ->assertInertia(fn (Assert $page) => $page->where('flash.closedPeriod.canSeeClosings', true));
    }

    /** Multas lleva solo pesos: un libro en dólares no se mira ni se opera por la dirección. */
    public function test_the_multas_box_has_no_dollar_book(): void
    {
        $this->escenario();
        $contador = $this->operador('contador');

        foreach (['dia', 'arqueos', 'cierres', 'calendario'] as $pantalla) {
            $this->actingAs($contador)
                ->get("/multas/caja/{$pantalla}?moneda=usd")
                ->assertInertia(fn (Assert $page) => $page
                    ->where('selected.currency', 'ARS')
                    ->where('selected.currencies', ['ARS']));
        }

        $this->actingAs($contador)->post('/multas/caja/arqueos', [
            'cashBoxId' => $this->multas(), 'countedOn' => '2026-06-02', 'currency' => 'USD', 'denominations' => [],
        ])->assertSessionHasErrors('currency');
        $this->actingAs($contador)->post('/multas/caja/cierres', [
            'date' => '2026-06-01', 'periodType' => 'daily', 'currency' => 'USD',
        ])->assertSessionHasErrors('currency');

        expect(CashCount::query()->count())->toBe(0)
            ->and(PeriodClosing::query()->count())->toBe(0);

        // Haberes conserva sus dos libros.
        $this->actingAs($contador)
            ->get('/caja/dia?moneda=usd')
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected.currency', 'USD')
                ->where('selected.currencies', ['ARS', 'USD']));
    }

    /** Quien solo opera la caja no recibe enlaces a fichas que no puede abrir. */
    public function test_without_viewing_fines_the_box_shows_no_links_to_them(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => 'cheque', 'amount' => '20000.00',
            'cheque' => ['number' => 'A-0001', 'bank' => 'Banco Nación', 'issueDate' => null],
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $soloCaja = User::factory()->create();
        $soloCaja->givePermissionTo('multas.caja.ver');

        $this->actingAs($soloCaja)
            ->get('/multas/caja/dia?fecha=2026-09-15')
            ->assertInertia(fn (Assert $page) => $page
                ->where('book.income.0.cash', '5000.00')
                ->where('bookLinks', [])
                ->where('breadcrumbs.0.title', 'Multas')
                ->where('breadcrumbs.0.href', null)
                ->where('breadcrumbs.1.href', null));

        $this->actingAs($soloCaja)
            ->getJson('/multas/caja/cheques-en-custodia')
            ->assertJsonPath('cheques.0.caption', 'CRISTOMAX S.A.S')
            ->assertJsonPath('canOpenFines', false);

        $this->actingAs($soloCaja)->get("/multas/{$multa->id}")->assertForbidden();

        $this->actingAs($this->operador('consulta'))
            ->getJson('/multas/caja/cheques-en-custodia')
            ->assertJsonPath('canOpenFines', true);
    }

    /** Inicio recuerda los días y los meses de Multas sin cerrar, a quien la cierra. */
    public function test_the_dashboard_lists_the_multas_days_and_months_left_open(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $colas = collect($this->colasDeInicio($this->operador('contador')))->keyBy('key');

        expect($colas['multas_caja_sin_cerrar']['count'])->toBe(1)
            ->and($colas['multas_caja_sin_cerrar']['href'])->toBe('/multas/caja/cierres')
            ->and($colas['multas_caja_mes_sin_cerrar']['count'])->toBe(1);

        $administrativo = collect($this->colasDeInicio($this->operador()))->pluck('key');
        expect($administrativo)->not->toContain('multas_caja_sin_cerrar');
    }

    /** @return list<array<string, mixed>> */
    private function colasDeInicio(User $usuario): array
    {
        $version = $this->actingAs($usuario)->get(route('inicio'))->assertOk()->viewData('page')['version'];

        return $this->actingAs($usuario)->get(route('inicio'), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'inicio',
            'X-Inertia-Partial-Data' => 'queues',
            'X-Inertia-Version' => $version,
        ])->json('props.queues') ?? [];
    }

    private function escenario(): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00'));
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-06-02',
            'amount' => '100000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function cobrarEfectivo(Fine $multa, string $importe): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => 'cash', 'amount' => $importe, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function cerrar(User $quien, string $fecha, string $tipo): void
    {
        $this->actingAs($quien)->post('/multas/caja/cierres', [
            'date' => $fecha, 'periodType' => $tipo, 'currency' => 'ARS',
        ])->assertSessionHasNoErrors();
    }

    private function reciboDe(Fine $multa): int
    {
        $pago = FinePayment::query()->where('fine_id', $multa->id)->latest('id')->firstOrFail();

        return (int) FinePaymentReceipt::query()->where('fine_payment_id', $pago->id)->value('receipt_id');
    }

    private function multas(): int
    {
        return $this->cajaDe(CashBox::MULTAS);
    }
}
