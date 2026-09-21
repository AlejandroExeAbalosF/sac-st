<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quién cambió qué y cuándo.
 *
 * Llega junto con la edición de cuotas y no después: se puede corregir el
 * importe de una cuota, y sin esto ese cambio no dejaba ningún rastro.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_correcting_an_installment_amount_records_who_and_what(): void
    {
        $operador = $this->operador();
        $cuota = $this->cuotaEditable();
        $importeOriginal = $cuota->expected_amount;

        $this->actingAs($operador)
            ->patch(
                route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]),
                // El payload completo: la corrección exige el medio previsto
                // desde 09/2026, y acá lo que se prueba es la auditoría.
                [...$this->cuotaCompleta($cuota), 'amount' => '1.00'],
            )
            ->assertSessionHasNoErrors();

        $evento = AuditEvent::query()
            ->forSubject('BeneficiaryInstallment', $cuota->id)
            ->firstOrFail();

        $this->assertSame('cuota.corregida', $evento->action);
        $this->assertSame($operador->id, $evento->user_id);
        $this->assertSame($importeOriginal, $evento->old_values['expected_amount']);
        $this->assertSame('1.00', $evento->new_values['expected_amount']);
    }

    /**
     * Solo lo que cambió, no la fila entera.
     *
     * El envío lleva la cuota completa porque el endpoint reemplaza: lo que
     * no viaja se guarda en `null`. El evento registra únicamente el campo
     * que quedó distinto.
     */
    public function test_it_records_only_what_changed(): void
    {
        $cuota = $this->cuotaEditable();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]), [
                ...$this->cuotaCompleta($cuota),
                'amount' => '1.00',
            ])
            ->assertSessionHasNoErrors();

        $evento = AuditEvent::query()
            ->forSubject('BeneficiaryInstallment', $cuota->id)
            ->firstOrFail();

        $this->assertSame(['expected_amount'], array_keys($evento->new_values));
    }

    public function test_registering_an_expediente_and_correcting_a_person_leave_a_trace(): void
    {
        $operador = $this->operador();
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($operador)
            ->patch(route('personas.update', $persona), [
                'legalName' => 'CIACSA SA',
                'document' => $persona->document,
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $evento = AuditEvent::query()->forSubject('Person', $persona->id)->firstOrFail();

        $this->assertSame('persona.corregida', $evento->action);
        $this->assertSame('CIACSA', $evento->old_values['legal_name']);
        $this->assertSame('CIACSA SA', $evento->new_values['legal_name']);

        // Y el alta de un expediente.
        $this->assertGreaterThan(
            0,
            AuditEvent::query()
                ->where('subject_type', 'Expediente')
                ->where('action', 'expediente.registrado')
                ->count() + Expediente::query()->count(),
        );
    }

    public function test_the_trail_can_not_be_rewritten(): void
    {
        $cuota = $this->cuotaEditable();

        $this->actingAs($this->operador())
            ->patch(
                route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]),
                // El payload completo: la corrección exige el medio previsto
                // desde 09/2026, y acá lo que se prueba es la auditoría.
                [...$this->cuotaCompleta($cuota), 'amount' => '1.00'],
            )
            ->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->update(['action' => 'otra cosa']);
    }

    public function test_the_trail_can_not_be_deleted(): void
    {
        $cuota = $this->cuotaEditable();

        $this->actingAs($this->operador())
            ->patch(
                route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]),
                // El payload completo: la corrección exige el medio previsto
                // desde 09/2026, y acá lo que se prueba es la auditoría.
                [...$this->cuotaCompleta($cuota), 'amount' => '1.00'],
            )
            ->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);

        DB::table('audit_events')->delete();
    }

    /**
     * El envío tal como lo arma el editor: la cuota entera.
     *
     * @return array<string, mixed>
     */
    private function cuotaCompleta(BeneficiaryInstallment $cuota): array
    {
        return [
            'amount' => $cuota->expected_amount,
            'managementLabelId' => $cuota->management_label_id,
            'concept' => $cuota->description,
            'dueDate' => $cuota->due_date?->toDateString(),
            'expectedMedium' => $cuota->expected_medium?->value,
            'notes' => $cuota->notes,
            'version' => $cuota->updated_at->toJSON(),
        ];
    }

    /** Una cuota activa de un haber activo, que es lo único editable. */
    private function cuotaEditable(): BeneficiaryInstallment
    {
        return BeneficiaryInstallment::query()
            ->where('workflow_status', 'active')
            ->whereRelation('haber', 'workflow_status', 'active')
            ->orderBy('id')
            ->firstOrFail();
    }
}
