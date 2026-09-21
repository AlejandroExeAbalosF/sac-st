<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\AuditEvent;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La anulación reemplaza al borrado.
 *
 * No hay eliminación física en ningún rol: el número de expediente es
 * único, y borrarlo perdería el registro de que estuvo cargado y dejaría a
 * los eventos de auditoría apuntando a una fila que ya no existe.
 */
class AnularExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_cancelling_reaches_the_haberes_and_their_installments(): void
    {
        $expediente = $this->expediente('120326/2019');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado con el número equivocado, corresponde al 120327/2019.',
            ])
            ->assertRedirect(route('expedientes.index'));

        $this->assertSame('cancelled', $expediente->refresh()->status->value);

        $this->assertSame(
            0,
            $expediente->haberes()->whereNot('workflow_status', 'cancelled')->count(),
            'Un expediente anulado no puede dejar haberes activos abajo.',
        );

        $this->assertSame(
            0,
            BeneficiaryInstallment::query()
                ->whereIn('haber_id', $expediente->haberes()->pluck('id'))
                ->whereNot('workflow_status', 'cancelled')
                ->count(),
        );
    }

    /**
     * Anular un haber bloqueado tiene que limpiar su motivo de bloqueo: hay
     * un CHECK que exige que exista si y solo si el estado es `blocked`.
     */
    public function test_cancelling_a_blocked_haber_does_not_violate_the_check(): void
    {
        // Zerpa: haber bloqueado por CVU.
        $expediente = $this->expediente('101206/2026');

        $this->assertNotNull($expediente->haberes()->value('block_reason'));

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Duplicado del expediente 101205/2026, se anula este.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($expediente->haberes()->value('block_reason'));
    }

    public function test_the_reason_is_required_and_has_to_explain(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), ['reason' => 'error'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $expediente->refresh()->status->value);
    }

    public function test_the_reason_is_kept_in_the_audit_trail(): void
    {
        $expediente = $this->expediente('77420/2024');
        $motivo = 'Se cargó dos veces la misma carátula; queda vigente el 77421/2024.';

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), ['reason' => $motivo]);

        $evento = AuditEvent::query()
            ->forSubject('Expediente', $expediente->id)
            ->where('action', 'expediente.anulado')
            ->firstOrFail();

        $this->assertSame($motivo, $evento->metadata['motivo']);
        $this->assertSame('active', $evento->old_values['status']);
        $this->assertSame('cancelled', $evento->new_values['status']);
    }

    public function test_it_can_not_be_cancelled_twice(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado por error durante la capacitación.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado por error durante la capacitación.',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_whoever_loads_every_day_can_not_cancel(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado por error durante la capacitación.',
            ])
            ->assertForbidden();

        $this->assertSame('active', $expediente->refresh()->status->value);
    }

    public function test_the_list_hides_cancelled_unless_they_are_asked_for(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado por error durante la capacitación.',
            ]);

        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expedientes', 5)
                ->where('anulados', 1));

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['anulados' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('expedientes', 6));
    }

    /** No existe borrado físico por ninguna vía. */
    public function test_there_is_no_delete_route(): void
    {
        $rutas = collect(app('router')->getRoutes())
            ->filter(fn ($ruta): bool => in_array('DELETE', $ruta->methods(), true))
            ->map(fn ($ruta): string => $ruta->uri());

        $this->assertEmpty(
            $rutas->filter(fn (string $uri): bool => str_contains($uri, 'haberes')),
            'Apareció una ruta de borrado: la baja se hace por estado.',
        );
    }

    /**
     * Reactivar devuelve cada cosa a donde estaba, no a «activo».
     *
     * Es el punto delicado: el expediente de Bulacio tiene tres haberes
     * cerrados y dos activos, con cuotas pagadas y pendientes mezcladas.
     * Restaurar todo como activo dejaría cinco haberes abiertos y cuotas
     * pagadas volviendo a figurar por cobrar.
     */
    public function test_reactivating_restores_the_previous_state_of_everything(): void
    {
        $expediente = $this->expediente('120326/2019');
        $antes = $this->fotoDeEstados($expediente);

        $this->assertGreaterThan(1, collect($antes['haberes'])->unique()->count());

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $expediente), [
                'reason' => 'La anulación fue un error: el expediente sigue vigente.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('active', $expediente->refresh()->status->value);
        $this->assertSame($antes, $this->fotoDeEstados($expediente));
    }

    /** Un haber bloqueado recupera su motivo, que la anulación había limpiado. */
    public function test_reactivating_restores_the_block_reason(): void
    {
        $expediente = $this->expediente('101206/2026');
        $motivoOriginal = $expediente->haberes()->value('block_reason');

        $this->assertNotNull($motivoOriginal);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->assertNull($expediente->haberes()->value('block_reason'));

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $expediente), [
                'reason' => 'La anulación fue un error: el expediente sigue vigente.',
            ]);

        // `value()` devuelve el enum, no la cadena: el modelo lo castea.
        $this->assertSame(
            HaberWorkflowStatus::Blocked,
            $expediente->haberes()->value('workflow_status'),
        );
        $this->assertSame($motivoOriginal, $expediente->haberes()->value('block_reason'));
    }

    public function test_a_closed_expediente_comes_back_closed(): void
    {
        $expediente = $this->expediente('45905/2018');

        $this->assertSame('closed', $expediente->status->value);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $expediente), [
                'reason' => 'La anulación fue un error: el expediente estaba bien cerrado.',
            ]);

        $this->assertSame('closed', $expediente->refresh()->status->value);
    }

    public function test_reactivating_needs_a_reason_and_leaves_a_trace(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $expediente), ['reason' => 'ups'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('cancelled', $expediente->refresh()->status->value);

        $motivo = 'Se anuló el expediente equivocado; este sigue vigente.';

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $expediente), ['reason' => $motivo]);

        $evento = AuditEvent::query()
            ->forSubject('Expediente', $expediente->id)
            ->where('action', 'expediente.reactivado')
            ->firstOrFail();

        $this->assertSame($motivo, $evento->metadata['motivo']);
        $this->assertTrue($evento->metadata['estados_restaurados']);
    }

    public function test_only_a_cancelled_expediente_can_be_reactivated(): void
    {
        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.reactivate', $this->expediente('77420/2024')), [
                'reason' => 'La anulación fue un error: el expediente sigue vigente.',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_whoever_loads_every_day_can_not_reactivate(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('expedientes.reactivate', $expediente), [
                'reason' => 'La anulación fue un error: el expediente sigue vigente.',
            ])
            ->assertForbidden();
    }

    /**
     * Estado de cada haber y cada cuota, para comparar antes y después.
     *
     * @return array{haberes: array<int, string>, cuotas: array<int, string>}
     */
    private function fotoDeEstados(Expediente $expediente): array
    {
        $haberes = $expediente->haberes()
            ->orderBy('id')
            ->pluck('workflow_status', 'id')
            ->map(fn ($estado): string => is_string($estado) ? $estado : $estado->value)
            ->all();

        $cuotas = BeneficiaryInstallment::query()
            ->whereIn('haber_id', array_keys($haberes))
            ->orderBy('id')
            ->pluck('workflow_status', 'id')
            ->map(fn ($estado): string => is_string($estado) ? $estado : $estado->value)
            ->all();

        return ['haberes' => $haberes, 'cuotas' => $cuotas];
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }
}
