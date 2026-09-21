<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Data\ExpedienteListItemData;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anulación de un haber suelto.
 *
 * Hasta acá la única baja era la del expediente entero, y para un haber
 * cargado de más eso obligaba a anular todo y volver a cargar lo que sí
 * estaba bien.
 */
class AnularHaberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /**
     * Lo que motivó todo esto: un haber de más deja al expediente
     * descuadrado, y anularlo tiene que hacerlo cuadrar.
     */
    public function test_cancelling_a_haber_makes_the_expediente_add_up_again(): void
    {
        $expediente = $this->expediente('125957/2026');
        $deMas = $expediente->haberes()->create([
            'beneficiary_id' => Person::query()->where('document', '22908114')->value('id'),
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '55555.00',
            'expected_installment_count' => 1,
            'payment_terms' => 'single',
            'workflow_status' => 'active',
        ]);

        $this->assertSame('1260055.00', $this->reconocido($expediente));

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$deMas->expediente, $deMas]), [
                'reason' => 'Cargado por error: el acta no incluye a este beneficiario.',
            ])
            ->assertSessionHasNoErrors();

        // Vuelve a coincidir con el total declarado.
        $this->assertSame('1204500.00', $this->reconocido($expediente->refresh()));
        $this->assertSame(
            $expediente->declared_total_amount,
            $this->reconocido($expediente),
        );
    }

    /** El haber anulado se sigue viendo: deja registro de que estuvo. */
    public function test_the_cancelled_haber_is_still_listed(): void
    {
        $haber = $this->haberDe('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Cargado por error durante la capacitación.',
            ]);

        $datos = ExpedienteListItemData::fromModel(
            Expediente::query()->paraListado()->findOrFail($haber->expediente_id),
        );

        $this->assertCount(1, $datos->haberes);
        $this->assertSame('cancelled', $datos->haberes[0]->status->value);
        $this->assertSame('0.00', $datos->recognizedTotalAmount);
    }

    public function test_cancelling_reaches_its_installments(): void
    {
        $haber = $this->haberDe('45905/2018');

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Anulado durante la revisión del cierre mensual.',
            ]);

        $this->assertSame(
            0,
            $haber->installments()->whereNot('workflow_status', 'cancelled')->count(),
        );
    }

    /**
     * Reactivar devuelve todo a donde estaba. El haber de Tinte tiene sus
     * dos cuotas pagadas: restaurarlas como activas las haría figurar por
     * cobrar otra vez.
     */
    public function test_reactivating_restores_the_previous_state(): void
    {
        $haber = $this->haberDe('45905/2018');
        $antes = $haber->installments()->orderBy('id')->pluck('workflow_status', 'id')
            ->map(fn ($estado): string => is_string($estado) ? $estado : $estado->value)
            ->all();

        $this->assertContains('paid', $antes);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Anulado durante la revisión del cierre mensual.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.reactivate', [$haber->expediente, $haber]), [
                'reason' => 'La anulación fue un error: el haber sigue vigente.',
            ])
            ->assertSessionHasNoErrors();

        $despues = $haber->installments()->orderBy('id')->pluck('workflow_status', 'id')
            ->map(fn ($estado): string => is_string($estado) ? $estado : $estado->value)
            ->all();

        $this->assertSame($antes, $despues);
        $this->assertSame(
            HaberWorkflowStatus::Closed,
            $haber->refresh()->workflow_status,
        );
    }

    /** Un haber bloqueado recupera su motivo, que la anulación limpió. */
    public function test_reactivating_restores_the_block_reason(): void
    {
        $haber = $this->haberDe('101206/2026');
        $motivo = $haber->block_reason;

        $this->assertNotNull($motivo);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Anulado durante la revisión del cierre mensual.',
            ]);

        $this->assertNull($haber->refresh()->block_reason);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.reactivate', [$haber->expediente, $haber]), [
                'reason' => 'La anulación fue un error: el haber sigue vigente.',
            ]);

        $haber->refresh();

        $this->assertSame(HaberWorkflowStatus::Blocked, $haber->workflow_status);
        $this->assertSame($motivo, $haber->block_reason);
    }

    public function test_the_reason_is_required_and_kept(): void
    {
        $haber = $this->haberDe('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), ['reason' => 'error'])
            ->assertSessionHasErrors('reason');

        $motivo = 'Cargado por error: el acta no incluye a este beneficiario.';

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), ['reason' => $motivo]);

        $evento = AuditEvent::query()
            ->forSubject('Haber', $haber->id)
            ->where('action', 'haber.anulado')
            ->firstOrFail();

        $this->assertSame($motivo, $evento->metadata['motivo']);
        $this->assertSame('96000.00', $evento->metadata['importe']);
    }

    /**
     * Con el expediente anulado ya está todo abajo anulado: tocar un haber
     * suelto dejaría un haber activo dentro de un expediente que no lo
     * está.
     */
    public function test_a_haber_of_a_cancelled_expediente_can_not_be_reactivated_alone(): void
    {
        $expediente = $this->expediente('77420/2024');
        $haber = $expediente->haberes()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Anulado por error durante la revisión mensual.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.reactivate', [$haber->expediente, $haber]), [
                'reason' => 'La anulación fue un error: el haber sigue vigente.',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_it_can_not_be_cancelled_twice(): void
    {
        $haber = $this->haberDe('77420/2024');

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Cargado por error durante la capacitación.',
            ]);

        $this->actingAs($this->operador('contador'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Cargado por error durante la capacitación.',
            ])
            ->assertSessionHasErrors('reason');
    }

    public function test_whoever_loads_every_day_can_not_cancel_a_haber(): void
    {
        $haber = $this->haberDe('77420/2024');

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'Cargado por error durante la capacitación.',
            ])
            ->assertForbidden();

        $this->assertSame(
            HaberWorkflowStatus::Active,
            $haber->refresh()->workflow_status,
        );
    }

    /** @return numeric-string */
    private function reconocido(Expediente $expediente): string
    {
        return ExpedienteListItemData::fromModel(
            Expediente::query()->paraListado()->findOrFail($expediente->id),
        )->recognizedTotalAmount;
    }

    private function haberDe(string $numero): Haber
    {
        return $this->expediente($numero)->haberes()->orderBy('id')->firstOrFail();
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }
}
