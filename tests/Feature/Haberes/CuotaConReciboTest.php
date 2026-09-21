<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Qué se puede corregir de una cuota que ya tiene recibo.
 *
 * **Todo**, mientras el expediente siga en el área. El recibo no impone
 * ninguna traba, y conviene decir por qué porque la intuición dice lo
 * contrario: el comprobante **copia** lo que va impreso en sus columnas
 * `*_snapshot` al emitirse, así que el papel que el empleador se llevó y
 * el registro del recibo quedan consistentes entre sí pase lo que pase
 * con la cuota. Corregirla no puede desmentir al papel, porque el papel
 * no la lee.
 *
 * Si al corregir queda plata de más imputada, eso **no se prohíbe: se
 * muestra**, y se resuelve devolviéndola al pozo de no identificados
 * —`DesasignarFondosTest` cubre ese lado—.
 *
 * El bloqueo real llega con el pase de pago, cuando el expediente sale
 * del área. Es de la etapa siguiente.
 */
class CuotaConReciboTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /**
     * El importe baja, y el excedente queda a la vista.
     *
     * No se prohíbe: se muestra. Esconderlo era lo peligroso —`remaining()`
     * recorta los negativos a cero, así que la cuota decía «financiada por
     * completo» con plata de más adentro—. Ahora `overAllocated()` lo
     * saca a la luz y se resuelve devolviéndolo al pozo.
     */
    public function test_el_importe_baja_y_el_excedente_queda_a_la_vista(): void
    {
        $cuota = $this->cuotaConRecibo();
        $menos = Decimal::sub($cuota->expected_amount, '250.00');

        $this->corregir($cuota, ['amount' => $menos])->assertSessionHasNoErrors();

        $cuota->refresh();
        $financiacion = app(InstallmentFunding::class);

        $this->assertSame($menos, $cuota->expected_amount);
        $this->assertSame('250.00', $financiacion->overAllocated($cuota));
        $this->assertSame('0.00', $financiacion->remaining($cuota));
    }

    /**
     * Y corregir la cuota no toca el recibo emitido.
     *
     * Es el motivo por el que congelar la cuota no protegía nada: el
     * comprobante **copia** lo que va impreso en sus columnas `*_snapshot`
     * al emitirse. El papel y el registro del recibo quedan consistentes
     * entre sí pase lo que pase con la cuota, porque el papel no la lee.
     */
    public function test_corregir_la_cuota_no_toca_el_recibo(): void
    {
        $cuota = $this->cuotaConRecibo();
        $antes = Receipt::query()->firstOrFail();

        $this->corregir($cuota, ['concept' => 'Otro concepto'])
            ->assertSessionHasNoErrors();

        $despues = Receipt::query()->firstOrFail();

        $this->assertSame($antes->amount, $despues->amount);
        $this->assertSame($antes->concept_snapshot, $despues->concept_snapshot);
        $this->assertSame($antes->medium_snapshot, $despues->medium_snapshot);
        $this->assertSame($antes->formatted_number, $despues->formatted_number);
        $this->assertSame('Otro concepto', $cuota->refresh()->description);
    }

    /** El concepto y el medio también se corrigen. */
    public function test_el_concepto_y_el_medio_se_corrigen(): void
    {
        $cuota = $this->cuotaConRecibo();

        $this->corregir($cuota, [
            'concept' => 'Concepto corregido',
            'expectedMedium' => 'cheque',
        ])->assertSessionHasNoErrors();

        $cuota->refresh();

        $this->assertSame('Concepto corregido', $cuota->description);
        $this->assertSame('cheque', $cuota->expected_medium?->value);
    }

    /** Y lo administrativo, que es lo que más se mueve después de cobrar. */
    public function test_lo_administrativo_se_sigue_editando(): void
    {
        $cuota = $this->cuotaConRecibo();

        $this->corregir($cuota, [
            'notes' => 'El beneficiario avisó que pasa la semana que viene.',
            'dueDate' => '2026-12-31',
        ])->assertSessionHasNoErrors();

        $cuota->refresh();

        $this->assertSame(
            'El beneficiario avisó que pasa la semana que viene.',
            $cuota->notes,
        );
        $this->assertSame('2026-12-31', $cuota->due_date?->toDateString());
    }

    /** Y la base tampoco lo impide: es un estado válido, no un error. */
    public function test_la_base_admite_la_cuota_sobre_asignada(): void
    {
        $cuota = $this->cuotaConRecibo();
        $menos = Decimal::sub($cuota->expected_amount, '1.00');

        DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['expected_amount' => $menos]);

        $this->assertSame($menos, $cuota->refresh()->expected_amount);
    }

    /** Subirlo también, claro. */
    public function test_la_base_permite_subir_el_importe(): void
    {
        $cuota = $this->cuotaConRecibo();
        $mas = Decimal::add($cuota->expected_amount, '1000.00');

        DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['expected_amount' => $mas]);

        $this->assertSame($mas, $cuota->refresh()->expected_amount);
    }

    /**
     * Subir el importe deja la cuota financiada en parte, con recibo.
     *
     * Es correcto —el recibo documenta lo cobrado y ahora se debe más—
     * pero se ve idéntico al caso normal del circuito bancario, donde
     * «falta plata por entrar». Acá no falta que llegue: falta cobrarla.
     * La pantalla lo aclara con el recibo a la vista.
     */
    public function test_subir_el_importe_deja_la_cuota_en_parte_con_recibo(): void
    {
        $cuota = $this->cuotaConRecibo();
        $cobrado = $cuota->expected_amount;
        $mas = Decimal::add($cobrado, '800.00');

        DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['expected_amount' => $mas]);

        $cuota->refresh();
        $financiacion = app(InstallmentFunding::class);

        $this->assertFalse($financiacion->isFullyFunded($cuota));
        $this->assertSame('800.00', $financiacion->remaining($cuota));
        $this->assertSame($cobrado, Receipt::query()->firstOrFail()->amount);

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.remainingAmount', '800.00')
                ->where('haber.installments.0.incomeReceipt.amount', $cobrado),
            );
    }

    /**
     * Y la diferencia no se puede cobrar sin anular primero.
     *
     * Es lo que la pantalla le dice al operador, así que conviene que sea
     * cierto: un segundo cobro fallaría al emitir, porque §2.1.14 admite
     * un solo recibo de ingreso por cuota.
     */
    public function test_la_diferencia_no_se_cobra_sin_anular_el_recibo(): void
    {
        $cuota = $this->cuotaConRecibo();

        DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['expected_amount' => Decimal::add($cuota->expected_amount, '800.00')]);

        $this->expectExceptionMessageMatches('/ya tiene el recibo/i');

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-de-la-diferencia',
            actorId: $this->operador()->id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | El historial
    |--------------------------------------------------------------------------
    */

    /** Cada corrección queda con su antes, su después y quién la hizo. */
    public function test_el_historial_muestra_el_antes_y_el_despues(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->patch($this->urlDeCorregir($cuota), [
                ...$this->datosActuales($cuota),
                'notes' => 'Primera corrección',
                'version' => $cuota->updated_at->toISOString(),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'cuota.corregida')
            ->assertJsonPath('events.0.by', $operador->name)
            ->assertJsonPath('events.0.changes.0.field', 'Observaciones')
            ->assertJsonPath('events.0.changes.0.before', $cuota->notes)
            ->assertJsonPath('events.0.changes.0.after', 'Primera corrección');
    }

    /** El importe se muestra formateado, no como viene de la columna. */
    public function test_el_historial_traduce_los_campos_a_palabras(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $antes = $cuota->expected_amount;
        $nuevo = Decimal::sub($antes, '250.00');

        $this->corregir($cuota, ['amount' => $nuevo])->assertSessionHasNoErrors();

        $evento = AuditEvent::query()
            ->where('subject_type', 'BeneficiaryInstallment')
            ->where('subject_id', $cuota->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($antes, $evento->old_values['expected_amount']);

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonPath('events.0.changes.0.field', 'Importe')
            ->assertJsonPath('events.0.changes.0.after', '$ '.Decimal::format($nuevo));
    }

    /** Una cuota sin cambios tiene historial vacío, no un error. */
    public function test_una_cuota_sin_cambios_tiene_historial_vacio(): void
    {
        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $this->cuotaEnEfectivo()))
            ->assertOk()
            ->assertJsonCount(0, 'events');
    }

    /** Quien puede ver el expediente puede ver cómo llegó a decir lo que dice. */
    public function test_quien_solo_consulta_puede_ver_el_historial(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('haberes.installments.history', $this->cuotaEnEfectivo()))
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaEnEfectivo(): BeneficiaryInstallment
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

        return $cuota->refresh();
    }

    private function cuotaConRecibo(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-para-editar',
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /**
     * @param  array<string, mixed>  $cambios
     */
    private function corregir(BeneficiaryInstallment $cuota, array $cambios): TestResponse
    {
        return $this->actingAs($this->operador())
            ->patch($this->urlDeCorregir($cuota), [
                ...$this->datosActuales($cuota),
                ...$cambios,
                'version' => $cuota->updated_at->toISOString(),
            ]);
    }

    private function verHaber(BeneficiaryInstallment $cuota): TestResponse
    {
        $haber = $cuota->haber;

        return $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk();
    }

    private function urlDeCorregir(BeneficiaryInstallment $cuota): string
    {
        return route('haberes.installments.update', [$cuota->haber->expediente, $cuota->haber, $cuota->id]);
    }

    /**
     * Lo que la cuota dice hoy, para mandar el formulario entero.
     *
     * @return array<string, mixed>
     */
    private function datosActuales(BeneficiaryInstallment $cuota): array
    {
        return [
            'amount' => $cuota->expected_amount,
            'managementLabelId' => $cuota->management_label_id,
            'dueDate' => $cuota->due_date?->toDateString(),
            'concept' => $cuota->description,
            'expectedMedium' => $cuota->expected_medium?->value,
            'notes' => $cuota->notes,
        ];
    }
}
