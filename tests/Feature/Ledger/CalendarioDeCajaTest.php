<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El calendario de caja.
 *
 * Lo que hay que probar no es que dibuje una grilla: es que **encuentre lo
 * que falta**. Una lista de cierres enumera lo que existe, y el día que
 * tuvo movimientos y quedó sin cerrar no está en ninguna lista de cierres
 * justamente porque no tiene uno. Esa celda es la razón de ser de esta
 * pantalla.
 */
class CalendarioDeCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 10:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_sin_mes_muestra_los_doce_del_ano(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/calendario')
                ->where('selected.year', 2026)
                ->where('selected.month', null)
                ->where('selected.day', null)
                ->has('months', 12)
                ->has('days', 0)
            );
    }

    /**
     * El día del que se viene abre el mes y queda marcado.
     *
     * Es lo que hace útil al botón de la caja del día: llegar a la grilla y
     * tener que buscar el día que uno acaba de dejar sería el trabajo que
     * el enlace vino a evitar.
     *
     * **Va un solo parámetro y no tres.** El año y el mes salen del día, así
     * que no hay forma de pedir un 15 de junio dentro de julio.
     */
    public function test_el_dia_que_llega_marcado_abre_su_mes(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?dia=2026-04-20')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.year', 2026)
                ->where('selected.month', 4)
                ->where('selected.day', '2026-04-20')
            );
    }

    /** Una fecha ilegible se ignora: es un subrayado, no un dato. */
    public function test_un_dia_ilegible_no_rompe_el_calendario(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?dia=cualquier-cosa')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.day', null)
                ->where('selected.month', null)
                ->where('selected.year', 2026)
            );
    }

    /** La grilla arranca en lunes y termina en domingo: semanas completas. */
    public function test_el_mes_trae_semanas_enteras(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $dias = $page->toArray()['props']['days'];

                $this->assertSame(0, count($dias) % 7, 'La grilla tiene semanas incompletas.');
                $this->assertFalse($dias[0]['inMonth'] && $dias[0]['day'] !== 1);
            });
    }

    /**
     * El caso que justifica la pantalla.
     *
     * Un día con movimientos y sin cierre queda en `pending`, y no hay lista
     * de cierres que pueda mostrarlo: no tiene cierre que listar.
     */
    public function test_el_dia_con_movimientos_sin_cerrar_queda_pendiente(): void
    {
        $this->abrirLibros();
        $this->cobrar('500000.00', '2026-06-10');

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $dia = $this->dia($page, '2026-06-10');

                $this->assertSame('pending', $dia['state']);
                $this->assertTrue($dia['hasMovements']);
                $this->assertFalse($dia['onlyReversals']);
                $this->assertNull($dia['closingCash']);
            });
    }

    public function test_un_dia_con_solo_reversion_sigue_pendiente_pero_se_distingue(): void
    {
        $this->abrirLibros();
        $this->cobrar('500000.00', '2026-06-09');

        $original = FinancialEvent::query()
            ->whereDate('event_date', '2026-06-09')
            ->firstOrFail();

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: 'reversion-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, '500000.00')->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, '500000.00')->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse('2026-06-10'),
            cashBoxId: $this->caja(),
            reversalOfId: (int) $original->id,
            reversalReason: 'Se anuló el cobro original.',
        );

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $dia = $this->dia($page, '2026-06-10');

                $this->assertSame('pending', $dia['state']);
                $this->assertTrue($dia['hasMovements']);
                $this->assertTrue($dia['onlyReversals']);
                $page->where('pendingDaysByMonth.2026-06', 3);
            });

        $this->cobrar('100000.00', '2026-06-10');

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertFalse(
                $this->dia($page, '2026-06-10')['onlyReversals'],
            ));
    }

    public function test_un_asiento_sin_comprobante_se_explica_en_el_calendario_y_la_caja_del_dia(): void
    {
        $this->abrirLibros();

        $deposito = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashDepositedToBank,
            idempotencyKey: 'deposito-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashInTransit, '240000.00')->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, '240000.00')->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse('2026-06-10'),
            cashBoxId: $this->caja(),
        );

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: 'reversion-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '240000.00')->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashInTransit, '240000.00')->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse('2026-06-11'),
            cashBoxId: $this->caja(),
            reversalOfId: (int) $deposito->id,
            reversalReason: 'Depósito registrado por error.',
        );

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?dia=2026-06-10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dayDetail.activity.0.label', 'Depósito en el banco')
                ->where('dayDetail.activity.0.amount', '240000.00')
                ->where('dayDetail.activity.0.reversedOn', '2026-06-11')
            );

        $this->get('/caja/dia?fecha=2026-06-10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('book.income', 0)
                ->has('book.expense', 0)
                ->where('activity.0.label', 'Depósito en el banco')
            );

        $this->get('/caja/calendario?dia=2026-06-11')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dayDetail.activity.0.label', 'Reversión de depósito en el banco')
                ->where('dayDetail.activity.0.amount', '240000.00')
                ->where('dayDetail.activity.0.note', 'Depósito registrado por error.')
            );
    }

    public function test_el_dia_cerrado_muestra_su_saldo(): void
    {
        $this->abrirLibros();
        $this->cobrar('500000.00', '2026-06-10');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $dia = $this->dia($page, '2026-06-10');

                $this->assertSame('closed', $dia['state']);
                $this->assertSame('1500000.00', $dia['closingCash']);
                $this->assertNotNull($dia['closingId']);
            });
    }

    /** Un día sin trabajo no es lo mismo que uno que quedó pendiente. */
    public function test_el_dia_sin_movimientos_esta_tranquilo(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $dia = $this->dia($page, '2026-06-10');

                $this->assertSame('quiet', $dia['state']);
                $this->assertFalse($dia['hasMovements']);
                $this->assertFalse($dia['onlyReversals']);
            });
    }

    public function test_los_dias_por_venir_no_piden_nada(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                // Hoy es el 15 en este test.
                $this->assertSame('future', $this->dia($page, '2026-06-20')['state']);
                $this->assertSame('quiet', $this->dia($page, '2026-06-14')['state']);
            });
    }

    /**
     * El día elegido trae sus dos tarjetas: el arqueo y el cierre.
     *
     * Son las mismas de la caja del día, para verlas sin salir del
     * calendario. Solo llegan cuando hay un día marcado dentro del mes que
     * se muestra: sin eso no hay tarjeta que llenar.
     */
    public function test_el_dia_elegido_trae_su_arqueo_y_su_cierre(): void
    {
        $this->abrirLibros();
        $this->cobrar('500000.00', '2026-06-10');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?dia=2026-06-10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dayDetail.date', '2026-06-10')
                ->where('dayDetail.closing.status', 'closed')
                // Todos los turnos, no solo el último: el panel los lista.
                ->has('dayDetail.arqueos', 1)
                ->where('dayDetail.arqueos.0.countedOn', '2026-06-10')
                ->where('selected.day', '2026-06-10')
                ->where('selected.month', 6)
            );
    }

    /**
     * El calendario ofrece cerrar el mes desde donde se lo está mirando.
     *
     * Cerrar el mensual vivía solo en la pantalla de Cierres, detrás de
     * un botón que dice «Cerrar período» y de un selector que arranca en
     * «Diario»: había que saber de antemano que ahí adentro estaba. En el
     * calendario el mes es lo que se tiene delante, y el diálogo necesita
     * los días pendientes para poder avisar.
     */
    public function test_la_vista_del_mes_trae_lo_que_hace_falta_para_cerrarlo(): void
    {
        $this->abrirLibros();
        $this->cobrar('50000.00', '2026-06-10');

        $this->actingAs($this->operador('contador'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.close', true)
                ->where('monthlyClosing', null)
                // Los días con movimiento sin cerrar: junio tiene el 01 y el 10.
                ->where('pendingDaysByMonth.2026-06', 2)
            );
    }

    /**
     * El panel del día opera, no solo mira.
     *
     * Dibuja el mismo flujo que la caja del día --contar, revisar,
     * imputar, cerrar, rehacer la planilla, reabrir-- y para eso necesita
     * los permisos completos, las denominaciones del arqueo y la versión
     * del dibujo. Sin ellos el panel se quedaría en una foto, que es lo
     * que era antes: elegir un día servía para verlo y nada más.
     */
    public function test_el_panel_recibe_lo_que_necesita_para_operar(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrador'))
            ->get('/caja/calendario?dia=2026-06-10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.count', true)
                ->where('can.review', true)
                ->where('can.adjust', true)
                ->where('can.close', true)
                ->where('can.reopen', true)
                ->where('can.regenerate', true)
                ->has('suggestedDenominations')
                ->has('sheetVersion')
            );
    }

    /** Sin día elegido, no se calcula el detalle. */
    public function test_sin_dia_elegido_no_hay_detalle(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026&mes=6')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('dayDetail', null)
            );
    }

    public function test_el_ano_cuenta_cuantos_dias_quedaron_cerrados(): void
    {
        $this->abrirLibros();
        $this->cobrar('500000.00', '2026-06-10');
        $this->cobrar('300000.00', '2026-06-11');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2026')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $junio = $page->toArray()['props']['months'][5];

                $this->assertSame('Junio', $junio['label']);
                $this->assertSame(1, $junio['daysClosed']);
                // El 10, el 11 y el día de la apertura.
                $this->assertSame(3, $junio['daysWithMovements']);
                $this->assertFalse($junio['closed']);
            });
    }

    /** Un año que todavía no llegó se ignora: no hay nada que mostrar. */
    public function test_el_ano_futuro_vuelve_al_actual(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/calendario?anio=2030')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.year', 2026));
    }

    public function test_el_calendario_pide_el_permiso_de_ver_cierres(): void
    {
        $sinPermiso = $this->operador('administrativo');
        $sinPermiso->syncRoles([]);

        $this->actingAs($sinPermiso)->get('/caja/calendario')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function dia(AssertableInertia $page, string $fecha): array
    {
        /** @var list<array<string, mixed>> $dias */
        $dias = $page->toArray()['props']['days'];

        foreach ($dias as $dia) {
            if ($dia['date'] === $fecha) {
                return $dia;
            }
        }

        $this->fail("El calendario no trajo el día {$fecha}.");
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '1000000.00'],
            denominations: $this->billetesPara('1000000.00'),
            date: CarbonImmutable::parse('2026-06-01'),
        );
    }

    private function cobrar(string $importe, string $fecha): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, $importe)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: $this->caja(),
        );
    }
}
