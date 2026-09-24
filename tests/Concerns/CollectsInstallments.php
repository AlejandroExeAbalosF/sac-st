<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Plata que entra a la caja por el circuito de verdad.
 *
 * Para probar la recaudación del día y el fajo que se arrastra no alcanza
 * con asentar efectivo a mano: los dos números se leen de `fund_receipts` y
 * de `funding_allocations`, que solo existen si el cobro pasó por su Action.
 *
 * Vive acá porque lo necesitan los dos tests que miran ese reparto —el de la
 * recaudación y el del recuento del fajo— y son escenarios idénticos: el
 * empleador deposita, el beneficiario cobra.
 */
trait CollectsInstallments
{
    /** Un cobro con recibo, como los 72190 a 72198 del 02/06. */
    protected function cobrar(BeneficiaryInstallment $cuota, int $recibo, string $fecha): void
    {
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'test:cobro:'.$recibo,
            talonarioNumber: (string) $recibo,
            printsTalonarioNumber: true,
            receivedDate: CarbonImmutable::parse($fecha),
        );
    }

    /** Un expediente con su haber y una cuota única, lista para cobrar. */
    protected function cuota(string $expediente, string $importe): BeneficiaryInstallment
    {
        $empresa = Person::query()->create([
            'type' => 'company',
            'legal_name' => 'Empleadora '.$expediente,
            'is_active' => true,
        ]);

        $trabajador = Person::query()->create([
            'type' => 'individual',
            'first_name' => 'Beneficiario',
            'last_name' => 'De '.$expediente,
            'is_active' => true,
        ]);

        foreach ([[$empresa, 'employer', 'company'], [$trabajador, 'beneficiary', 'individual']] as [$p, $rol, $tipo]) {
            DB::table('person_roles')->insertOrIgnore([
                'person_id' => $p->id,
                'role' => $rol,
                'person_type' => $tipo,
                'created_at' => now(),
            ]);
        }

        $expedienteModelo = Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => preg_replace('/\D/', '', $expediente) ?? '',
            'display_number' => $expediente,
            'year' => 2026,
            'received_date' => '2026-05-01',
            'employer_id' => $empresa->id,
            'employer_role' => 'employer',
            'subject' => 'Haberes en consignación',
            'status' => ExpedienteStatus::Active,
        ]);

        $haber = $expedienteModelo->haberes()->create([
            'concept' => 'Indemnización',
            'beneficiary_id' => $trabajador->id,
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => $importe,
            'expected_installment_count' => 1,
            'payment_terms' => PaymentTerms::Single,
            'workflow_status' => HaberWorkflowStatus::Active,
        ]);

        return $haber->installments()->create([
            'installment_number' => 1,
            'expected_amount' => $importe,
            'expected_medium' => ExpectedMedium::Cash,
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);
    }
}
