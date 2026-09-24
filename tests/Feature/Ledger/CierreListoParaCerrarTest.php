<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\User;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
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
 * El invariante 30, impuesto por la base.
 *
 * *«No puede cerrarse un período con eventos en `draft`, importaciones en
 * curso o arqueos sin resolver»* — §9.9 regla 5.
 *
 * Lo que se prueba acá **no es el Action**: es que el trigger lo impide
 * aunque nadie pase por él. Por eso los cierres se escriben con
 * `DB::table()`, que es la única forma de comprobar que la última línea de
 * defensa existe de verdad — un job en cola o una corrección a mano no
 * llaman a `ClosePeriod`.
 */
class CierreListoParaCerrarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_la_base_rechaza_cerrar_con_un_asiento_en_borrador(): void
    {
        $this->borrador('2026-06-10');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/borrador/i');

        $this->cerrarCrudo('2026-06-10', conArqueo: false);
    }

    /**
     * Un período que no terminó no se cierra.
     *
     * **El caso que lo justifica pasó de verdad.** El controlador exigía
     * que la fecha no fuera futura, y para el diario alcanza; para el
     * mensual no. Cerrar el mes con la fecha de hoy produce un período que
     * llega hasta fin de mes, y desde ahí `financial_events_period_open`
     * rechaza cada operación de los días que faltan: la caja se queda sin
     * poder emitir un recibo por el resto del mes.
     */
    public function test_no_se_cierra_el_mes_en_curso(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 12:00'));

        $this->abrirLibros();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/todavía no llegó/');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-09-03'),
            type: PeriodType::Monthly,
        );
    }

    /** El diario del propio día sí: el área cierra la caja al terminarlo. */
    public function test_el_cierre_diario_de_hoy_sigue_permitido(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 18:00'));

        $this->abrirLibros();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );

        $this->assertSame(1, DB::table('period_closings')->count());
    }

    /**
     * Y la base lo impide aunque nadie pase por el Action.
     *
     * Con un año de distancia para no depender del reloj: el trigger
     * compara contra el del motor, que no es el mismo que el de la
     * aplicación —UTC contra `America/Buenos_Aires`— y por eso lleva un día
     * de tolerancia.
     */
    public function test_la_base_rechaza_cerrar_un_periodo_que_no_termino(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/todavia no llego/');

        DB::table('period_closings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'ARS',
            'period_type' => 'monthly',
            'period_from' => '2099-01-01',
            'period_to' => '2099-01-31',
            'status' => 'closed',
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_la_base_rechaza_cerrar_con_un_arqueo_sin_resolver(): void
    {
        DB::table('cash_counts')->insert([
            'cash_box_id' => $this->caja(),
            'counted_on' => '2026-06-10',
            'sequence' => 1,
            'counted_at' => now(),
            'currency' => 'ARS',
            'expected_amount' => '0.00',
            'counted_amount' => '0.00',
            'uncounted_amount' => '0.00',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/arqueo/i');

        $this->cerrarCrudo('2026-06-10', conArqueo: false);
    }

    public function test_la_base_rechaza_un_cierre_diario_sin_arqueo_aunque_se_salte_el_action(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/contar el cajon/i');

        $this->cerrarCrudo('2026-06-10', conArqueo: false);
    }

    public function test_la_base_rechaza_un_arqueo_que_quedo_viejo_por_un_movimiento_posterior(): void
    {
        $this->abrirLibros();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');
        $this->cobrar('50000.00', '2026-06-10');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/saldo del libro cambio/i');

        $this->cerrarCrudo('2026-06-10', conArqueo: false);
    }

    /**
     * La condición que en PHP no se podía escribir.
     *
     * `bank_statement_imports` es de Banking y Ledger no puede depender de
     * Banking. En la base no hay módulos, así que el trigger la alcanza.
     *
     * **El estado se arma a mano a propósito**: hoy el circuito real no lo
     * produce —la importación pasa de `parsing` a `completed` dentro de la
     * misma transacción—, y lo que se prueba es que la guarda existe para
     * el día que el parseo se mueva a una cola.
     */
    public function test_la_base_rechaza_cerrar_mientras_se_importa_un_extracto(): void
    {
        $this->importacionEnCurso('2026-06-01', '2026-06-30');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/importa/i');

        $this->cerrarCrudo('2026-06-10', conArqueo: false);
    }

    /** Una importación de otro mes no traba este cierre. */
    public function test_una_importacion_de_otro_periodo_no_traba_el_cierre(): void
    {
        $this->importacionEnCurso('2026-07-01', '2026-07-31');

        $this->cerrarCrudo('2026-06-10');

        $this->assertSame(1, DB::table('period_closings')->count());
    }

    /** Y una ya terminada tampoco: no queda nada por llegar. */
    public function test_una_importacion_terminada_no_traba_el_cierre(): void
    {
        $this->importacionEnCurso('2026-06-01', '2026-06-30', 'completed');

        $this->cerrarCrudo('2026-06-10');

        $this->assertSame(1, DB::table('period_closings')->count());
    }

    /** El Action da el mensaje legible; la base es la que no se puede saltear. */
    public function test_el_action_avisa_antes_de_llegar_a_la_base(): void
    {
        $this->abrirLibros();
        $this->borrador('2026-06-10');

        $this->expectException(ValidationException::class);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-10'),
        );
    }

    /**
     * Los días sin cerrar traban el mes.
     *
     * **No sale del DER**: el invariante 30 no los nombra y los totales
     * del mes no dependen de los cierres diarios —se calculan sobre el
     * libro—, así que el mes cerraba igual. El área definió lo contrario:
     * sin su cierre diario, esos días nunca pasaron por el arqueo, que es
     * donde se detecta un faltante.
     *
     * La pantalla ya los contaba para avisar; ahora ese mismo número es lo
     * que hay que llevar a cero antes de cerrar.
     */
    public function test_los_dias_sin_cerrar_traban_el_mes(): void
    {
        $this->abrirLibros();
        $this->cobrar('50000.00', '2026-06-10');

        $this->actingAs($this->operador('contador'))
            ->get('/caja/cierres')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('pendingDaysByMonth.2026-06', 2)
            );

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-15'),
                type: PeriodType::Monthly,
            );

            $this->fail('El mes no debería cerrar con días operados sin cerrar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'sin cerrar',
                $e->validator->errors()->first('period'),
            );
        }

        $this->assertSame(0, DB::table('period_closings')->count());

        // Cerrados los días con movimiento, el mes cierra.
        $contador = User::factory()->create();

        foreach (['2026-06-01', '2026-06-10'] as $dia) {
            $this->arqueoListoParaCerrar($this->caja(), $dia, $contador);

            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse($dia),
                actorId: $contador->id,
            );
        }

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            type: PeriodType::Monthly,
        );

        $this->assertSame(3, DB::table('period_closings')->count());
    }

    /**
     * Un asiento dentro de un período cerrado se explica en castellano.
     *
     * El trigger `financial_events_period_open` lo impide igual, pero su
     * `QueryException` llegaba entera a la pantalla: la traza de PostgreSQL
     * en la cara de quien solo quería emitir un recibo. Lo que se prueba es
     * que el Action lo alcanza antes y dice qué cierre lo frenó.
     */
    public function test_un_asiento_en_un_periodo_cerrado_se_explica_en_castellano(): void
    {
        $this->abrirLibros();
        $this->cerrarCrudo('2026-06-10');

        try {
            $this->cobrar('50000.00', '2026-06-10');
            $this->fail('Se asentó dentro de un período cerrado.');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('10/06/2026', $e->getMessage());
            $this->assertSame('2026-06-10', $e->toArray()['attemptedOn']);
            $this->assertSame('Diario', $e->toArray()['periodType']);
        }
    }

    /** Fuera del período no frena nada. */
    public function test_el_dia_siguiente_al_cierre_sigue_admitiendo_movimientos(): void
    {
        $this->abrirLibros();
        $this->cerrarCrudo('2026-06-10');

        $this->cobrar('50000.00', '2026-06-11');

        $this->assertSame(1, DB::table('financial_events')
            ->whereDate('event_date', '2026-06-11')
            ->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

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

    /** Un cierre escrito sin pasar por el Action. */
    private function cerrarCrudo(string $fecha, bool $conArqueo = true): void
    {
        if ($conArqueo) {
            $this->arqueoListoParaCerrar($this->caja(), $fecha);
        }

        DB::table('period_closings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'ARS',
            'period_type' => 'daily',
            'period_from' => $fecha,
            'period_to' => $fecha,
            'status' => 'closed',
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function borrador(string $fecha): void
    {
        DB::table('financial_events')->insert([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => $fecha,
            'status' => 'draft',
            'idempotency_key' => 'borrador-'.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function importacionEnCurso(string $desde, string $hasta, string $estado = 'parsing'): void
    {
        $cuenta = DB::table('bank_accounts')->insertGetId([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_statement_imports')->insert([
            'bank_account_id' => $cuenta,
            'imported_by' => $this->operador('administrativo')->id,
            'original_filename' => 'extracto.xlsx',
            'file_size' => 1024,
            'file_sha256' => hash('sha256', Str::random(20)),
            'source_format' => 'macro_excel',
            'parser_version' => '1',
            'period_from' => $desde,
            'period_to' => $hasta,
            'status' => $estado,
            // La base exige la equivalencia: importado ⇔ tiene fecha de importación.
            'imported_at' => $estado === 'completed' ? now() : null,
            'rows_total' => 0,
            'rows_valid' => 0,
            'rows_rejected' => 0,
            'rows_new' => 0,
            'rows_duplicate' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function abrirLibros(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '100000.00'],
            denominations: $this->billetesPara('100000.00'),
            date: CarbonImmutable::parse('2026-06-01'),
            // Abrir es contar el cajón: sin quién lo contó, ese arqueo
            // queda en borrador y traba el cierre del primer período.
            actorId: $this->operador('administrador')->id,
        );
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }
}
