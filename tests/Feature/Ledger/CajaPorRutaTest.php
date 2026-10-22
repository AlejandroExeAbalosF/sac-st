<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\User;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Http\Controllers\CashController;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La caja de las pantallas de caja sale de la ruta (Etapa 6a): sin caja por
 * defecto, y lo que llega por la dirección tiene que ser de esa caja.
 */
class CajaPorRutaTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    /** Un arqueo de Multas pedido por las direcciones de Haberes no existe. */
    public function test_a_cash_count_of_another_box_is_not_found(): void
    {
        $arqueo = $this->arqueoDeMultas();
        $admin = $this->operador('administrador');

        $this->actingAs($admin)->post("/caja/arqueos/{$arqueo->id}/revisar")->assertNotFound();
        $this->actingAs($admin)->post("/caja/arqueos/{$arqueo->id}/imputar", ['authorization' => 'Autorizado por el contador'])
            ->assertNotFound();

        expect($arqueo->refresh()->reviewed_at)->not->toBeNull();
    }

    /** Un cierre de Multas pedido por las direcciones de Haberes no existe. */
    public function test_a_closing_of_another_box_is_not_found(): void
    {
        $arqueo = $this->arqueoDeMultas();
        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $arqueo->cash_box_id,
            date: CarbonImmutable::parse('2026-06-01'),
            actorId: $this->quienAbre(),
        );
        $admin = $this->operador('administrador');

        $this->actingAs($admin)->post("/caja/cierres/{$cierre->id}/reabrir", ['reason' => 'Corregir el arqueo del día'])
            ->assertNotFound();
        $this->actingAs($admin)->get("/caja/cierres/{$cierre->id}/planilla")->assertNotFound();
        $this->actingAs($admin)->post("/caja/cierres/{$cierre->id}/planilla/regenerar", ['reason' => 'Cambió el dibujo de la planilla'])
            ->assertNotFound();

        expect(PeriodClosing::query()->findOrFail($cierre->id)->sheet_attachment_id)->toBeNull();
    }

    /** Una caja sin módulo, o una pantalla sin caja, no caen en Haberes. */
    public function test_without_a_box_with_a_module_the_screen_does_not_exist(): void
    {
        Route::middleware(['web', 'auth', 'caja:aranceles'])->get('/_prueba/caja-aranceles', [CashController::class, 'index']);
        Route::middleware(['web', 'auth'])->get('/_prueba/caja-sin-caja', [CashController::class, 'index']);

        $admin = $this->operador('administrador');

        $this->actingAs($admin)->get('/_prueba/caja-aranceles')->assertNotFound();
        $this->actingAs($admin)->get('/_prueba/caja-sin-caja')->assertNotFound();
        $this->actingAs($admin)->get('/caja/dia')->assertOk();
    }

    /** La caja del cierre es la de la ruta, aunque el formulario mande otra. */
    public function test_the_closing_does_not_trust_the_box_sent_by_the_browser(): void
    {
        $this->abrirLibrosSinSaldo();
        $haberes = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        $otra = (int) CashBox::query()->where('code', '!=', CashBox::HABERES)->value('id');
        $quien = User::query()->findOrFail($this->quienAbre());
        $this->arqueoListoParaCerrar($haberes, '2026-06-01', $quien);

        $this->actingAs($this->operador('administrador'))->post('/caja/cierres', [
            'cashBoxId' => $otra,
            'date' => '2026-06-01',
            'periodType' => 'daily',
            'currency' => 'ARS',
        ])->assertSessionHasNoErrors();

        expect(PeriodClosing::query()->sole()->cash_box_id)->toBe($haberes);
    }

    private function arqueoDeMultas(): CashCount
    {
        $cuenta = $this->cuenta();
        $this->cortar($cuenta, '2026-05-31');
        $this->abrirMultas($cuenta, '2026-06-01');

        return $this->arqueoListoParaCerrar(
            $this->cajaDe(CashBox::MULTAS),
            '2026-06-01',
            User::query()->findOrFail($this->quienAbre()),
        );
    }
}
