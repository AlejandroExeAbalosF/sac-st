<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\HaberManagementLabel;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Operaciones incrementales sobre las cuotas desde el detalle del expediente. */
final class GestionarCuotasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_an_administrative_user_can_add_the_last_missing_installment(): void
    {
        $haber = $this->haber('77420/2024');
        $haber->update([
            'assigned_amount' => '120000.00',
            'expected_installment_count' => 2,
            'payment_terms' => 'installments',
        ]);
        $label = HaberManagementLabel::query()->where('code', 'PAGAR')->firstOrFail();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.store', [$haber->expediente, $haber]), [
                ...$this->installmentData('24000.00'),
                'managementLabelId' => $label->id,
                'expectedMedium' => 'bank',
                'dueDate' => '2026-09-15',
                'notes' => 'Ticket recibido por mesa de entradas.',
            ])
            ->assertRedirect(route('expedientes.show', $haber->expediente))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('beneficiary_installments', [
            'haber_id' => $haber->id,
            'installment_number' => 2,
            'expected_amount' => '24000.00',
            'management_label_id' => $label->id,
            'expected_medium' => 'bank',
            'due_date' => '2026-09-15',
            'notes' => 'Ticket recibido por mesa de entradas.',
        ]);
    }

    public function test_the_last_installment_must_complete_the_recognized_total(): void
    {
        $haber = $this->haber('77420/2024');
        $haber->update([
            'assigned_amount' => '120000.00',
            'expected_installment_count' => 2,
            'payment_terms' => 'installments',
        ]);

        $this->actingAs($this->operador())
            ->post(
                route('haberes.installments.store', [$haber->expediente, $haber]),
                $this->installmentData('23000.00'),
            )
            ->assertSessionHasErrors('amount');

        $this->assertSame(1, $haber->installments()->count());
    }

    public function test_it_can_not_add_more_installments_than_the_plan_expects(): void
    {
        $haber = $this->haber('77420/2024');

        $this->actingAs($this->operador())
            ->post(
                route('haberes.installments.store', [$haber->expediente, $haber]),
                $this->installmentData('1000.00'),
            )
            ->assertSessionHasErrors('form');

        $this->assertSame(1, $haber->installments()->count());
    }

    public function test_an_active_installment_can_be_corrected_with_all_its_metadata(): void
    {
        $haber = $this->haber('125957/2026');
        $installment = $haber->installments()->where('installment_number', 1)->firstOrFail();
        $label = HaberManagementLabel::query()->where('code', 'PAGAR')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData('602250.00'),
                'version' => $installment->updated_at->toISOString(),
                'managementLabelId' => $label->id,
                'concept' => 'Convenio rectificado',
                'expectedMedium' => 'cheque',
                'dueDate' => '2026-10-01',
                'notes' => 'Corrección según providencia.',
            ])
            ->assertRedirect(route('expedientes.show', $haber->expediente))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiary_installments', [
            'id' => $installment->id,
            'expected_amount' => '602250.00',
            'management_label_id' => $label->id,
            'description' => 'Convenio rectificado',
            'expected_medium' => 'cheque',
            'due_date' => '2026-10-01',
            'notes' => 'Corrección según providencia.',
        ]);
    }

    public function test_editing_from_the_haber_detail_returns_to_the_same_screen(): void
    {
        $haber = $this->haber('125957/2026');
        $installment = $haber->installments()->where('installment_number', 1)->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData($installment->importeEsperado()),
                'version' => $installment->updated_at->toISOString(),
                'returnTo' => 'haber',
            ])
            ->assertRedirect(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertSessionHasNoErrors();
    }

    public function test_reducing_a_complete_plan_opens_one_explicit_missing_installment(): void
    {
        $haber = $this->haber('77420/2024');
        $installment = $haber->installments()->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData('90000.00'),
                'version' => $installment->updated_at->toISOString(),
            ])
            ->assertRedirect(route('expedientes.show', $haber->expediente))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('haberes', [
            'id' => $haber->id,
            'expected_installment_count' => 2,
            'payment_terms' => 'installments',
        ]);
        $this->assertSame(1, $haber->installments()->count());
    }

    public function test_an_edit_must_leave_positive_balance_for_installments_not_loaded_yet(): void
    {
        $haber = $this->haber('77420/2024');
        $haber->update([
            'assigned_amount' => '120000.00',
            'expected_installment_count' => 2,
            'payment_terms' => 'installments',
        ]);
        $installment = $haber->installments()->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData('120000.00'),
                'version' => $installment->updated_at->toISOString(),
            ])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseHas('beneficiary_installments', [
            'id' => $installment->id,
            'expected_amount' => '96000.00',
        ]);
    }

    public function test_a_paid_installment_is_read_only(): void
    {
        $haber = $this->haber('120326/2019', withPaidInstallment: true);
        $installment = $haber->installments()->where('workflow_status', 'paid')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData($installment->importeEsperado()),
                'version' => $installment->updated_at->toISOString(),
            ])
            ->assertSessionHasErrors('form');
    }

    public function test_a_stale_screen_can_not_overwrite_a_more_recent_change(): void
    {
        $haber = $this->haber('77420/2024');
        $installment = $haber->installments()->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $installment]), [
                ...$this->installmentData('96000.00'),
                'version' => $installment->updated_at->copy()->subSecond()->toISOString(),
            ])
            ->assertSessionHasErrors('form');
    }

    public function test_an_installment_can_not_be_updated_through_another_haber(): void
    {
        $haber = $this->haber('77420/2024');
        $otherInstallment = $this->haber('125957/2026')->installments()->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $otherInstallment]), [
                ...$this->installmentData($otherInstallment->importeEsperado()),
                'version' => $otherInstallment->updated_at->toISOString(),
            ])
            ->assertNotFound();
    }

    public function test_a_read_only_user_can_see_but_not_manage_installments(): void
    {
        $haber = $this->haber('77420/2024');
        $user = $this->operador('consulta');

        $this->actingAs($user)
            ->followingRedirects()
            ->get(route('expedientes.show', $haber->expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canEdit', false)
            );

        $this->post(
            route('haberes.installments.store', [$haber->expediente, $haber]),
            $this->installmentData('1000.00'),
        )->assertForbidden();
    }

    private function haber(string $expediente, bool $withPaidInstallment = false): Haber
    {
        $query = Expediente::query()
            ->where('display_number', $expediente)
            ->firstOrFail()
            ->haberes();

        if ($withPaidInstallment) {
            $query->whereHas('installments', fn ($installments) => $installments
                ->where('workflow_status', 'paid'));
        }

        return $query->where('workflow_status', 'active')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function installmentData(string $amount): array
    {
        return [
            'amount' => $amount,
            'managementLabelId' => null,
            'concept' => null,
            'dueDate' => null,
            // Obligatorio desde 09/2026: una cuota sin medio no dice por
            // dónde se paga, y el servidor la rechaza.
            'expectedMedium' => 'cash',
            'notes' => null,
        ];
    }
}
