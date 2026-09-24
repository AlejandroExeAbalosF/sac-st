<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Excel\CashSheetWorkbook;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Database\Seeders\CashBoxSeeder;
use Database\Seeders\DocumentSeriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rehacer la planilla cuando cambió el dibujo, no los números.
 *
 * El Excel del cierre es evidencia y por eso no se regenera en cada
 * descarga. La contracara era que un arreglo del generador nunca llegaba a
 * los cierres ya exportados: la única salida era reabrir un período
 * contable que estaba perfecto.
 */
class RehacerPlanillaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([DocumentSeriesSeeder::class, CashBoxSeeder::class]);
    }

    /** La planilla nueva reemplaza a la vieja, que queda archivada. */
    public function test_rehacer_deja_una_version_nueva_y_conserva_la_anterior(): void
    {
        $cierre = $this->cierreConPlanilla();
        $primera = (int) $cierre->sheet_attachment_id;

        $this->actingAs($this->operador('administrador'))
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'Se corrigió el saldo inicial del formulario.',
            ])
            ->assertRedirect();

        $this->assertToast('La planilla se rehízo.', 'La anterior queda archivada.');

        $segunda = (int) $cierre->refresh()->sheet_attachment_id;

        $this->assertNotSame($primera, $segunda);

        // La anterior sigue ahí: pudo imprimirse y firmarse.
        $this->assertNotNull(Attachment::query()->find($primera));
        $this->assertSame(2, Attachment::query()->count());
    }

    /** Y queda escrito quién la rehizo y por qué. */
    public function test_rehacer_deja_rastro_en_la_auditoria(): void
    {
        $cierre = $this->cierreConPlanilla();
        $usuario = $this->operador('administrador');

        $this->actingAs($usuario)
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'El reverso no traía las denominaciones en cero.',
            ])
            ->assertSessionHasNoErrors();

        $evento = DB::table('audit_events')->where('action', 'planilla.regenerada')->first();

        $this->assertNotNull($evento);
        $this->assertSame($usuario->id, (int) $evento->user_id);
        $this->assertStringContainsString('denominaciones en cero', (string) $evento->metadata);
    }

    /** Un motivo de dos palabras no explica nada dentro de un año. */
    public function test_el_motivo_es_obligatorio(): void
    {
        $cierre = $this->cierreConPlanilla();
        $original = (int) $cierre->sheet_attachment_id;

        $this->actingAs($this->operador('administrador'))
            ->from(route('caja.cierres.index'))
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'porque si',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame($original, (int) $cierre->refresh()->sheet_attachment_id);
        $this->assertSame(1, Attachment::query()->count());
    }

    /**
     * Rehacer un documento oficial no es cosa de cualquiera.
     *
     * El contador cierra y reabre períodos; rehacer la planilla queda un
     * escalón más arriba porque reemplaza el papel, no los números.
     */
    public function test_el_contador_no_puede_rehacerla(): void
    {
        $cierre = $this->cierreConPlanilla();

        $this->actingAs($this->operador('contador'))
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'Se corrigió el saldo inicial del formulario.',
            ])
            ->assertForbidden();

        $this->assertSame(1, Attachment::query()->count());
    }

    /** Sin planilla emitida no hay nada que rehacer: se pide la primera. */
    public function test_un_cierre_sin_planilla_no_se_rehace(): void
    {
        $cierre = $this->cierre();

        $this->actingAs($this->operador('administrador'))
            ->from(route('caja.cierres.index'))
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'Se corrigió el saldo inicial del formulario.',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(0, Attachment::query()->count());
    }

    /** La planilla dice con qué versión del dibujo se hizo. */
    public function test_la_planilla_queda_marcada_con_la_version_del_dibujo(): void
    {
        $cierre = $this->cierreConPlanilla();

        $adjunto = Attachment::query()->findOrFail($cierre->sheet_attachment_id);

        $this->assertSame(CashSheetWorkbook::VERSION, $adjunto->template_version);
    }

    /** Y la pantalla recibe la versión vigente para poder compararla. */
    public function test_la_pantalla_informa_la_version_vigente(): void
    {
        $this->cierreConPlanilla();

        $this->actingAs($this->operador('administrador'))
            ->get(route('caja.cierres.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sheetVersion', CashSheetWorkbook::VERSION)
                ->where('can.regenerate', true)
                ->where('closings.0.sheetTemplateVersion', CashSheetWorkbook::VERSION));
    }

    /**
     * La pantalla puede llegar a las versiones anteriores.
     *
     * Guardar la planilla vieja sin forma de abrirla no serviría de nada:
     * puede estar impresa y firmada en una carpeta, y el día que alguien
     * pregunte por qué el papel no coincide con lo que baja hoy, la
     * respuesta tiene que estar a mano.
     */
    public function test_la_pantalla_lista_las_planillas_emitidas(): void
    {
        $cierre = $this->cierreConPlanilla();
        $primera = (int) $cierre->sheet_attachment_id;

        $this->actingAs($this->operador('administrador'))
            ->post(route('caja.cierres.sheet.regenerate', ['closing' => $cierre->id]), [
                'reason' => 'Se corrigió el saldo inicial del formulario.',
            ])
            ->assertSessionHasNoErrors();

        $segunda = (int) $cierre->refresh()->sheet_attachment_id;

        $this->actingAs($this->operador('administrador'))
            ->get(route('caja.cierres.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // De la última a la primera: la vigente arriba.
                ->has('closings.0.sheetHistory', 2)
                ->where('closings.0.sheetHistory.0.id', $segunda)
                ->where('closings.0.sheetHistory.0.current', true)
                ->where('closings.0.sheetHistory.1.id', $primera)
                ->where('closings.0.sheetHistory.1.current', false));
    }

    private function cierre(): PeriodClosing
    {
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $caja,
            balances: [LedgerAccount::CashOnHand->value => '100000.00'],
            denominations: $this->billetesPara('100000.00'),
            date: CarbonImmutable::parse('2026-06-01'),
        );

        $this->arqueoListoParaCerrar($caja, '2026-06-02');

        return app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse('2026-06-02'),
        );
    }

    private function cierreConPlanilla(): PeriodClosing
    {
        $cierre = $this->cierre();

        app(ExportCashSheet::class)->handle($cierre);

        return $cierre->refresh();
    }
}
