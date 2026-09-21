<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Corrección de la ficha del expediente.
 *
 * Existe por un caso concreto: un total declarado cargado mal deja al
 * expediente avisando para siempre que lo reconocido no coincide, sin
 * forma de resolverlo salvo tocando la base.
 */
class EditarExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El caso que motivó la pantalla. */
    public function test_correcting_the_declared_total_silences_the_mismatch(): void
    {
        $expediente = $this->expediente('125957/2026');

        // Un haber de más deja lo reconocido por encima de lo declarado.
        $expediente->haberes()->create([
            'beneficiary_id' => Person::query()->where('document', '22908114')->value('id'),
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '55555.00',
            'expected_installment_count' => 1,
            'payment_terms' => 'single',
            'workflow_status' => 'active',
        ]);

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'declaredTotalAmount' => '1260055.00',
            ])
            ->assertRedirect(route('expedientes.show', $expediente))
            ->assertSessionHasNoErrors();

        $this->assertSame('1260055.00', $expediente->refresh()->declared_total_amount);
    }

    public function test_it_corrects_the_caratula_the_date_and_the_employer(): void
    {
        $expediente = $this->expediente('77420/2024');
        $otroEmpleador = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'subject' => 'Carátula corregida',
                'receivedDate' => '2024-06-10',
                'employerId' => $otroEmpleador->id,
                'employerRepresentative' => 'Quien Firmó',
            ])
            ->assertSessionHasNoErrors();

        $expediente->refresh();

        $this->assertSame('Carátula corregida', $expediente->subject);
        $this->assertSame('2024-06-10', $expediente->received_date?->toDateString());
        $this->assertSame($otroEmpleador->id, $expediente->employer_id);
        $this->assertSame('Quien Firmó', $expediente->employer_representative);
    }

    /**
     * El número identifica al expediente: no se corrige. Mandarlo no
     * cambia nada, aunque venga en el envío.
     */
    public function test_the_number_can_not_be_changed(): void
    {
        $expediente = $this->expediente('77420/2024');
        $original = $expediente->canonical_number;

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'number' => '0030064-999999/2026-0',
                'canonicalNumber' => '0030064-999999/2026-0',
                'displayNumber' => '999999/2026',
            ])
            ->assertSessionHasNoErrors();

        $expediente->refresh();

        $this->assertSame($original, $expediente->canonical_number);
        $this->assertSame('77420/2024', $expediente->display_number);
    }

    public function test_the_correction_leaves_a_trace_with_what_changed(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'subject' => 'Otra carátula',
            ]);

        $evento = AuditEvent::query()
            ->forSubject('Expediente', $expediente->id)
            ->where('action', 'expediente.corregido')
            ->firstOrFail();

        $this->assertSame(['subject'], array_keys($evento->new_values));
        $this->assertSame('Otra carátula', $evento->new_values['subject']);
    }

    public function test_it_rejects_an_employer_that_is_not_one(): void
    {
        $expediente = $this->expediente('77420/2024');
        $beneficiario = Person::query()->where('document', '28114902')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'employerId' => $beneficiario->id,
            ])
            ->assertSessionHasErrors('employerId');
    }

    public function test_it_rejects_a_future_reception_date(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador())
            ->patch(route('expedientes.update', $expediente), [
                ...$this->ficha($expediente),
                'receivedDate' => now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('receivedDate');
    }

    public function test_the_form_shows_the_current_employer_first(): void
    {
        $expediente = $this->expediente('45905/2018');

        $this->actingAs($this->operador())
            ->get(route('expedientes.edit', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/edit')
                ->where('expediente.displayNumber', '45905/2018')
                // Sin esto, un empleador que no entra en las primeras
                // veinte opciones abriría el buscador sin poder mostrarse.
                ->where('empleadores.0.id', $expediente->employer_id));
    }

    public function test_whoever_only_reads_can_not_edit(): void
    {
        $expediente = $this->expediente('77420/2024');

        $this->actingAs($this->operador('consulta'))
            ->get(route('expedientes.edit', $expediente))
            ->assertForbidden();

        $this->actingAs($this->operador('consulta'))
            ->patch(route('expedientes.update', $expediente), $this->ficha($expediente))
            ->assertForbidden();
    }

    /**
     * El envío completo tal como lo arma la pantalla.
     *
     * @return array<string, mixed>
     */
    private function ficha(Expediente $expediente): array
    {
        return [
            'subject' => $expediente->subject,
            'receivedDate' => $expediente->received_date?->toDateString(),
            'employerId' => $expediente->employer_id,
            'employerRepresentative' => $expediente->employer_representative,
            'declaredTotalAmount' => $expediente->declared_total_amount,
            'externalReference' => $expediente->external_reference,
            'notes' => $expediente->notes,
        ];
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }
}
