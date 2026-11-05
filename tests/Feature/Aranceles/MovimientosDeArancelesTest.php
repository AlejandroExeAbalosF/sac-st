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
use App\Modules\Aranceles\Actions\ReplaceFeeReceipt;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Support\FeeCashBoxModule;
use App\Modules\Aranceles\Support\FeeMovements;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\RecordTransferTicket;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/** El informe de un mes de Aranceles (reglas A100 a A105): consulta y Excel. */
class MovimientosDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private User $admin;

    private int $caja;

    private BankAccount $banco;

    private int $clave = 0;

    private CarbonImmutable $mes;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Argentina/Salta'));
        $this->admin = $this->operador('administrador');
        $this->caja = $this->cajaDe(CashBox::ARANCELES);
        $this->banco = $this->cuenta();
        $this->mes = BusinessDate::today()->startOfMonth();

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

    private function tipo(int $codigo = 14): int
    {
        return (int) FeeType::query()->where('code', $codigo)->value('id');
    }

    private function cobrar(int $cantidad = 1, int $codigo = 14, string $nombre = 'AG Max SRL'): FeeCharge
    {
        return app(CollectFeeInCash::class)->handle(
            $nombre, null, [['feeTypeId' => $this->tipo($codigo), 'quantity' => $cantidad]],
            'cobro-'.(++$this->clave), $this->admin->id, null, null, null, true,
        );
    }

    private function pendiente(): FeeCharge
    {
        return app(CreateFeeCharge::class)->handle(
            'Por banco SA', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1]],
            'alta-'.(++$this->clave), $this->admin->id, true,
        );
    }

    private function identificar(FeeCharge $cobro, string $importe, ?CarbonImmutable $fecha = null): BankTransaction
    {
        $credito = $this->movimiento($this->banco, ($fecha ?? BusinessDate::today())->toDateString(), $importe);
        app(IdentifyFeeCredits::class)->handle($cobro, [$credito->id], 'identifica-'.(++$this->clave), null, $this->admin->id);

        return $credito;
    }

    private function anular(FeeCharge $cobro): void
    {
        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);
    }

    private function mesSiguiente(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'America/Argentina/Salta'));
    }

    private function movimientos(): FeeMovements
    {
        return app(FeeMovements::class);
    }

    /** @return array<string, mixed> */
    private function seccion(string $clave, ?CarbonImmutable $mes = null): array
    {
        return $this->movimientos()->summary($clave, $mes ?? $this->mes, 100);
    }

    // ── Recaudación ───────────────────────────────────────────────────────

    public function test_collection_is_reported_by_type_and_medium(): void
    {
        $this->cobrar(2);
        $this->cobrar(1, 25);
        $this->identificar($this->pendiente(), '120');

        $porTipo = $this->movimientos()->byType($this->mes);

        $this->assertCount(2, $porTipo);
        $this->assertSame(14, $porTipo[0]['code']);
        $this->assertSame(3, $porTipo[0]['quantity']);
        $this->assertSame('240.00', $porTipo[0]['cash']);
        $this->assertSame('120.00', $porTipo[0]['bank']);
        $this->assertSame('360.00', $porTipo[0]['total']);
        $this->assertSame(25, $porTipo[1]['code']);
    }

    /** Lo que informa la recaudación es lo que el libro aplicó en el mes, con cada caso. */
    public function test_collection_matches_the_ledger(): void
    {
        $this->cobrar();
        $anulado = $this->cobrar();
        $this->anular($anulado);
        $corregido = $this->cobrar();
        app(CorrectFeeCharge::class)->handle(
            $corregido, 'AG Max SRL', null, [['feeTypeId' => $this->tipo(25), 'quantity' => 1]],
            new Reason(FeeReasonCode::WrongType, null), null, $this->admin->id, true,
        );
        $this->identificar($this->pendiente(), '500');

        $this->assertSame(
            app(CashBalance::class)->of(LedgerAccount::CollectedFunds, $this->caja, Currency::Ars),
            $this->seccion('recaudacion')['total'],
        );
    }

    /** A101: un mes pasado no cambia; la anulación resta en el suyo. */
    public function test_a_void_in_a_later_month_stays_in_its_own(): void
    {
        $cobro = $this->cobrar();

        $this->mesSiguiente();
        $this->anular($cobro);

        $this->assertSame('120.00', $this->seccion('recaudacion')['total']);

        $octubre = BusinessDate::today()->startOfMonth();
        $delMes = $this->seccion('recaudacion', $octubre);

        $this->assertSame('-120.00', $delMes['total']);
        $this->assertStringContainsString('Anulación', $delMes['rows'][0]['detail']);
        $this->assertSame(-1, $this->movimientos()->byType($octubre)[0]['quantity']);
    }

    public function test_voided_in_the_same_month_nets_zero(): void
    {
        $this->anular($this->cobrar());

        $seccion = $this->seccion('recaudacion');

        $this->assertSame(2, $seccion['count']);
        $this->assertSame('0.00', $seccion['total']);
    }

    /** A101: la corrección tiene dos filas en su mes, y el mes del cobro original no cambia. */
    public function test_a_correction_shows_in_its_own_month(): void
    {
        $cobro = $this->cobrar();

        $this->mesSiguiente();
        app(CorrectFeeCharge::class)->handle(
            $cobro, 'AG Max SRL', null, [['feeTypeId' => $this->tipo(25), 'quantity' => 1]],
            new Reason(FeeReasonCode::WrongType, null), null, $this->admin->id, true,
        );

        $this->assertSame('120.00', $this->seccion('recaudacion')['total']);

        $delMes = $this->seccion('recaudacion', BusinessDate::today()->startOfMonth());

        $this->assertSame(2, $delMes['count']);
        $this->assertSame('-70.00', $delMes['total']);
        $detalles = implode(' | ', array_column($delMes['rows'], 'detail'));
        $this->assertStringContainsString('Corrección', $detalles);
        $this->assertStringContainsString('corrige un cobro anterior', $detalles);
    }

    /** A102: un reemplazo no mueve la recaudación; muestra el recibo y el nombre vigentes. */
    public function test_a_replacement_does_not_change_collection(): void
    {
        $cobro = $this->cobrar();

        app(ReplaceFeeReceipt::class)->handle($cobro, 'Nombre Corregido', null, [], new Reason(FeeReasonCode::WrongPayer, null), null, $this->admin->id);

        $seccion = $this->seccion('recaudacion');

        $this->assertSame(1, $seccion['count']);
        $this->assertSame('120.00', $seccion['total']);
        $this->assertSame('Nombre Corregido', $seccion['rows'][0]['payer']);
        $this->assertStringEndsWith('2', $seccion['rows'][0]['receipt']);
    }

    // ── Ingresos parciales ────────────────────────────────────────────────

    /** A103: no son recaudación hasta el recibo, y el mes en que entraron no cambia. */
    public function test_a_partial_is_not_collection_and_its_month_does_not_change(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, '100');

        $this->assertSame('0.00', $this->seccion('recaudacion')['total']);
        $this->assertSame('100.00', $this->seccion('parciales')['total']);

        $this->mesSiguiente();
        $this->identificar($cobro, '20');
        $octubre = BusinessDate::today()->startOfMonth();

        $septiembre = $this->seccion('parciales');
        $this->assertSame('100.00', $septiembre['total']);
        $this->assertStringContainsString('se completó', $septiembre['rows'][0]['detail']);

        $this->assertSame('0.00', $this->seccion('parciales', $octubre)['total']);
        $this->assertSame('120.00', $this->seccion('recaudacion', $octubre)['total']);
    }

    public function test_a_partial_completed_in_the_same_month_is_not_listed(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, '100');
        $this->identificar($cobro, '20');

        $this->assertSame(0, $this->seccion('parciales')['count']);
    }

    public function test_a_voided_partial_shows_its_reversal(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, '100');

        $this->mesSiguiente();
        $this->anular($cobro);

        $this->assertSame('100.00', $this->seccion('parciales')['total']);
        $this->assertSame('-100.00', $this->seccion('parciales', BusinessDate::today()->startOfMonth())['total']);
    }

    public function test_correcting_a_bank_payment_does_not_rewrite_past_partials(): void
    {
        $cobro = $this->pendiente();
        $this->identificar($cobro, '120');

        $this->mesSiguiente();
        app(CorrectFeeCharge::class)->handle(
            $cobro, 'Otro nombre', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1]],
            new Reason(FeeReasonCode::Other, 'Detalle'), null, $this->admin->id, true,
        );

        $this->assertSame(0, $this->seccion('parciales')['count']);
    }

    // ── Movimiento de fondos ──────────────────────────────────────────────

    /** Cancelar otro mes no cambia un mes pasado: la reversión resta en su fecha. */
    public function test_a_transfer_cancelled_in_a_later_month_does_not_change_its_month(): void
    {
        $this->cobrar();
        $traslado = app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, '100', BusinessDate::today(), null, null, 'traspaso', $this->admin->id,
        );

        $this->mesSiguiente();
        app(CancelCashToBankTransfer::class)->handle($traslado, 'Se cargó por error', 'cancela', $this->admin->id);

        $octubre = $this->seccion('traspasos', BusinessDate::today()->startOfMonth());

        $septiembre = $this->seccion('traspasos');

        $this->assertSame('100.00', $septiembre['total']);
        $this->assertStringContainsString('cancelado el 05/10/2026', $septiembre['rows'][0]['detail']);
        $this->assertSame(1, $octubre['count']);
        $this->assertSame('-100.00', $octubre['total']);
        $this->assertStringContainsString('Cancelación', $octubre['rows'][0]['detail']);
        $this->assertStringContainsString('Se cargó por error', $octubre['rows'][0]['detail']);
    }

    /** A104: depositar no suma recaudación; cancelado en el mismo mes da cero. */
    public function test_funds_movement_is_apart_from_collection(): void
    {
        $this->cobrar(2);
        $traspasar = fn (string $importe) => app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, $importe, BusinessDate::today(), null, null, 'traspaso-'.(++$this->clave), $this->admin->id,
        );

        $cancelado = $traspasar('100');
        app(CancelCashToBankTransfer::class)->handle($cancelado, 'Se cargó por error', 'cancela', $this->admin->id);

        $traslado = $traspasar('140');
        $traslado = app(RecordTransferTicket::class)->handle(
            $traslado, '140', ['depositDate' => BusinessDate::today()],
            UploadedFile::fake()->image('c.jpg'), 'comprobante', $this->admin->id,
        );
        app(ConfirmCashDepositCredit::class)->handle(
            $traslado, $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '140'), 'acredita', $this->admin->id,
        );

        $traspasos = $this->seccion('traspasos');

        $this->assertSame(3, $traspasos['count']);
        $this->assertSame('140.00', $traspasos['total']);
        $this->assertSame(['100.00', '-100.00', '140.00'], array_column($traspasos['rows'], 'amount'));
        $this->assertSame('140.00', $this->seccion('acreditaciones')['total']);
        $this->assertSame('240.00', $this->seccion('recaudacion')['total']);
    }

    // ── Diferencias ───────────────────────────────────────────────────────

    public function test_differences_are_listed_with_totals_by_reason(): void
    {
        $this->cobrar();
        app(CollectFeeInCash::class)->handle(
            'Con diferencia', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1, 'amount' => '100', 'reasonCode' => 'rounding']],
            'difiere', $this->admin->id, null, null, null, true,
        );
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();
        $this->identificar($cobro, '110');
        app(AdjustFeeCharge::class)->handle($cobro, [$renglon->id => ['amount' => '110', 'previous' => '120.00']], new Reason(FeeReasonCode::Rounding, null), null, $this->admin->id);
        app(CollectFeeInCash::class)->handle(
            'De más', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1, 'amount' => '150', 'reasonCode' => 'exception', 'reason' => 'Autorizó la dirección']],
            'de-mas', $this->admin->id, null, null, null, true,
        );

        $seccion = $this->seccion('diferencias');

        $this->assertSame(3, $seccion['count']);
        $this->assertSame('0.00', $seccion['total']);
        $this->assertStringContainsString('calculado $ 120,00', $seccion['rows'][0]['detail']);

        $porMotivo = collect($this->movimientos()->byReason($this->mes))->keyBy('reason');

        $this->assertSame('-30.00', $porMotivo['Redondeo']['total']);
        $this->assertSame(2, $porMotivo['Redondeo']['count']);
        $this->assertSame('30.00', $porMotivo['Excepción autorizada']['total']);
    }

    /** Cada ajuste aporta a su motivo: el neto no se atribuye entero al último. */
    public function test_successive_adjustments_count_for_each_reason(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();
        $ajustar = fn (string $de, string $a, Reason $motivo) => app(AdjustFeeCharge::class)->handle(
            $cobro, [$renglon->id => ['amount' => $a, 'previous' => $de]], $motivo, null, $this->admin->id,
        );
        $ajustar('120.00', '100', new Reason(FeeReasonCode::Rounding, null));
        $ajustar('100.00', '90', new Reason(FeeReasonCode::Exception, 'Autorizó la dirección'));
        $this->identificar($cobro, '90');

        $porMotivo = collect($this->movimientos()->byReason($this->mes))->keyBy('reason');
        $seccion = $this->seccion('diferencias');

        $this->assertSame('-20.00', $porMotivo['Redondeo']['total']);
        $this->assertSame('-10.00', $porMotivo['Excepción autorizada']['total']);
        $this->assertSame('-30.00', $seccion['total']);
        $this->assertSame(1, $seccion['count']);
        $this->assertStringContainsString('Redondeo: −$ 20,00', $seccion['rows'][0]['detail']);
        $this->assertStringContainsString('Autorizó la dirección', $seccion['rows'][0]['detail']);
    }

    /** También el cambio al cobrar y el ajuste posterior; y lo que suman da la sección. */
    public function test_the_totals_by_reason_add_up_to_the_section(): void
    {
        $cobro = app(CreateFeeCharge::class)->handle(
            'Con cambio', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1, 'amount' => '110', 'reasonCode' => 'rounding']],
            'con-cambio', $this->admin->id, true,
        );
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();
        app(AdjustFeeCharge::class)->handle(
            $cobro, [$renglon->id => ['amount' => '115', 'previous' => '110.00']], new Reason(FeeReasonCode::Other, 'Se recalculó'), null, $this->admin->id,
        );
        $this->identificar($cobro, '115');

        $porMotivo = $this->movimientos()->byReason($this->mes);
        $suma = array_reduce($porMotivo, fn (string $t, array $m): string => Decimal::add($t, $m['total']), '0.00');

        $this->assertCount(2, $porMotivo);
        $this->assertSame('-5.00', $suma);
        $this->assertSame($suma, $this->seccion('diferencias')['total']);
    }

    /** Un renglón que volvió a lo calculado sigue a la vista: hubo cambios. */
    public function test_a_line_back_to_the_calculated_amount_stays_listed(): void
    {
        $cobro = $this->pendiente();
        $renglon = FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->firstOrFail();
        $ajustar = fn (string $de, string $a) => app(AdjustFeeCharge::class)->handle(
            $cobro, [$renglon->id => ['amount' => $a, 'previous' => $de]], new Reason(FeeReasonCode::Rounding, null), null, $this->admin->id,
        );
        $ajustar('120.00', '100');
        $ajustar('100.00', '120');
        $this->identificar($cobro, '120');

        $seccion = $this->seccion('diferencias');
        $porMotivo = $this->movimientos()->byReason($this->mes);

        $this->assertSame(1, $seccion['count']);
        $this->assertSame('0.00', $seccion['total']);
        $this->assertSame(2, $porMotivo[0]['count']);
        $this->assertSame('0.00', $porMotivo[0]['total']);
    }

    // ── Pantalla y Excel ──────────────────────────────────────────────────

    public function test_a_month_with_year_zero_returns_to_the_screen(): void
    {
        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '0000-01']))
            ->assertRedirect(route('aranceles.movimientos.index'));
        $this->actingAs($this->admin)->get(route('aranceles.movimientos.exportar', ['mes' => '0000-01']))
            ->assertRedirect(route('aranceles.movimientos.index'));
    }

    public function test_the_screen_shows_the_month_and_its_filters(): void
    {
        $this->cobrar();
        $this->cobrar(1, 25);
        $this->identificar($this->pendiente(), '120');

        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '2026-09']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/movimientos', false)
                ->where('month', '2026-09')
                ->has('byType', 2)
                ->where('sections.0.total', '290.00')
                ->where('sections.0.filtered', true));

        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '2026-09', 'medio' => 'bank']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.medio', 'bank')->where('sections.0.total', '120.00')->has('byType', 1));

        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '2026-09', 'tipo' => $this->tipo(25)]))
            ->assertInertia(fn (Assert $page) => $page->where('sections.0.total', '50.00'));
    }

    public function test_a_future_month_falls_back_to_the_current_one(): void
    {
        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '2099-01']))
            ->assertInertia(fn (Assert $page) => $page->where('month', '2026-09'));
    }

    public function test_a_malformed_filter_returns_to_the_screen(): void
    {
        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => 'no-es-un-mes']))
            ->assertRedirect(route('aranceles.movimientos.index'));
    }

    public function test_the_export_carries_the_label_totals_and_keeps_text_as_text(): void
    {
        app(CollectFeeInCash::class)->handle(
            '=HYPERLINK("x")', null, [['feeTypeId' => $this->tipo(), 'quantity' => 1]], 'formula', $this->admin->id, null, null, null, true,
        );

        $contenido = $this->actingAs($this->admin)
            ->get(route('aranceles.movimientos.exportar', ['mes' => '2026-09']))
            ->assertOk()
            ->streamedContent();
        $archivo = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        file_put_contents($archivo, $contenido);
        $hoja = IOFactory::load($archivo)->getActiveSheet();
        $filas = $hoja->toArray(null, false, false);
        @unlink($archivo);

        $this->assertStringContainsString('no es una rendición formal', (string) $filas[1][0]);
        $this->assertContains('Recaudación por tipo', array_column($filas, 0));
        $textos = array_merge(...$filas);
        $this->assertContains('=HYPERLINK("x")', $textos);
        $this->assertContains('Total de la sección', $textos);
        $this->assertNotContains('Total general', $textos);
    }

    public function test_it_needs_permission_to_see_aranceles(): void
    {
        $sinPermiso = User::factory()->create();

        $this->actingAs($sinPermiso)->get(route('aranceles.movimientos.index'))->assertForbidden();
        $this->actingAs($sinPermiso)->get(route('aranceles.movimientos.exportar'))->assertForbidden();
    }

    /** Se llega desde el mes del calendario de la caja. */
    public function test_the_calendar_links_to_the_month(): void
    {
        $panel = app(FeeCashBoxModule::class)->monthPanel($this->mes, null, $this->admin);

        $this->assertNotNull($panel);
        $this->assertSame(route('aranceles.movimientos.index', ['mes' => '2026-09'], absolute: false), $panel->links[0]->href);
        $this->assertNull(app(FeeCashBoxModule::class)->monthPanel($this->mes, null, User::factory()->create()));
    }
}
