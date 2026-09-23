<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Data\PeriodClosingListItemData;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Las tres pantallas de caja.
 *
 * Lo que se prueba acá es el reparto de permisos y que el saldo que llega
 * a la pantalla salga del libro. **El reparto no es decorativo**: contar el
 * cajón es trabajo de mostrador, imputar una diferencia mueve plata contra
 * `CASH_DIFFERENCE` sin que haya entrado ni salido nada, y reabrir un
 * período deshace algo que el área pudo haber archivado en papel. Que un
 * administrativo llegue a lo último sería un agujero, no una comodidad.
 */
class PantallasDeCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_la_caja_muestra_el_saldo_calculado_desde_el_libro(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/dia?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/index')
                ->where('state.cash', '9852300.00')
                ->where('state.cheques', '673804.70')
                ->where('selected.date', '2026-06-01')
            );
    }

    public function test_la_url_anterior_redirige_al_dia_y_conserva_los_filtros(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja?fecha=2026-06-01&moneda=usd')
            ->assertRedirect('/caja/dia?fecha=2026-06-01&moneda=usd');
    }

    public function test_la_caja_no_deja_mirar_el_futuro(): void
    {
        $this->abrirLibros();

        $manana = BusinessDate::today()->addDay()->toDateString();
        $hoy = BusinessDate::today()->toDateString();

        $this->actingAs($this->operador('administrativo'))
            ->get("/caja/dia?fecha={$manana}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selected.date', $hoy));
    }

    public function test_una_fecha_ilegible_no_rompe_la_pantalla(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/dia?fecha=el-martes-pasado')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.date', BusinessDate::today()->toDateString())
            );
    }

    /**
     * La jornada se cierra sin salir de la caja del día.
     *
     * Contar, revisar y cerrar son tres actos encadenados —el cierre no
     * admite arqueos en borrador— y ahora los tres se hacen desde la misma
     * pantalla. Lo que se prueba es la cadena entera con **un solo
     * usuario**, que es el caso que el área pidió poder cubrir.
     */
    public function test_un_solo_usuario_cuenta_revisa_y_cierra_el_dia(): void
    {
        $this->abrirLibros();

        $contador = $this->operador('contador');

        // 1 · Contar el cajón.
        $this->actingAs($contador)
            ->from('/caja/dia?fecha=2026-06-02')
            ->post('/caja/arqueos', [
                'cashBoxId' => $this->caja(),
                'countedOn' => '2026-06-02',
                'currency' => 'ARS',
                // Suma exacta del saldo de apertura: 9.852.300.
                'denominations' => [100_000 => 98, 50_000 => 1, 2_000 => 1, 200 => 1, 100 => 1],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/caja/dia?fecha=2026-06-02');

        $arqueo = CashCount::query()->firstOrFail();
        $this->assertSame(CashCountStatus::Draft, $arqueo->status);

        // Con el arqueo en borrador, el cierre todavía no puede.
        $this->assertSame(0, PeriodClosing::query()->count());

        // 2 · Revisarlo, el mismo usuario.
        $this->actingAs($contador)
            ->post("/caja/arqueos/{$arqueo->id}/revisar")
            ->assertSessionHasNoErrors();

        $arqueo->refresh();
        $this->assertSame(CashCountStatus::Reviewed, $arqueo->status);
        $this->assertTrue($arqueo->wasSelfReviewed());

        // 3 · Y cerrar el día.
        $this->actingAs($contador)
            ->post('/caja/cierres', [
                'cashBoxId' => $this->caja(),
                'date' => '2026-06-02',
                'periodType' => 'daily',
                'currency' => 'ARS',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PeriodClosing::query()->where('status', 'closed')->count());
    }

    /** La caja del día lleva lo que el arqueo necesita para cargarse ahí. */
    public function test_la_caja_del_dia_trae_las_denominaciones_y_los_permisos(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('contador'))
            ->get('/caja/dia?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('suggestedDenominations')
                ->where('can.count', true)
                ->where('can.review', true)
                ->where('can.adjust', true)
            );
    }

    public function test_el_administrativo_consulta_el_listado_pero_no_revisa_ni_imputa(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/arqueos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/arqueos')
                ->missing('can.count')
                ->where('can.review', false)
                ->where('can.adjust', false)
            );
    }

    /**
     * La Caja muestra el libro de la moneda que se le pide.
     *
     * El mismo cajón guarda las dos y la base las separa; cuál de los dos
     * libros se está leyendo es un dato de la vista, y viaja en la URL.
     */
    public function test_la_caja_muestra_el_libro_de_la_moneda_pedida(): void
    {
        $usuario = $this->operador('contador');

        $this->actingAs($usuario)
            ->get('/caja/dia?moneda=usd')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.currency', 'USD')
                ->where('suggestedDenominations.0', 100)
            );

        // Lo que no es una moneda cae en pesos en vez de romper.
        $this->actingAs($usuario)
            ->get('/caja/dia?moneda=euros')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.currency', 'ARS')
                ->where('suggestedDenominations.0', 100_000)
            );
    }

    /**
     * El libro en dólares se abre y se arquea con los billetes del dólar.
     *
     * De punta a punta y por HTTP a propósito: lo que estaba cerrado no
     * eran los Actions --que siempre aceptaron la moneda-- sino la
     * validación de estas dos pantallas.
     */
    public function test_se_abre_el_libro_en_dolares_y_se_arquea_con_sus_billetes(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'USD',
                'date' => '2026-05-31',
                'balances' => [LedgerAccount::CashOnHand->value => '320.00'],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador('administrativo'))
            ->post('/caja/arqueos', [
                'cashBoxId' => $this->caja(),
                'countedOn' => '2026-06-01',
                'currency' => 'USD',
                'denominations' => [100 => 3, 20 => 1],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $arqueo = CashCount::query()->firstOrFail();

        $this->assertSame(Currency::Usd, $arqueo->currency);
        $this->assertSame('320.00', $arqueo->counted_amount);
        // Los dos libros dan lo mismo: no hay diferencia que explicar.
        $this->assertSame('0.00', $arqueo->difference_amount);
    }

    public function test_arqueos_y_cierres_conservan_el_dia_desde_el_que_se_llega(): void
    {
        $usuario = $this->operador('contador');

        $this->actingAs($usuario)
            ->get('/caja/arqueos?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.defaultDate', '2026-06-01')
            );

        $this->actingAs($usuario)
            ->get('/caja/cierres?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selected.defaultDate', '2026-06-01')
            );
    }

    public function test_cierres_solo_ofrece_ir_al_dia_a_quien_puede_verlo(): void
    {
        $this->actingAs($this->operador('contador'))
            ->get('/caja/cierres')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.viewDay', true)
            );

        $soloCierres = $this->operador('contador');
        $soloCierres->syncRoles([]);
        $soloCierres->givePermissionTo('cierres.ver');

        $this->actingAs($soloCierres)
            ->get('/caja/cierres')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.viewDay', false)
            );
    }

    public function test_el_contador_revisa_e_imputa(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('contador'))
            ->get('/caja/arqueos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.review', true)
                ->where('can.adjust', true)
            );
    }

    public function test_el_administrativo_no_puede_cerrar_ni_reabrir(): void
    {
        $this->abrirLibros();

        $administrativo = $this->operador('administrativo');

        $this->actingAs($administrativo)
            ->post('/caja/cierres', [
                'cashBoxId' => $this->caja(),
                'date' => '2026-06-01',
                'periodType' => 'daily',
                'currency' => 'ARS',
            ])
            ->assertForbidden();

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-01');
        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-01'),
        );

        $this->actingAs($administrativo)
            ->post("/caja/cierres/{$cierre->id}/reabrir", ['reason' => 'Faltó un recibo.'])
            ->assertForbidden();
    }

    public function test_registrar_un_arqueo_desde_la_pantalla(): void
    {
        $this->abrirLibros(efectivo: '2034800.00');

        $this->actingAs($this->operador('administrativo'))
            ->post('/caja/arqueos', [
                'cashBoxId' => $this->caja(),
                'countedOn' => '2026-06-01',
                'currency' => 'ARS',
                'denominations' => [
                    20000 => 101,
                    10000 => 1,
                    1000 => 4,
                    500 => 1,
                    200 => 1,
                    100 => 1,
                ],
            ])
            ->assertRedirect();

        $arqueo = CashCount::query()->firstOrFail();

        $this->assertSame('2034800.00', $arqueo->counted_amount);
        $this->assertSame(CashCountStatus::Draft, $arqueo->status);
        $this->assertSame(6, $arqueo->lines()->count());
    }

    /** La caja del arqueo la pone el servidor, no lo que manda el navegador. */
    public function test_el_arqueo_no_confia_en_la_caja_del_navegador(): void
    {
        $otraCaja = CashBox::query()->where('code', '!=', CashBox::HABERES)->firstOrFail();

        $this->actingAs($this->operador('administrativo'))
            ->post('/caja/arqueos', [
                'cashBoxId' => $otraCaja->id,
                'countedOn' => '2026-06-01',
                'currency' => 'ARS',
                'denominations' => [],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $this->caja(),
            (int) CashCount::query()->firstOrFail()->cash_box_id,
        );
    }

    public function test_el_arqueo_no_acepta_un_conteo_de_manana(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('administrativo'))
            ->post('/caja/arqueos', [
                'cashBoxId' => $this->caja(),
                'countedOn' => BusinessDate::today()->addDay()->toDateString(),
                'currency' => 'ARS',
                'denominations' => [],
            ])
            ->assertSessionHasErrors('countedOn');
    }

    public function test_cerrar_y_bajar_la_planilla(): void
    {
        Storage::fake('local');
        $this->abrirLibros();

        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-01', $contador);

        $this->actingAs($contador)
            ->post('/caja/cierres', [
                'cashBoxId' => $this->caja(),
                'date' => '2026-06-01',
                'periodType' => 'daily',
                'currency' => 'ARS',
            ])
            ->assertRedirect();

        $cierre = PeriodClosing::query()->firstOrFail();
        $this->assertSame('9852300.00', $cierre->closing_cash);

        /*
         * La planilla no se devuelve directo: se guarda como adjunto y se
         * redirige a la única puerta que sirve archivos, que es la que
         * comprueba permisos.
         */
        $this->actingAs($contador)
            ->get("/caja/cierres/{$cierre->id}/planilla")
            ->assertRedirectContains('/adjuntos/');
    }

    public function test_reabrir_exige_motivo(): void
    {
        $this->abrirLibros();

        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-01', $contador);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-01'),
            actorId: $contador->id,
        );

        $this->actingAs($contador)
            ->post("/caja/cierres/{$cierre->id}/reabrir", ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    /**
     * Un día cerrado se opera desde la caja, sin ir a Cierres.
     *
     * Reabrirlo, rehacer su planilla o ver las versiones emitidas vivía
     * solo en la lista de períodos, y para usar cualquiera de las tres
     * había que salir del día que se tenía abierto y buscar su tarjeta
     * entre todas. La pantalla necesita los permisos y la planilla del
     * cierre para poder ofrecerlas acá.
     */
    public function test_la_caja_del_dia_trae_lo_que_el_cierre_necesita(): void
    {
        $this->abrirLibros();

        $administrador = $this->operador('administrador');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-01', $administrador);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-01'),
            actorId: $administrador->id,
        );

        app(ExportCashSheet::class)->handle($cierre, $administrador->id);

        $this->actingAs($administrador)
            ->get('/caja/dia?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.reopen', true)
                ->where('can.regenerate', true)
                ->has('sheetVersion')
                // La planilla emitida y su historial, para los tres botones.
                ->where('closing.sheetAttachmentId', $cierre->refresh()->sheet_attachment_id)
                ->has('closing.sheetHistory', 1)
                ->where('closing.sheetTemplateVersion', CashSheetWorkbook::VERSION)
            );
    }

    /**
     * El arqueo anterior viaja como referencia, no como plantilla.
     *
     * Sirve para dos cosas: copiar el fajo que no se recuenta --una
     * declaración, la misma todos los días-- y comparar al revisar. Las
     * denominaciones nunca se precargan: un conteo que arranca con los
     * números de ayer deja de ser un conteo, y un arqueo copiado es
     * indistinguible de uno real.
     */
    public function test_la_caja_manda_el_arqueo_anterior_como_referencia(): void
    {
        $this->abrirLibros();

        $contador = $this->operador('contador');

        $ayer = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-01'),
            denominations: [20_000 => 1],
            actorId: $contador->id,
            uncountedAmount: '9832300.00',
            uncountedReason: 'Fondo histórico en caja fuerte.',
        );

        app(ReviewCashCount::class)->handle($ayer, $contador->id);

        $this->actingAs($contador)
            ->get('/caja/dia?fecha=2026-06-02')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('previousCount.countedOn', '2026-06-01')
                ->where('previousCount.uncountedAmount', '9832300.00')
                ->where('previousCount.uncountedReason', 'Fondo histórico en caja fuerte.')
            );
    }

    public function test_el_listado_de_arqueos_no_precarga_datos_para_un_conteo_nuevo(): void
    {
        $this->actingAs($this->operador('contador'))
            ->get('/caja/arqueos?fecha=2026-06-01')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->missing('expectedCash')
                ->missing('suggestedDenominations')
                ->missing('can.count')
            );
    }

    /** Y un borrador no es referencia de nada: no concluyó. */
    public function test_un_arqueo_en_borrador_no_viaja_como_anterior(): void
    {
        $this->abrirLibros();

        app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-01'),
            denominations: [20_000 => 1],
            actorId: $this->operador('contador')->id,
            uncountedAmount: '9832300.00',
            uncountedReason: 'Fondo histórico en caja fuerte.',
        );

        $this->actingAs($this->operador('contador'))
            ->get('/caja/dia?fecha=2026-06-02')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('previousCount', null)
            );
    }

    /**
     * La pantalla avisa si el cajón ya se movió después de ese día.
     *
     * No es un aviso sobre la aritmética --el arqueo del 14 se compara
     * contra el libro al 14, y el sistema nunca mezcla jornadas-- sino
     * sobre la plata física: si el 15 ya entró un cobro, lo que hay en el
     * cajón ahora incluye esa plata, y contarlo para el 14 dejaría una
     * diferencia atribuida al día equivocado.
     */
    public function test_la_caja_avisa_si_hubo_movimientos_posteriores(): void
    {
        $this->abrirLibros();
        $this->cobrar('50000.00', '2026-06-03');

        $this->actingAs($this->operador('contador'))
            ->get('/caja/dia?fecha=2026-06-02')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('movedAfter', true));

        // Y el último día con movimiento no tiene nada después.
        $this->actingAs($this->operador('contador'))
            ->get('/caja/dia?fecha=2026-06-03')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('movedAfter', false));
    }

    /**
     * Un cierre desactualizado se ve idéntico a uno sano, así que se marca.
     *
     * El cierre se calcula del libro y se guarda. Si después entrara un
     * movimiento con fecha anterior, el guardado quedaría mintiendo — y
     * hasta hace poco eso pasaba en silencio.
     *
     * **El estado corrupto ya no se puede fabricar**, y eso es lo que se
     * comprueba de paso acá: el asiento anterior lo rechazan el Action y
     * el trigger, y el cierre en sí tiene su propio congelado que impide
     * editarlo. Queda la comparación, que es la red para los datos
     * cargados antes de esas reglas: la pantalla dice si cada cierre
     * sigue coincidiendo con el libro.
     */
    public function test_la_pantalla_dice_si_el_cierre_sigue_coincidiendo_con_el_libro(): void
    {
        $this->abrirLibros('1000000.00');

        $contador = $this->operador('contador');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-15', $contador);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            actorId: $contador->id,
        );

        $this->actingAs($contador)
            ->get('/caja/cierres')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('closings.0.matchesLedger', true));

        // Y contra un libro que dijera otra cosa, lo diría.
        $this->assertFalse(
            PeriodClosingListItemData::fromModel($cierre, '999.00')->matchesLedger,
        );

        // Sin comparar, no afirma nada: `null` no es «está bien».
        $this->assertNull(
            PeriodClosingListItemData::fromModel($cierre)->matchesLedger,
        );
    }

    public function test_quien_solo_consulta_ve_la_caja_sin_botones(): void
    {
        $this->abrirLibros();

        $this->actingAs($this->operador('consulta'))
            ->get('/caja/dia')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can.count', false)
                ->where('can.close', false)
                ->where('can.reopen', false)
                ->where('can.export', true)
            );
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(string $efectivo = '9852300.00'): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [
                LedgerAccount::CashOnHand->value => $efectivo,
                LedgerAccount::ChequesInCustody->value => '673804.70',
            ],
            date: CarbonImmutable::parse('2026-06-01'),
        );
    }

    /** Un ingreso de efectivo, para que el día tenga movimiento. */
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
