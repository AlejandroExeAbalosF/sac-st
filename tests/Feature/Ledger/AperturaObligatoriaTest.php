<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Exceptions\CashBookNotOpenedException;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La caja no opera sin apertura, libro por libro.
 *
 * Pesos y dólares son dos libros sobre el mismo cajón. Sin la apertura de
 * uno, el saldo teórico arranca en cero y el primer arqueo daría una
 * diferencia igual a todo lo que ya estaba: por eso ese libro no admite
 * movimientos, arqueos ni cierres. Cada regla se prueba en los dos lados:
 * el mensaje del Action y el rechazo de la base.
 */
class AperturaObligatoriaTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────── El Action lo dice ─────────────────────────

    public function test_sin_apertura_no_se_asienta(): void
    {
        try {
            $this->cobrar('1000.00', '2026-06-02');
            $this->fail('Se asentó un movimiento en un libro sin apertura.');
        } catch (CashBookNotOpenedException $e) {
            $this->assertSame(Currency::Ars, $e->currency);
            $this->assertNull($e->openedOn);
            $this->assertStringContainsString('en pesos', $e->getMessage());
        }

        $this->assertSame(0, FinancialEvent::query()->count());
    }

    public function test_abrir_los_pesos_no_abre_los_dolares(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-01');

        $this->expectException(CashBookNotOpenedException::class);
        $this->expectExceptionMessage('en dólares');

        $this->cobrar('50.00', '2026-06-02', Currency::Usd);
    }

    public function test_no_se_asienta_antes_de_la_apertura_y_el_mismo_dia_si(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-01');

        $this->cobrar('200.00', '2026-06-01');

        try {
            $this->cobrar('200.00', '2026-05-31');
            $this->fail('Se asentó un movimiento anterior a la apertura.');
        } catch (CashBookNotOpenedException $e) {
            $this->assertSame('2026-06-01', $e->openedOn?->toDateString());
            $this->assertStringContainsString('01/06/2026', $e->getMessage());
        }

        $this->assertSame('1200.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja()));
    }

    public function test_sin_apertura_no_se_arquea(): void
    {
        $this->expectException(CashBookNotOpenedException::class);

        app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [],
        );
    }

    /**
     * El primer día del sistema, como lo trabaja el área.
     *
     * A la mañana se abre con el cierre manual del día anterior —contando
     * el cajón—, durante el día se cobra, y a la noche se arquea y se
     * cierra. El arqueo de la noche es un segundo turno: el de la apertura
     * ya contó el fajo, que se arrastra sin recontar, y lo único que se
     * cuenta es lo que entró en el día.
     */
    public function test_el_dia_de_la_apertura_se_opera_se_arquea_y_se_cierra(): void
    {
        // Como desde la pantalla: con quien abre, que deja revisado el conteo.
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '1000.00'],
            date: CarbonImmutable::parse('2026-06-10'),
            actorId: $this->operador('administrador')->id,
            denominations: $this->billetesPara('1000.00'),
        );
        $this->cobrarEnEfectivo('500.00', '2026-06-10');

        /*
         * Cada paso se confirma por separado, como en producción: la base
         * acepta la apertura y el cobro del mismo día antes del cierre.
         */
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $arqueo = $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        $this->assertSame(2, $arqueo->sequence);
        $this->assertSame('500.00', $arqueo->counted_amount);
        $this->assertSame('1000.00', $arqueo->uncounted_amount);
        $this->assertTrue($arqueo->isBalanced());

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);
        $this->assertSame('1500.00', $cierre->closing_cash);
    }

    /** El caso de la Caja del día abierta en una fecha del calendario anterior a la apertura. */
    public function test_no_se_arquea_ni_se_cierra_un_dia_anterior_a_la_apertura(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-10');

        try {
            app(RecordCashCount::class)->handle(
                cashBoxId: $this->caja(),
                countedOn: CarbonImmutable::parse('2026-06-09'),
                denominations: [],
            );
            $this->fail('Se arqueó un día anterior a la apertura.');
        } catch (CashBookNotOpenedException $e) {
            $this->assertSame('2026-06-10', $e->openedOn?->toDateString());
        }

        $this->expectException(CashBookNotOpenedException::class);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-09'),
        );
    }

    public function test_sin_apertura_no_se_cierra(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-01');

        $this->expectException(CashBookNotOpenedException::class);
        $this->expectExceptionMessage('en dólares');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            currency: Currency::Usd,
        );
    }

    // ───────────────────────── Cómo se abre ─────────────────────────

    public function test_los_dolares_se_abren_con_efectivo_igual_que_los_pesos(): void
    {
        $apertura = app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '500.00'],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
            denominations: [100 => 5],
        );

        $this->assertSame('500.00', $apertura->declared_total);
        $this->assertFalse($apertura->isEmpty());
        $this->assertSame('500.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja(), Currency::Usd));

        // Y desde ahí el libro en dólares opera.
        $this->cobrar('50.00', '2026-06-02', Currency::Usd);

        $this->assertSame('550.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja(), Currency::Usd));
    }

    /**
     * Antes fallaba: con los cheques detallados, el asiento principal se
     * quedaba con una sola línea de crédito en cero.
     */
    public function test_se_abre_declarando_solo_cheques_detallados(): void
    {
        $apertura = app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::ChequesInCustody->value => '300.00'],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
            cheques: [
                ['number' => '001', 'bank' => 'Macro', 'issueDate' => '2026-05-20', 'amount' => '100.00'],
                ['number' => '002', 'bank' => 'Nación', 'issueDate' => '2026-05-21', 'amount' => '200.00'],
            ],
        );

        $this->assertSame('300.00', $apertura->declared_total);
        $this->assertSame(2, FinancialEvent::query()->where('event_type', FinancialEventType::OpeningBalance)->count());
        $this->assertSame(2, FundReceipt::query()->where('origin', FundReceiptOrigin::Opening)->count());
        $this->assertSame('300.00', app(CashBalance::class)->of(LedgerAccount::ChequesInCustody, $this->caja(), Currency::Usd));

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_se_abre_sin_saldo_cuando_se_declara(): void
    {
        $apertura = app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
            declaredEmpty: true,
        );

        $this->assertTrue($apertura->isEmpty());
        $this->assertSame(0, FinancialEvent::query()->count());

        // El primer dólar es el cobro que recién entra.
        $this->cobrar('80.00', '2026-06-02', Currency::Usd);

        $this->assertSame('80.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja(), Currency::Usd));

        // Y la base, al confirmar, acepta las dos cosas.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_todo_en_cero_sin_declararlo_no_abre(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('que no había dólares al abrir');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '0'],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
        );
    }

    public function test_declarar_que_no_habia_nada_con_saldo_cargado_no_abre(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('hay saldos cargados');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '100.00'],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
            denominations: [100 => 1],
            declaredEmpty: true,
        );
    }

    public function test_los_depositos_directos_van_en_una_cuenta_de_su_moneda(): void
    {
        $enPesos = BankAccount::query()->create([
            'label' => 'Cta. Cte. — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no es en dólares');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::BankAccount->value => '100.00'],
            date: CarbonImmutable::parse('2026-06-01'),
            currency: Currency::Usd,
            bankAccountId: (int) $enPesos->id,
        );
    }

    public function test_una_apertura_no_se_rehace(): void
    {
        $this->abrirLibrosSinSaldo(Currency::Usd);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no se rehace');

        $this->abrirLibrosSinSaldo(Currency::Usd);
    }

    // ───────────────────────── La base lo impide ─────────────────────────

    public function test_la_base_rechaza_un_asiento_sin_apertura(): void
    {
        $this->asientoCrudo('2026-06-02', Currency::Usd);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('todavia no tiene apertura');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_rechaza_un_asiento_anterior_a_la_apertura(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-01');

        /*
         * La apertura se da por confirmada, como en producción: si sus
         * guardas siguieran pendientes, la que rechazaría el asiento sería
         * la de la apertura y no la del movimiento.
         */
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');

        $this->asientoCrudo('2026-05-31', Currency::Ars);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no admite movimientos con fecha 2026-05-31');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_rechaza_un_arqueo_sin_apertura(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se puede arquear');

        DB::table('cash_counts')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'USD',
            'counted_on' => '2026-06-02',
            'counted_at' => now(),
            'expected_amount' => '0.00',
        ]);
    }

    public function test_la_base_rechaza_un_cierre_sin_apertura(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no hay nada que cerrar');

        DB::table('period_closings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'USD',
            'period_type' => 'daily',
            'period_from' => '2026-06-02',
            'period_to' => '2026-06-02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_la_base_rechaza_un_total_declarado_que_no_coincide_con_el_asiento(): void
    {
        DB::table('cash_book_openings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'USD',
            'opened_on' => '2026-06-01',
            'declared_total' => '100.00',
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('declara 100.00 y sus asientos suman 0.00');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_rechaza_una_apertura_posterior_a_lo_que_ya_hay_en_el_libro(): void
    {
        DB::table('cash_book_openings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'USD',
            'opened_on' => '2026-06-10',
            'declared_total' => '0.00',
        ]);

        $this->asientoCrudo('2026-06-05', Currency::Usd);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ya tiene registros del 2026-06-05');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_una_apertura_no_se_modifica_ni_se_borra(): void
    {
        $this->abrirLibrosSinSaldo(Currency::Usd);

        try {
            // En un savepoint: el rechazo aborta solo esto y no el resto del test.
            DB::transaction(fn () => DB::table('cash_book_openings')->update(['declared_total' => '10.00']));
            $this->fail('Se modificó una apertura.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');

        DB::table('cash_book_openings')->delete();
    }

    // ───────────────────────── Las pantallas ─────────────────────────

    public function test_el_rechazo_vuelve_a_la_pantalla_con_la_salida(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->from(route('caja.dia', ['moneda' => 'usd']))
            ->post(route('caja.arqueos.store'), [
                'cashBoxId' => $this->caja(),
                'countedOn' => '2026-06-02',
                'currency' => 'USD',
                'denominations' => [],
            ])
            ->assertRedirect(route('caja.dia', ['moneda' => 'usd']))
            ->assertSessionHas('missingOpening.currency', 'USD');
    }

    public function test_la_salida_se_ofrece_solo_a_quien_puede_abrir(): void
    {
        $aviso = [
            'currency' => 'USD',
            'currencyLabel' => 'Dólares',
            'attemptedOn' => '2026-06-02',
            'openedOn' => null,
            'message' => 'Los libros en dólares de esta caja todavía no se abrieron.',
        ];

        $this->actingAs($this->operador('administrador'))
            ->withSession(['missingOpening' => $aviso])
            ->get(route('caja.dia'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.missingOpening.canOpen', true)
                ->where('flash.missingOpening.currency', 'USD'));

        $this->actingAs($this->operador('administrativo'))
            ->withSession(['missingOpening' => $aviso])
            ->get(route('caja.dia'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('flash.missingOpening.canOpen', false));
    }

    public function test_la_caja_del_dia_pide_la_apertura_de_cada_libro(): void
    {
        $this->abrirConEfectivo('1000.00', '2026-06-01');
        $usuario = $this->operador('administrador');

        $this->actingAs($usuario)
            ->get(route('caja.dia'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsOpening', false));

        $this->actingAs($usuario)
            ->get(route('caja.dia', ['moneda' => 'usd']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('needsOpening', true)
                ->where('can.open', true));

        $this->actingAs($usuario)
            ->get(route('caja.arqueos.index', ['moneda' => 'usd']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsOpening', true));

        $this->actingAs($usuario)
            ->get(route('caja.cierres.index', ['moneda' => 'usd']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsOpening', true));
    }

    public function test_la_apertura_en_dolares_ofrece_solo_cuentas_en_dolares(): void
    {
        BankAccount::query()->create([
            'label' => 'Cta. Cte. — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $usuario = $this->operador('administrador');

        $this->actingAs($usuario)
            ->get(route('caja.apertura.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('bankAccounts', 1));

        $this->actingAs($usuario)
            ->get(route('caja.apertura.index', ['moneda' => 'usd']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('bankAccounts', 0));
    }

    public function test_la_pantalla_abre_sin_saldo_y_lo_muestra(): void
    {
        $usuario = $this->operador('administrador');

        $this->actingAs($usuario)
            ->post(route('caja.apertura.store'), [
                'cashBoxId' => $this->caja(),
                'currency' => 'USD',
                'date' => '2026-06-01',
                'balances' => [],
                'denominations' => [],
                'declaredEmpty' => true,
                // Sin cuentas en dólares el selector queda vacío y la pantalla no la manda.
                'bankAccountId' => null,
            ])
            ->assertSessionHasNoErrors()
            // De vuelta al libro que se acaba de abrir.
            ->assertRedirect(route('caja.dia', ['moneda' => 'usd']));

        $apertura = CashBookOpening::for($this->caja(), Currency::Usd);

        $this->assertNotNull($apertura);
        $this->assertSame($usuario->id, $apertura->opened_by);

        $this->actingAs($usuario)
            ->get(route('caja.apertura.index', ['moneda' => 'usd']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('existing.isEmpty', true)
                ->has('existing.lines', 0));
    }

    // ─────────────────────────── Ayudas ───────────────────────────

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirConEfectivo(string $efectivo, string $fecha): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => $efectivo],
            denominations: $this->billetesPara($efectivo),
            date: CarbonImmutable::parse($fecha),
        );
    }

    private function cobrar(string $importe, string $fecha, Currency $moneda = Currency::Ars): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, $importe)->in($moneda)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)->in($moneda)->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: $this->caja(),
        );
    }

    /** Un ingreso real por mostrador, que cuenta como recaudación del día. */
    private function cobrarEnEfectivo(string $importe, string $fecha): void
    {
        app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(12),
            cashBoxId: $this->caja(),
            receivedDate: CarbonImmutable::parse($fecha),
        );
    }

    /** Un asiento escrito por SQL, salteándose el Action. */
    private function asientoCrudo(string $fecha, Currency $moneda): void
    {
        $evento = DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => $fecha,
            'status' => 'posted',
            'posted_at' => now(),
            'idempotency_key' => 'crudo-'.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'financial_event_id' => $evento,
                'account_code' => LedgerAccount::CashOnHand->value,
                'currency' => $moneda->value,
                'debit' => '25.00',
                'credit' => '0.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
            [
                'financial_event_id' => $evento,
                'account_code' => LedgerAccount::UnassignedFunds->value,
                'currency' => $moneda->value,
                'debit' => '0.00',
                'credit' => '25.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
        ]);
    }
}
