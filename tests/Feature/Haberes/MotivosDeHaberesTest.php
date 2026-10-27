<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\AuditEvent;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El motivo de Haberes se elige de la lista de cada acto y se guarda como
 * código; el detalle es opcional, salvo con «Otro». Donde hay columna, la
 * base lo impone.
 */
class MotivosDeHaberesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_ficha_recibe_la_lista_de_cada_acto(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->get(route('expedientes.show', $expediente))
            ->assertInertia(fn (Assert $page) => $page
                ->where('motivos', HaberesReasonAct::options())
                ->where('motivos.cancellation.0', ['value' => 'data_entry_error', 'label' => 'Error de carga']));
    }

    public function test_anular_guarda_el_codigo_en_la_auditoria_y_el_detalle_es_opcional(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), ['reasonCode' => 'duplicate'])
            ->assertSessionHasNoErrors();

        $evento = AuditEvent::query()
            ->forSubject('Expediente', $expediente->id)
            ->where('action', 'expediente.anulado')
            ->sole();

        $this->assertSame('Cargado dos veces', $evento->metadata['motivo']);
        $this->assertSame('duplicate', $evento->metadata['motivo_codigo']);
    }

    public function test_cada_acto_acepta_solo_su_lista_y_otro_pide_el_detalle(): void
    {
        $expediente = $this->expediente('77420/2024');
        $contador = $this->operador('contador');

        // «Se anuló por error» es de reactivar, no de anular.
        $this->actingAs($contador)
            ->patch(route('expedientes.cancel', $expediente), ['reasonCode' => 'cancelled_by_mistake'])
            ->assertSessionHasErrors('reasonCode');

        $this->patch(route('expedientes.cancel', $expediente), ['reasonCode' => 'other'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $expediente->refresh()->status->value);

        $this->patch(route('expedientes.cancel', $expediente), ['reasonCode' => 'other', 'reason' => 'Lo pidió la Dirección.'])
            ->assertSessionHasNoErrors();

        $this->patch(route('expedientes.reactivate', $expediente), ['reasonCode' => 'cancelled_by_mistake'])
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $expediente->refresh()->status->value);
    }

    public function test_la_base_exige_el_codigo_de_la_ventana_de_edicion(): void
    {
        $cuota = BeneficiaryInstallment::query()->orderBy('id')->firstOrFail();
        $ventana = fn (array $valores) => fn () => DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['edit_unlocked_at' => now(), ...$valores]);

        $this->rechaza($ventana(['edit_unlock_reason' => 'Sin código']));
        $this->rechaza($ventana(['edit_unlock_reason_code' => 'duplicate']));
        $this->rechaza($ventana(['edit_unlock_reason_code' => 'other']));
        $this->rechaza($ventana(['edit_unlock_reason_code' => 'saf_observation', 'edit_unlock_reason' => '  ']));

        $ventana(['edit_unlock_reason_code' => 'saf_observation'])();

        $this->assertSame('saf_observation', DB::table('beneficiary_installments')->where('id', $cuota->id)->value('edit_unlock_reason_code'));
    }

    private function rechaza(callable $consulta): void
    {
        try {
            DB::transaction(fn () => $consulta());
            $this->fail('La base aceptó una ventana de edición sin un motivo válido.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('beneficiary_installments_edit_unlock_check', $exception->getMessage());
        }
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }
}
