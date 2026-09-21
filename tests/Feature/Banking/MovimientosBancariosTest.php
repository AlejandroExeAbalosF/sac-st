<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lo que se puede y lo que no se puede hacer con un movimiento ya cargado.
 *
 * La regla de fondo: lo que informó el banco no se edita nunca; lo que
 * decidió una persona sobre ese movimiento, sí, y queda auditado.
 */
class MovimientosBancariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_el_importe_de_un_movimiento_no_se_puede_editar(): void
    {
        $this->importar();
        $movimiento = BankTransaction::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('bank_transactions')
            ->where('id', $movimiento->id)
            ->update(['amount' => '1.00']);
    }

    public function test_la_fecha_de_un_movimiento_no_se_puede_editar(): void
    {
        $this->importar();
        $movimiento = BankTransaction::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('bank_transactions')
            ->where('id', $movimiento->id)
            ->update(['transaction_date' => '2020-01-01']);
    }

    public function test_un_movimiento_no_se_puede_borrar_a_mano(): void
    {
        $this->importar();

        $this->expectException(QueryException::class);

        DB::table('bank_transactions')->delete();
    }

    /**
     * El estado de conciliación sí cambia: §9.4 del DER lo define como
     * marcador administrativo, no como hecho monetario.
     */
    public function test_el_estado_de_conciliacion_si_cambia(): void
    {
        $this->importar();
        $movimiento = BankTransaction::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $movimiento), [
                'reason' => 'Comisión bancaria del causal 3914.',
            ])
            ->assertSessionHasNoErrors();

        $movimiento->refresh();

        $this->assertSame(ReconciliationStatus::Ignored, $movimiento->reconciliation_status);
        $this->assertSame('Comisión bancaria del causal 3914.', $movimiento->ignored_reason);
        $this->assertNotNull($movimiento->ignored_by);
        $this->assertNotNull($movimiento->ignored_at);
    }

    /** Sin motivo no hay decisión, y la base lo impone además del formulario. */
    public function test_dejar_fuera_del_circuito_exige_motivo(): void
    {
        $this->importar();
        $movimiento = BankTransaction::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $movimiento), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->expectException(QueryException::class);

        DB::table('bank_transactions')
            ->where('id', $movimiento->id)
            ->update(['reconciliation_status' => 'ignored']);
    }

    public function test_quien_carga_todos_los_dias_no_deja_movimientos_fuera_del_circuito(): void
    {
        $this->importar();
        $movimiento = BankTransaction::query()->firstOrFail();

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('banco.movimientos.ignore', $movimiento), [
                'reason' => 'Comisión bancaria.',
            ])
            ->assertForbidden();
    }

    public function test_quien_solo_consulta_no_importa_extractos(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador('consulta'))
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertForbidden();
    }

    /**
     * Revertir borra lo que nació con esa importación.
     *
     * Sin esta salida, un archivo mal interpretado dejaría movimientos
     * falsos para siempre y su huella bloquearía la importación del
     * movimiento correcto.
     */
    public function test_revertir_una_importacion_borra_sus_movimientos(): void
    {
        $this->importar();

        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->delete(route('banco.extractos.destroy', $import))
            ->assertRedirect();

        $this->assertSame(0, BankTransaction::query()->count());
        $this->assertSame(0, BankStatementRow::query()->count());
        $this->assertSame(0, BankStatementImport::query()->count());
    }

    /**
     * Un movimiento que también vino en otro archivo sobrevive.
     *
     * No le pertenece a la importación que se revierte: le cambia el
     * padre, porque el otro archivo lo sigue informando.
     */
    public function test_revertir_conserva_los_movimientos_que_vinieron_en_otro_extracto(): void
    {
        $cuenta = $this->cuenta();
        $this->importar($cuenta, 'macro-online.csv');
        $this->importar($cuenta, 'macro-excel.xls');

        $primera = BankStatementImport::query()->oldest('id')->firstOrFail();
        $segunda = BankStatementImport::query()->latest('id')->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->delete(route('banco.extractos.destroy', $primera))
            ->assertRedirect();

        // Los catorce movimientos siguen: el segundo archivo los informa.
        $this->assertSame(14, BankTransaction::query()->count());
        $this->assertSame(
            14,
            BankTransaction::query()->where('first_seen_import_id', $segunda->id)->count(),
        );
    }

    /** El trabajo de una persona no se descarta en silencio. */
    public function test_no_se_revierte_una_importacion_con_movimientos_ya_tratados(): void
    {
        $this->importar();

        $movimiento = BankTransaction::query()->firstOrFail();
        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $movimiento), [
                'reason' => 'Comisión bancaria del causal 3914.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->delete(route('banco.extractos.destroy', $import));

        $this->assertSame(14, BankTransaction::query()->count());
        $this->assertSame(1, BankStatementImport::query()->count());
    }

    public function test_quien_carga_todos_los_dias_no_revierte_importaciones(): void
    {
        $this->importar();
        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador('administrativo'))
            ->delete(route('banco.extractos.destroy', $import))
            ->assertForbidden();
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);
    }

    private function importar(?BankAccount $cuenta = null, string $fixture = 'macro-online.csv'): void
    {
        $cuenta ??= $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/'.$fixture),
                    $fixture,
                    null,
                    null,
                    true,
                ),
            ])
            ->assertSessionHasNoErrors();
    }
}
