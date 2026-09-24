<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\User;
use App\Modules\Ledger\Actions\AdjustCashDifference;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Caja: apertura, arqueo y cierre.
 *
 * Los números **no son inventados**: salen de `CAJA HABERES EN CONSIGNACION
 * JUNIO 2026.xlsx`, la planilla que el área lleva a mano. Si el sistema
 * reproduce esas hojas, reproduce el trabajo real; si las reproduce con
 * números redondos elegidos por conveniencia, no prueba nada.
 *
 * - Anverso del 01/06: saldo inicial 9.852.300 en efectivo, 673.804,70 en cheques.
 * - Anverso del 02/06: ingresos 24.985.600, egresos 25.077.450, saldo final 6.760.450.
 * - Reverso del 30/06: 101×20.000 + 1×10.000 + 4×1.000 + 500 + 200 + 100 = 2.034.800.
 */
class CajaArqueoYCierreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    // ─────────────────────────── Apertura ───────────────────────────

    public function test_la_apertura_deja_el_saldo_que_habia_en_el_cajon(): void
    {
        $this->abrirLibros();

        $saldos = app(CashBalance::class);

        $this->assertSame('9852300.00', $saldos->of(LedgerAccount::CashOnHand, $this->caja()));
        $this->assertSame('673804.70', $saldos->of(LedgerAccount::ChequesInCustody, $this->caja()));

        // El contraasiento entero, que es lo que se va drenando a medida
        // que los casos viejos se pagan.
        $this->assertSame('10526104.70', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    public function test_una_caja_no_se_abre_dos_veces(): void
    {
        $this->abrirLibros();

        $this->expectException(ValidationException::class);

        $this->abrirLibros();
    }

    public function test_la_apertura_declara_donde_esta_la_plata_y_no_de_quien_es(): void
    {
        $this->expectException(ValidationException::class);

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::BeneficiaryFunds->value => '1000.00'],
            date: CarbonImmutable::parse('2026-05-31'),
        );
    }

    // ──────────────────────────── Arqueo ────────────────────────────

    /**
     * El reverso del 30/06/2026, cargado tal cual está en el papel.
     *
     * Es el test que justifica `uncounted_amount`. El área contó 2.034.800
     * de la recaudación del día y dejó 743.050 sin recontar; el segundo
     * número sale del libro, no de lo que escriba el operador. Los dos
     * juntos dan el saldo y **la diferencia es cero sin que el conteo haya
     * sido completo**.
     */
    public function test_el_reverso_del_30_de_junio_cuadra_declarando_el_fajo_no_recontado(): void
    {
        $this->abrirLibros(efectivo: '743050.00', cheques: '0.00');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
        );

        $this->assertSame('2034800.00', $arqueo->counted_amount);
        $this->assertSame('743050.00', $arqueo->uncounted_amount);
        $this->assertSame('2777850.00', $arqueo->expected_amount);
        $this->assertSame('0.00', $arqueo->difference_amount);

        $this->assertTrue($arqueo->isBalanced());
        $this->assertFalse(
            $arqueo->wasFullyCounted(),
            'Cuadrar no es haber contado todo, y el sistema tiene que poder distinguirlo.'
        );

        $this->assertSame(6, $arqueo->lines()->count());
    }

    public function test_el_total_contado_sale_de_las_denominaciones_y_no_de_quien_carga(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
        );

        // 101×20.000 + 1×10.000 + 4×1.000 + 500 + 200 + 100
        $this->assertSame('2034800.00', $arqueo->counted_amount);
        $this->assertSame(
            $arqueo->counted_amount,
            $arqueo->lines()->sum('subtotal'),
            'El total tiene que ser la suma de las líneas, no un número aparte.'
        );
    }

    public function test_una_diferencia_sin_explicacion_no_se_registra(): void
    {
        $this->abrirLibros(efectivo: '2777850.00', cheques: '0.00');

        $this->expectException(ValidationException::class);

        app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101],
        );
    }

    /**
     * Quien contó puede revisar, y queda dicho que no hubo segunda firma.
     *
     * Lo ideal sigue siendo que revise otro. Pero el área trabaja con un
     * equipo chico y exigir dos personas dejaba trabado el día entero
     * cuando hay una sola en el mostrador: el cierre no admite arqueos en
     * borrador. Se prefiere un registro que diga la verdad antes que un
     * control que en la práctica se saltea prestando la sesión de otro.
     */
    public function test_quien_conto_puede_revisar_y_queda_sin_segunda_firma(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $cajero = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
        );

        $revisado = app(ReviewCashCount::class)->handle($arqueo, $cajero->id);

        $this->assertSame(CashCountStatus::Reviewed, $revisado->status);
        $this->assertTrue($revisado->wasSelfReviewed());
    }

    /** Con dos personas, el arqueo no queda marcado. */
    public function test_revisado_por_otro_no_queda_marcado(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
        );

        $this->assertFalse(
            app(ReviewCashCount::class)->handle($arqueo, $contador->id)->wasSelfReviewed(),
        );
    }

    public function test_el_arqueo_de_dolares_es_su_propia_fila(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
        );

        // Sin apertura en dólares el saldo teórico es cero, así que un
        // conteo vacío cuadra. Lo que se prueba es que convive.
        $enDolares = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [],
            currency: Currency::Usd,
        );

        $this->assertSame(Currency::Usd, $enDolares->currency);
        $this->assertSame(2, CashCount::query()->whereDate('counted_on', '2026-06-30')->count());
    }

    public function test_la_diferencia_se_imputa_a_la_cuenta_de_diferencias(): void
    {
        $this->abrirLibros(efectivo: '2034900.00', cheques: '0.00');
        $this->recibirEfectivo('2034900.00', '2026-06-30');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
            explanation: 'Faltan $100 respecto del libro.',
        );

        $this->assertSame('-100.00', $arqueo->difference_amount);

        $arqueo = app(ReviewCashCount::class)->handle($arqueo, $contador->id);
        $arqueo = app(AdjustCashDifference::class)->handle($arqueo, $contador->id);

        $this->assertSame(CashCountStatus::Adjusted, $arqueo->status);
        $this->assertNotNull($arqueo->adjustment_event_id);
        $this->assertSame(
            'Imputación de diferencia de arqueo',
            FinancialEvent::query()->findOrFail($arqueo->adjustment_event_id)->description,
        );

        $saldos = app(CashBalance::class);

        // Después del ajuste el libro dice lo que hay en el cajón, y la
        // explicación se mudó a `CASH_DIFFERENCE`.
        $this->assertSame('4069700.00', $saldos->of(LedgerAccount::CashOnHand, $this->caja()));

        /*
         * El signo se lee igual en los dos lados: negativo es faltante.
         * Que el saldo de `CASH_DIFFERENCE` coincida exactamente con la
         * diferencia del arqueo es lo que hace auditable el ajuste — si
         * apuntara para el otro lado, el libro estaría explicando lo
         * contrario de lo que el conteo encontró.
         */
        $this->assertSame('-100.00', $saldos->of(LedgerAccount::CashDifference, $this->caja()));
        $this->assertSame(
            $arqueo->difference_amount,
            $saldos->of(LedgerAccount::CashDifference, $this->caja()),
        );
    }

    // ──────────────────────────── Cierre ────────────────────────────

    /**
     * El anverso del 02/06/2026, reproducido desde el libro.
     *
     * ```text
     * SALDO INICIAL  6.852.300
     * INGRESOS      24.985.600
     * EGRESOS       25.077.450
     * SALDO FINAL    6.760.450
     * ```
     */
    /**
     * Imputar la diferencia no traba el cierre del día.
     *
     * **La imputación mueve el libro a propósito**: corre el saldo hasta
     * igualar lo contado. Comparar después contra el `expected_amount`
     * —congelado antes de imputar— hacía que el sistema leyera su propia
     * reconciliación como «el libro cambió» y pidiera recontar un cajón ya
     * conciliado. El día quedaba sin poder cerrarse.
     *
     * No lo detectaba nadie porque la prueba de la imputación verificaba el
     * asiento y no seguía hasta el cierre, que es donde el circuito termina.
     */
    public function test_el_dia_cierra_despues_de_imputar_la_diferencia(): void
    {
        $this->abrirLibros(efectivo: '2034900.00', cheques: '0.00', fecha: '2026-06-01');
        $this->recibirEfectivo('2034900.00', '2026-06-02');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
            explanation: 'Faltan $100 respecto del libro.',
        );

        $arqueo = app(ReviewCashCount::class)->handle($arqueo, $contador->id);
        $arqueo = app(AdjustCashDifference::class)->handle(
            $arqueo,
            $contador->id,
            'Autorizado por nota interna 12/2026.',
        );

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);

        // Y el saldo congelado es el que dejó la imputación.
        $this->assertSame('4069700.00', $cierre->closing_cash);
    }

    public function test_el_cierre_reproduce_el_anverso_de_la_planilla(): void
    {
        $this->abrirLibros(efectivo: '6852300.00', cheques: '673804.70', fecha: '2026-06-01');

        $this->cobrar('24985600.00', '2026-06-02');
        $this->pagar('25077450.00', '2026-06-02');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        $this->assertSame('6852300.00', $cierre->opening_cash);
        $this->assertSame('24985600.00', $cierre->received_cash);
        $this->assertSame('25077450.00', $cierre->disbursed_cash);
        $this->assertSame('6760450.00', $cierre->closing_cash);

        // Las otras dos columnas se arrastran sin moverse, como en junio.
        $this->assertSame('673804.70', $cierre->closing_cheques);

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);
    }

    public function test_el_cierre_no_puede_separarse_del_libro(): void
    {
        $this->abrirLibros(efectivo: '6852300.00', cheques: '673804.70', fecha: '2026-06-01');
        $this->cobrar('24985600.00', '2026-06-02');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        $this->assertSame(
            app(CashBalance::class)->of(
                LedgerAccount::CashOnHand,
                $this->caja(),
                Currency::Ars,
                CarbonImmutable::parse('2026-06-02'),
            ),
            $cierre->closing_cash,
        );
    }

    public function test_el_cierre_diario_exige_un_arqueo_revisado(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-02'),
            );
            $this->fail('El día se cerró sin arqueo.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Antes de cerrar el día hay que contar el cajón y revisar el arqueo.',
                $e->errors()['period'][0],
            );
        }
    }

    public function test_el_cierre_diario_rechaza_un_arqueo_si_el_libro_cambio_despues(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $this->cobrar('500.00', '2026-06-02');

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-02'),
            );
            $this->fail('El día se cerró con un arqueo anterior al último movimiento.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'El saldo del libro cambió desde el último arqueo',
                $e->errors()['period'][0],
            );
        }
    }

    public function test_no_se_cierra_un_periodo_con_un_arqueo_en_borrador(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00', fecha: '2026-06-01');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
        );

        $this->expectException(ValidationException::class);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
        );
    }

    public function test_cerrar_el_dia_congela_su_arqueo(): void
    {
        $this->abrirLibros(efectivo: '2034800.00', cheques: '0.00', fecha: '2026-06-01');
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
        );

        app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
            actorId: $contador->id,
        );

        $this->assertSame(CashCountStatus::Closed, $arqueo->refresh()->status);
    }

    /**
     * Un movimiento con fecha dentro de un período cerrado se rechaza, y
     * llega **traducido**: `ClosedPeriodException` con el cierre adentro, no
     * la `QueryException` del trigger. El trigger sigue siendo la última
     * línea de defensa —lo prueba `CierreListoParaCerrarTest` escribiendo
     * sin pasar por el Action—; acá se pasa por el Action, que es el camino
     * real, y por eso el mensaje es el legible.
     */
    public function test_un_periodo_cerrado_rechaza_un_movimiento_retroactivo(): void
    {
        $this->abrirLibros(efectivo: '6852300.00', cheques: '673804.70', fecha: '2026-06-01');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        try {
            $this->cobrar('1000.00', '2026-06-02');
            $this->fail('Se asentó un movimiento en un período cerrado.');
        } catch (ClosedPeriodException $e) {
            $this->assertSame('2026-06-02', $e->closing->period_from->toDateString());
            $this->assertSame('2026-06-02', $e->attemptedOn->toDateString());
        }
    }

    /** El cierre es por moneda: cerrar pesos no congela los dólares. */
    public function test_un_cierre_en_pesos_no_bloquea_un_asiento_en_dolares(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            currency: Currency::Ars,
        );

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-usd-despues-del-cierre-ars',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '50.00')
                    ->in(Currency::Usd)
                    ->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '50.00')
                    ->in(Currency::Usd)
                    ->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse('2026-06-02'),
            cashBoxId: $this->caja(),
        );

        $this->assertSame('50.00', app(CashBalance::class)->of(
            LedgerAccount::CashOnHand,
            $this->caja(),
            Currency::Usd,
            CarbonImmutable::parse('2026-06-02'),
        ));
    }

    /**
     * Un borrador en dólares no frena el cierre en pesos, y uno en pesos sí.
     *
     * Las dos mitades van juntas a propósito: acotar la guarda por moneda
     * solo vale si lo que perseguía sigue cayendo.
     */
    public function test_el_borrador_que_frena_el_cierre_es_el_de_su_moneda(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $this->borradorCrudo('2026-06-02', Currency::Usd);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            currency: Currency::Ars,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);

        // Y en la moneda del borrador, el mismo día no cierra.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('asiento en borrador');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            currency: Currency::Usd,
        );
    }

    /** SQL directo tampoco puede agregar líneas tardías a un período cerrado. */
    public function test_la_base_rechaza_lineas_agregadas_a_un_evento_posteado_y_cerrado(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            currency: Currency::Ars,
        );

        $evento = FinancialEvent::query()
            ->where('cash_box_id', $this->caja())
            ->whereDate('event_date', '2026-06-01')
            ->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cambiaria el saldo inicial');

        DB::table('journal_lines')->insert([
            [
                'financial_event_id' => $evento->id,
                'account_code' => LedgerAccount::CashOnHand->value,
                'currency' => Currency::Ars->value,
                'debit' => '10.00',
                'credit' => '0.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
            [
                'financial_event_id' => $evento->id,
                'account_code' => LedgerAccount::UnassignedFunds->value,
                'currency' => Currency::Ars->value,
                'debit' => '0.00',
                'credit' => '10.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
        ]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_reabrir_exige_motivo_y_deja_rastro(): void
    {
        $this->abrirLibros(efectivo: '6852300.00', cheques: '673804.70', fecha: '2026-06-01');

        $contador = User::factory()->create();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02', $contador);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $this->assertDatabaseHas('audit_events', [
            'action' => 'periodo.cerrado',
            'user_id' => $contador->id,
        ]);

        try {
            app(ReopenPeriod::class)->handle($cierre, $contador->id, '   ');
            $this->fail('Reabrir sin motivo tendría que fallar.');
        } catch (ValidationException) {
            // Es lo esperado.
        }

        $cierre = app(ReopenPeriod::class)->handle(
            $cierre,
            $contador->id,
            'Faltó cargar el recibo 76399.',
        );

        $this->assertSame(PeriodClosingStatus::Reopened, $cierre->status);
        $this->assertSame('Faltó cargar el recibo 76399.', $cierre->reopen_reason);
        $this->assertNotNull($cierre->reopened_at);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'periodo.reabierto',
            'user_id' => $contador->id,
        ]);

        // Y con el período reabierto, el movimiento que faltaba entra.
        $this->cobrar('210050.00', '2026-06-02');
    }

    public function test_el_cierre_mensual_cubre_el_mes_entero(): void
    {
        $this->abrirLibros(efectivo: '6852300.00', cheques: '673804.70', fecha: '2026-05-31');

        $this->cobrar('24985600.00', '2026-06-02');
        $this->pagar('25077450.00', '2026-06-30');

        // El mes exige cerrados sus días con movimiento, así que van primero.
        $contador = User::factory()->create();

        foreach (['2026-05-31', '2026-06-02', '2026-06-30'] as $dia) {
            $this->arqueoListoParaCerrar($this->caja(), $dia, $contador);

            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse($dia),
                actorId: $contador->id,
            );
        }

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            type: PeriodType::Monthly,
        );

        $this->assertSame('2026-06-01', $cierre->period_from->toDateString());
        $this->assertSame('2026-06-30', $cierre->period_to->toDateString());
        $this->assertSame('24985600.00', $cierre->received_cash);
        $this->assertSame('25077450.00', $cierre->disbursed_cash);
        $this->assertSame('6760450.00', $cierre->closing_cash);
    }

    /**
     * Un conteo mal cargado se rehace contando de nuevo.
     *
     * **Es la salida cuando el cajero contó de menos**: el efectivo del
     * día en vez del cajón entero. La otra —imputar— asienta la
     * diferencia contra `CASH_DIFFERENCE` y mueve el libro hasta el
     * número equivocado, así que no puede ser la única que la pantalla
     * ofrezca.
     *
     * Un borrador se reemplaza y uno firme abre el turno siguiente: el
     * arqueo revisado es un hecho y no se pisa, pero tampoco traba el día.
     */
    public function test_un_arqueo_revisado_no_se_pisa_y_el_borrador_si(): void
    {
        $this->abrirLibros(efectivo: '2909750.00', cheques: '0.00', fecha: '2026-09-07');
        $this->recibirEfectivo('50000.00', '2026-09-08');

        $cajero = User::factory()->create();

        // Cuenta 30.000 de los 50.000 que entraron en el día.
        $incompleto = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-09-08'),
            denominations: [20_000 => 1, 10_000 => 1],
            actorId: $cajero->id,
            explanation: 'Conteo parcial mientras se ordena el cajón.',
        );

        $this->assertSame(1, $incompleto->sequence);
        $this->assertSame('-20000.00', $incompleto->difference_amount);

        // Todavía es borrador: contar otra vez lo reemplaza.
        $rehecho = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-09-08'),
            denominations: [20_000 => 2],
            actorId: $cajero->id,
            explanation: 'Se recontó el cajón completo.',
        );

        $this->assertSame(1, $rehecho->sequence);
        $this->assertSame($incompleto->id, $rehecho->id);
        $this->assertSame(1, $this->arqueosDel('2026-09-08'));

        // Una vez revisado ya es un hecho: el siguiente conteo abre turno.
        app(ReviewCashCount::class)->handle($rehecho, User::factory()->create()->id);

        $segundoTurno = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-09-08'),
            denominations: [20_000 => 2, 10_000 => 1],
            actorId: $cajero->id,
        );

        $this->assertSame(2, $segundoTurno->sequence);
        $this->assertNotSame($rehecho->id, $segundoTurno->id);
        $this->assertSame(2, $this->arqueosDel('2026-09-08'));

        // Y el anterior sigue ahí, revisado, como historia del día.
        $this->assertSame(
            CashCountStatus::Reviewed,
            CashCount::query()->findOrFail($rehecho->id)->status,
        );
    }

    /**
     * Contar una parte y declarar el resto es lo que cierra sin diferencia.
     *
     * Nadie recuenta tres millones en efectivo todas las tardes: se cuenta
     * el movimiento del día y el fondo histórico se declara como no
     * recontado, con su motivo. Contado más no recontado tiene que dar el
     * saldo del libro.
     */
    public function test_el_fondo_que_no_se_recuenta_se_declara(): void
    {
        $this->abrirLibros(efectivo: '2909750.00', cheques: '0.00', fecha: '2026-09-07');
        $this->recibirEfectivo('50000.00', '2026-09-08');

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-09-08'),
            denominations: [20_000 => 2, 10_000 => 1],
            actorId: User::factory()->create()->id,
        );

        $this->assertSame('50000.00', $arqueo->counted_amount);
        $this->assertSame('2909750.00', $arqueo->uncounted_amount);
        $this->assertSame('2959750.00', $arqueo->expected_amount);
        $this->assertSame('0.00', $arqueo->difference_amount);
    }

    /**
     * Una diferencia viva no se cierra encima.
     *
     * **El cierre congela el saldo del libro, no el contado.** Cerrar con
     * la diferencia sin resolver dejaba el día archivado diciendo que
     * había una plata que el conteo no encontró, y limpiarlo después
     * obliga a reabrir el período. El área definió que el día se cierra
     * con el arqueo resuelto: o cuadra, o la diferencia se imputó.
     */
    public function test_el_dia_no_cierra_con_una_diferencia_sin_imputar(): void
    {
        $this->abrirLibros(efectivo: '2000000.00', cheques: '0.00', fecha: '2026-06-01');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [20_000 => 99, 10_000 => 1, 1_000 => 9, 500 => 1, 200 => 2],
            actorId: $cajero->id,
            explanation: 'Falta plata en el cajón.',
        );

        $arqueo = app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        $this->assertFalse($arqueo->isBalanced());

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-02'),
                actorId: $contador->id,
            );

            $this->fail('El cierre no debería aceptar una diferencia sin imputar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'sin imputar',
                $e->validator->errors()->first('period'),
            );
        }

        // Imputada, el mismo día cierra sin tocar nada más.
        app(AdjustCashDifference::class)->handle(
            $arqueo,
            $contador->id,
            'Nota interna 12/2026.',
        );

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);
    }

    /**
     * Y la base lo impide sola, sin pasar por el Action.
     *
     * El mensaje legible lo da `ClosePeriod`; que el hecho no pueda
     * escribirse lo garantiza el trigger, que es donde vive el invariante.
     */
    public function test_la_base_rechaza_el_cierre_con_diferencia_viva(): void
    {
        $this->abrirLibros(efectivo: '2000000.00', cheques: '0.00', fecha: '2026-06-01');

        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [20_000 => 99, 10_000 => 1, 1_000 => 9, 500 => 1, 200 => 2],
            actorId: $contador->id,
            explanation: 'Falta plata en el cajón.',
        );

        app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sin imputar');

        DB::table('period_closings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => Currency::Ars->value,
            'period_type' => PeriodType::Daily->value,
            'period_from' => '2026-06-02',
            'period_to' => '2026-06-02',
            'status' => PeriodClosingStatus::Closed->value,
            'opening_cash' => '0.00',
            'opening_cheques' => '0.00',
            'opening_bank_deposits' => '0.00',
            'closed_by' => $contador->id,
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un cajón vacío se arquea contando nada.
     *
     * La guarda que exige al menos una denominación existe para que nadie
     * dé por revisado un arqueo donde no se cargó una sola fila. Aplicada
     * a rajatabla dejaba sin salida el día en que todo se depositó: sin
     * arqueo posible no hay cierre posible.
     */
    public function test_un_cajon_vacio_se_puede_arquear_y_cerrar(): void
    {
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [],
            actorId: $contador->id,
        );

        $this->assertSame('0.00', $arqueo->expected_amount);
        $this->assertSame('0.00', $arqueo->counted_amount);
        $this->assertTrue($arqueo->isBalanced());

        $arqueo = app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        $this->assertSame(CashCountStatus::Reviewed, $arqueo->status);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $cierre->status);
    }

    /** Pero con plata en el libro, contar nada sigue sin poder revisarse. */
    public function test_con_saldo_en_el_libro_no_se_revisa_un_arqueo_vacio(): void
    {
        $this->abrirLibros(efectivo: '1000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->recibirEfectivo('1000.00', '2026-06-02');

        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [],
            actorId: $contador->id,
            explanation: 'No se llegó a contar.',
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sin denominaciones contadas');

        app(ReviewCashCount::class)->handle($arqueo, $contador->id);
    }

    /**
     * El mes no cierra sobre días que nunca se cerraron.
     *
     * Los totales del mensual salen del libro, así que cerraban igual
     * aunque ninguna jornada hubiera pasado por su arqueo. El control
     * diario —que es donde se detecta un faltante— quedaba salteado sin
     * que nada lo dijera.
     */
    public function test_el_mes_no_cierra_con_dias_operados_sin_cerrar(): void
    {
        $this->abrirLibros(efectivo: '100000.00', cheques: '0.00', fecha: '2026-05-31');
        $this->cobrar('5000.00', '2026-06-02');
        $this->cobrar('7000.00', '2026-06-03');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:00'));

        $contador = User::factory()->create();

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-30'),
                type: PeriodType::Monthly,
                actorId: $contador->id,
            );

            $this->fail('El mes no debería cerrar con días operados sin cerrar.');
        } catch (ValidationException $e) {
            $mensaje = $e->validator->errors()->first('period');

            $this->assertStringContainsString('sin cerrar', $mensaje);
            $this->assertStringContainsString('02/06', $mensaje);
            $this->assertStringContainsString('03/06', $mensaje);
        }

        // Cerrados los tres días con movimiento, el mes cierra.
        foreach (['2026-05-31', '2026-06-02', '2026-06-03'] as $dia) {
            $this->arqueoListoParaCerrar($this->caja(), $dia, $contador);

            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse($dia),
                actorId: $contador->id,
            );
        }

        $mes = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
            type: PeriodType::Monthly,
            actorId: $contador->id,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $mes->status);

        CarbonImmutable::setTestNow();
    }

    /**
     * Un día sin un solo asiento no necesita cierre.
     *
     * Se exigen los días con movimiento, no los del calendario: pedir
     * planilla para cada sábado obligaría a inventar treinta cierres
     * vacíos por mes.
     */
    public function test_los_dias_sin_movimiento_no_traban_el_mes(): void
    {
        $this->abrirLibros(efectivo: '100000.00', cheques: '0.00', fecha: '2026-05-31');
        $this->cobrar('5000.00', '2026-06-02');

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 09:00'));

        $contador = User::factory()->create();

        foreach (['2026-05-31', '2026-06-02'] as $dia) {
            $this->arqueoListoParaCerrar($this->caja(), $dia, $contador);

            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse($dia),
                actorId: $contador->id,
            );
        }

        // Los otros veintiocho días de junio no tienen nada, y no hacen falta.
        $mes = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
            type: PeriodType::Monthly,
            actorId: $contador->id,
        );

        $this->assertSame(PeriodClosingStatus::Closed, $mes->status);

        CarbonImmutable::setTestNow();
    }

    /**
     * Un día cerrado no se vuelve a arquear.
     *
     * **El cierre congela la jornada, y el arqueo es parte de ella.** Un
     * conteo cargado después dejaba la planilla ya emitida diciendo una
     * cosa y el último arqueo del día diciendo otra; y como el cierre lee
     * el último, al reabrir y recerrar tomaría como respaldo un conteo que
     * nadie revisó contra ese snapshot.
     *
     * Es el tercer lado de la misma regla: no se cierra sin arqueo, no se
     * cierra con una diferencia viva, y no se arquea lo ya cerrado.
     */
    public function test_un_dia_cerrado_no_se_vuelve_a_arquear(): void
    {
        $this->abrirLibros(efectivo: '100000.00', cheques: '0.00', fecha: '2026-06-01');

        $contador = User::factory()->create();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02', $contador);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $antes = CashCount::query()->count();

        try {
            app(RecordCashCount::class)->handle(
                cashBoxId: $this->caja(),
                countedOn: CarbonImmutable::parse('2026-06-02'),
                denominations: [20_000 => 1],
                actorId: $contador->id,
                explanation: 'Conteo cargado después del cierre.',
            );

            $this->fail('No debería aceptarse un arqueo de un día cerrado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'período cerrado',
                $e->validator->errors()->first('counted_on'),
            );
        }

        $this->assertSame($antes, CashCount::query()->count());

        // Reabierto, el conteo vuelve a ser posible: es el camino correcto.
        $cierre = PeriodClosing::query()->firstOrFail();

        app(ReopenPeriod::class)->handle($cierre, $contador->id, 'Faltó cargar un recibo.');

        $nuevo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-02'),
            denominations: [20_000 => 1],
            actorId: $contador->id,
            explanation: 'Conteo del período reabierto.',
        );

        $this->assertSame($antes + 1, CashCount::query()->count());
        $this->assertSame(2, $nuevo->sequence);
    }

    /**
     * Y la base lo impide sola.
     *
     * El trigger mira solo los `INSERT`: el propio cierre marca como
     * `closed` los arqueos del período que cierra, y eso es un `UPDATE`
     * que ocurre con la fila del cierre ya escrita. Mirarlos también haría
     * que cerrar se rechazara a sí mismo.
     */
    public function test_la_base_rechaza_un_arqueo_de_un_dia_cerrado(): void
    {
        $this->abrirLibros(efectivo: '100000.00', cheques: '0.00', fecha: '2026-06-01');

        $contador = User::factory()->create();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02', $contador);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('periodo cerrado');

        DB::table('cash_counts')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => Currency::Ars->value,
            'counted_on' => '2026-06-02',
            'sequence' => 9,
            'counted_at' => now(),
            'expected_amount' => '100000.00',
            'counted_amount' => '100000.00',
            'uncounted_amount' => '0.00',
            'status' => CashCountStatus::Draft->value,
            'performed_by' => $contador->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Un asiento anterior no deja desactualizado un cierre posterior.
     *
     * **Cerrar el 15 dejando el 14 abierto es legítimo**: los días son
     * independientes y cada cierre lee el libro, no el cierre previo. Lo
     * que no puede pasar es cargar después un recibo en el 14 —que sigue
     * abierto, así que la guarda de período cerrado no lo alcanzaba— y
     * que el cierre del 15 quede mintiendo por ese importe.
     *
     * Se comprobó que pasaba: el cierre seguía diciendo 1.150.000 y el
     * libro 1.927.000, en silencio.
     */
    public function test_un_asiento_anterior_no_desactualiza_un_cierre_posterior(): void
    {
        $this->abrirLibros(efectivo: '1000000.00', cheques: '0.00', fecha: '2026-06-01');
        $this->cobrar('100000.00', '2026-06-14');
        $this->cobrar('50000.00', '2026-06-15');

        $contador = User::factory()->create();

        // Se cierra el 15 y el 14 queda abierto.
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-15', $contador);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            actorId: $contador->id,
        );

        $this->assertSame('1150000.00', $cierre->closing_cash);

        try {
            $this->cobrar('777000.00', '2026-06-14');

            $this->fail('No debería aceptarse un asiento que invalida el cierre del 15.');
        } catch (ClosedPeriodException $e) {
            $this->assertStringContainsString('saldo inicial del cierre', $e->getMessage());
            // Nombra el cierre que hay que reabrir, no el día del asiento.
            $this->assertSame('2026-06-15', $e->closing->period_from->toDateString());
        }

        // El cierre sigue coincidiendo con el libro.
        $this->assertSame(
            $cierre->refresh()->closing_cash,
            app(CashBalance::class)->of(
                LedgerAccount::CashOnHand,
                $this->caja(),
                Currency::Ars,
                CarbonImmutable::parse('2026-06-15'),
            ),
        );

        // Reabierto, el movimiento entra: es la salida que el mensaje indica.
        app(ReopenPeriod::class)->handle($cierre, $contador->id, 'Faltaba el recibo del 14.');

        $this->cobrar('777000.00', '2026-06-14');

        $this->assertSame('1927000.00', app(CashBalance::class)->of(
            LedgerAccount::CashOnHand,
            $this->caja(),
            Currency::Ars,
            CarbonImmutable::parse('2026-06-15'),
        ));
    }

    /** Y la base lo impide sola. */
    public function test_la_base_rechaza_un_asiento_que_invalida_un_cierre_posterior(): void
    {
        $this->abrirLibros(efectivo: '1000000.00', cheques: '0.00', fecha: '2026-06-01');

        $contador = User::factory()->create();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-15', $contador);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            actorId: $contador->id,
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cambiaria el saldo inicial');

        $evento = DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => '2026-06-14',
            'status' => 'posted',
            'idempotency_key' => 'crudo-'.Str::random(10),
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            [
                'financial_event_id' => $evento,
                'account_code' => LedgerAccount::CashOnHand->value,
                'currency' => Currency::Ars->value,
                'debit' => '100.00',
                'credit' => '0.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
            [
                'financial_event_id' => $evento,
                'account_code' => LedgerAccount::UnassignedFunds->value,
                'currency' => Currency::Ars->value,
                'debit' => '0.00',
                'credit' => '100.00',
                'cash_box_id' => $this->caja(),
                'created_at' => now(),
            ],
        ]);

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * La confirmación del cierre dice el saldo como se escribe.
     *
     * `closing_cash` llega del cast `decimal:2`, o sea `'9852300.00'`, y el
     * mensaje lo interpolaba crudo: «saldo final $ 9852300.00». Un importe
     * de siete cifras sin separadores no se lee, y este en particular es el
     * número que el contador compara contra la planilla.
     */
    public function test_el_cierre_confirma_el_saldo_con_el_formato_del_sistema(): void
    {
        $this->abrirLibros();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-01');

        $this->actingAs($this->operador('contador'))
            ->post(route('caja.cierres.store'), [
                'cashBoxId' => $this->caja(),
                'date' => '2026-06-01',
                'periodType' => PeriodType::Daily->value,
                'currency' => Currency::Ars->value,
            ])
            ->assertRedirect();

        $this->assertToast(
            'Período del 01/06/2026 cerrado.',
            'Saldo final $ 9.852.300,00 en efectivo.',
        );
    }

    // ─────────────────────────── Andamiaje ───────────────────────────

    /**
     * Un asiento a medio escribir, puesto sin pasar por ningún Action.
     *
     * `PostJournalEntry` siempre asienta; para tener un borrador hay que
     * escribirlo a mano, que es igual lo que hace la pantalla que los
     * guarda a medio cargar.
     */
    private function borradorCrudo(string $fecha, Currency $moneda): void
    {
        $evento = DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => $fecha,
            'status' => 'draft',
            'idempotency_key' => 'borrador-'.Str::random(10),
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

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(
        string $efectivo = '9852300.00',
        string $cheques = '673804.70',
        string $fecha = '2026-05-31',
    ): void {
        $saldos = [LedgerAccount::CashOnHand->value => $efectivo];

        if ($cheques !== '0.00') {
            $saldos[LedgerAccount::ChequesInCustody->value] = $cheques;
        }

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: $saldos,
            denominations: $this->billetesPara($saldos[LedgerAccount::CashOnHand->value]),
            date: CarbonImmutable::parse($fecha),
        );
    }

    /**
     * Cuántos arqueos tiene un día.
     *
     * Contar la tabla entera dejo de servir cuando la apertura empezó a
     * dejar el suyo: ese conteo es de otro día y no tiene nada que ver
     * con los turnos que estos tests miran.
     */
    private function arqueosDel(string $fecha): int
    {
        return CashCount::query()
            ->where('cash_box_id', $this->caja())
            ->whereDate('counted_on', $fecha)
            ->count();
    }

    /** Un ingreso de efectivo, como los recibos 72190 a 72198 del 02/06. */
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

    /** Un ingreso real por mostrador, visible para la recaudación del día. */
    private function recibirEfectivo(string $importe, string $fecha): void
    {
        app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(12),
            cashBoxId: $this->caja(),
            receivedDate: CarbonImmutable::parse($fecha),
        );
    }

    /** Un egreso por mostrador, como los recibos 76397 a 76455. */
    private function pagar(string $importe, string $fecha): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashDisbursement,
            idempotencyKey: 'egreso-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, $importe)->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: $this->caja(),
        );
    }
}
