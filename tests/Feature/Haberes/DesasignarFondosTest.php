<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Liberar plata imputada a una cuota: vuelve al pozo de no identificados.
 *
 * **No es una devolución** —el DER llama asi (§2.6) a devolverle plata al
 * empleador, que sale del organismo—. Aca el dinero no se mueve de la caja
 * ni de la cuenta: solo deja de tener dueño asignado.
 *
 * Es el espejo de la asignación, y hacía falta por dos caminos que
 * terminan en el mismo lugar: se corrigió el importe de la cuota y quedó
 * plata de más, o la recepción se imputó a la cuota equivocada.
 *
 * **No edita nada: agrega.** La imputación original queda intacta y se
 * escribe una fila `reversal` que la apunta y la resta. Eso es lo que
 * permite que la plata se mueva sin reescribir lo que ya pasó.
 */
class DesasignarFondosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Bajar el importe deja la cuota sobre-asignada, y se ve. */
    public function test_bajar_el_importe_deja_el_excedente_a_la_vista(): void
    {
        $cuota = $this->cuotaFinanciada();
        $menos = Decimal::sub($cuota->expected_amount, '250.00');

        $this->corregirImporte($cuota, $menos)->assertSessionHasNoErrors();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.overAllocatedAmount', '250.00')
                ->where('haber.installments.0.remainingAmount', '0.00')
                ->has('haber.installments.0.allocations', 1),
            );
    }

    /** Y el excedente vuelve al pozo de no identificados. */
    public function test_devolver_el_excedente_lo_saca_de_la_cuota(): void
    {
        $cuota = $this->cuotaFinanciada();
        $antes = $cuota->expected_amount;

        $this->corregirImporte($cuota, Decimal::sub($antes, '250.00'));
        $this->devolver($cuota, '250.00')->assertSessionHasNoErrors();

        $financiacion = app(InstallmentFunding::class);

        $this->assertSame('0.00', $financiacion->overAllocated($cuota->refresh()));
        $this->assertSame(
            Decimal::sub($antes, '250.00'),
            $financiacion->allocated($cuota),
        );
        $this->assertTrue($financiacion->isFullyFunded($cuota));
    }

    /** El asiento es el inverso del de la asignación. */
    public function test_la_devolucion_postea_el_asiento_inverso(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->corregirImporte($cuota, Decimal::sub($cuota->expected_amount, '250.00'));
        $this->devolver($cuota, '250.00');

        $reversion = FundingAllocation::query()
            ->where('allocation_kind', AllocationKind::Reversal)
            ->firstOrFail();

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $reversion->allocation_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['BENEFICIARY_FUNDS', 'UNASSIGNED_FUNDS'], $cuentas);
    }

    /**
     * La imputación original no se toca.
     *
     * Es el sentido de que el libro sea append-only: lo que ocurrió queda,
     * y la corrección es un renglón nuevo que lo resta.
     */
    public function test_la_imputacion_original_queda_intacta(): void
    {
        $cuota = $this->cuotaFinanciada();
        $original = FundingAllocation::query()->live()->firstOrFail();
        $importeOriginal = $original->amount;

        $this->corregirImporte($cuota, Decimal::sub($cuota->expected_amount, '250.00'));
        $this->devolver($cuota, '250.00');

        $this->assertSame($importeOriginal, $original->refresh()->amount);

        $reversion = FundingAllocation::query()
            ->where('allocation_kind', AllocationKind::Reversal)
            ->firstOrFail();

        $this->assertSame($original->id, $reversion->reversal_of_id);
        $this->assertSame('250.00', $reversion->amount);
    }

    /**
     * Se puede devolver todo, que es lo que hace falta cuando la
     * recepción se imputó a la cuota equivocada.
     */
    public function test_se_puede_devolver_la_imputacion_entera(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->devolver($cuota, $cuota->expected_amount)->assertSessionHasNoErrors();

        $financiacion = app(InstallmentFunding::class);

        $this->assertSame('0.00', $financiacion->allocated($cuota->refresh()));
        $this->assertFalse($financiacion->isFullyFunded($cuota));
    }

    /** No se devuelve más de lo que esa imputación tiene en pie. */
    public function test_no_se_devuelve_mas_de_lo_imputado(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->devolver($cuota, Decimal::add($cuota->expected_amount, '1.00'))
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, FundingAllocation::query()
            ->where('allocation_kind', AllocationKind::Reversal)
            ->count());
    }

    /** Ni dos veces la misma: la segunda ya no encuentra saldo. */
    public function test_no_se_devuelve_dos_veces_lo_mismo(): void
    {
        $cuota = $this->cuotaFinanciada();
        $total = $cuota->expected_amount;

        $this->devolver($cuota, $total)->assertSessionHasNoErrors();
        $this->devolver($cuota, $total)->assertSessionHasErrors('amount');
    }

    /** El motivo es obligatorio: sin él no hay quién explique el movimiento. */
    public function test_la_devolucion_exige_un_motivo(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->devolver($cuota, '250.00', reason: '')
            ->assertSessionHasErrors('reason');
    }

    /** Y queda en el rastro, con el motivo adentro. */
    public function test_la_devolucion_queda_auditada(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->devolver($cuota, '250.00', reason: 'Se imputó a la cuota equivocada.');

        $evento = DB::table('audit_events')
            ->where('action', 'asignacion.desasignada')
            ->first();

        $this->assertNotNull($evento);
        $this->assertStringContainsString(
            'Se imputó a la cuota equivocada.',
            (string) $evento->metadata,
        );
    }

    /** El doble clic no devuelve dos veces. */
    public function test_el_segundo_envio_no_duplica_la_devolucion(): void
    {
        $cuota = $this->cuotaFinanciada();
        $clave = 'devolucion-unica';

        $this->devolver($cuota, '250.00', clave: $clave)->assertSessionHasNoErrors();
        $this->devolver($cuota, '250.00', clave: $clave)->assertSessionHasNoErrors();

        $this->assertSame(1, FundingAllocation::query()
            ->where('allocation_kind', AllocationKind::Reversal)
            ->count());
    }

    /** Quien solo registra recepciones no revierte. */
    public function test_quien_no_puede_revertir_no_devuelve(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('haberes.installments.unallocate', $cuota), [
                'allocationId' => FundingAllocation::query()->live()->firstOrFail()->id,
                'amount' => '250.00',
                'reason' => 'Un motivo cualquiera.',
                'idempotencyKey' => 'devolucion-sin-permiso',
            ])
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaFinanciada(): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->orderBy('installment_number')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-para-desasignar',
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function corregirImporte(BeneficiaryInstallment $cuota, string $importe): TestResponse
    {
        return $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]), [
                'amount' => $importe,
                'managementLabelId' => $cuota->management_label_id,
                'dueDate' => $cuota->due_date?->toDateString(),
                'concept' => $cuota->description,
                'expectedMedium' => $cuota->expected_medium?->value,
                'notes' => $cuota->notes,
                'version' => $cuota->refresh()->updated_at->toISOString(),
            ]);
    }

    private function devolver(
        BeneficiaryInstallment $cuota,
        string $importe,
        string $reason = 'El importe de la cuota se corrigió.',
        ?string $clave = null,
    ): TestResponse {
        return $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.unallocate', $cuota), [
                'allocationId' => FundingAllocation::query()->live()->firstOrFail()->id,
                'amount' => $importe,
                'reason' => $reason,
                'idempotencyKey' => $clave ?? 'devolucion-'.uniqid(),
            ]);
    }

    private function verHaber(BeneficiaryInstallment $cuota): TestResponse
    {
        $haber = $cuota->haber;

        return $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk();
    }
}
