<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Las dos colas del egreso, que el área llama «Planillas».
 *
 * Lo que se prueba acá no es que la pantalla abra: es **qué entra y qué no
 * entra en cada cola**. La de mostrador no tiene derecho a inventar su
 * propio criterio —lo decide `DisbursementEligibility`, igual que el botón
 * de entregar—, y la prueba de eso es que una cuota trabada desaparezca de
 * la lista sin que la lista sepa por qué.
 */
class PantallaDePlanillasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_pantalla_abre_con_las_dos_colas_vacias(): void
    {
        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/planillas')
                ->where('cola', 'mostrador')
                ->has('counter', 0)
                ->has('transfers', 0)
                ->where('counterTotal', '0'),
            );
    }

    public function test_todas_incluye_las_anuladas_y_las_pagadas_fuera_del_circuito(): void
    {
        $anulada = $this->cuota();
        $historica = $this->cuotas()->whereKeyNot($anulada->id)->firstOrFail();
        $anulada->forceFill(['workflow_status' => InstallmentWorkflowStatus::Cancelled])->save();
        $historica->forceFill(['workflow_status' => InstallmentWorkflowStatus::LegacySettled])->save();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cola', 'todas')
                ->where('allCount', BeneficiaryInstallment::query()->count())
                ->where('all.data.0.installmentId', $anulada->id)
                ->where('all.data.0.status', 'Anulada')
                ->where('all.data.1.installmentId', $historica->id)
                ->where('all.data.1.status', 'Pagada fuera del circuito')
                ->has('counter', 0)
                ->has('transfers', 0));
    }

    public function test_todas_distingue_una_cuota_sin_fondos_de_una_parcial(): void
    {
        $sinFondos = $this->cuota();
        $parcial = $this->cuotas()->whereKeyNot($sinFondos->id)->firstOrFail();
        $parcial->forceFill(['expected_medium' => 'cash'])->save();
        $this->cobrar($parcial->refresh());
        $parcial->forceFill([
            'expected_amount' => Decimal::add($parcial->expected_amount, '100.00'),
        ])->save();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('all.data.0.status', 'Sin fondos imputados')
                ->where('all.data.0.paymentMethod', null)
                ->where('all.data.1.status', 'Fondos parciales · falta $ 100,00')
                ->where('all.data.1.paymentMethod', null));
    }

    public function test_todas_indica_el_medio_de_salida_cuando_la_cuota_esta_lista(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('all.data.0.installmentId', $cuota->id)
                ->where('all.data.0.status', 'Entrega pendiente')
                ->where('all.data.0.paymentMethod', 'Efectivo'));
    }

    public function test_los_filtros_estan_disponibles_para_quien_puede_ver_planillas(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('filters.expediente', '')
                ->has('stageOptions', 16));
    }

    public function test_filtro_de_situacion_se_aplica_antes_de_contar_y_paginar(): void
    {
        $sinFondos = $this->cuota();
        $pagadaFuera = $this->cuotas()->whereKeyNot($sinFondos->id)->firstOrFail();
        $pagadaFuera->forceFill(['workflow_status' => InstallmentWorkflowStatus::LegacySettled])->save();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas', 'stages' => ['paid_before_opening']]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('allCount', 1)
                ->where('all.total', 1)
                ->where('all.data.0.installmentId', $pagadaFuera->id)
                ->where('filters.stages.0', 'paid_before_opening'));
    }

    public function test_filtro_de_expediente_restringe_tambien_el_mostrador(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();
        $numero = $cuota->haber->expediente->display_number;

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['expediente' => $numero]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('counter', 1)
                ->where('counter.0.installmentId', $cuota->id));

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['expediente' => 'inexistente']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('counter', 0)
                ->where('allCount', 0));
    }

    public function test_el_mostrador_ignora_los_filtros_que_no_aparecen_en_su_panel(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', [
                'cola' => 'mostrador',
                'stages' => ['paid'],
                'medio' => 'bank_transfer',
                'retenido' => 1,
                'pagoDesde' => '2099-01-01',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('counter', 1)
                ->where('counter.0.installmentId', $cuota->id)
                ->where('allCount', 0));
    }

    public function test_transferencias_ignora_filtros_ocultos_pero_acepta_su_avance(): void
    {
        [$informada, $debitada] = $this->variasCuotas(2);
        $this->egreso($informada, DisbursementStatus::ReportReceived);
        $this->egreso($debitada, DisbursementStatus::BankDebitObserved);

        $this->actingAs($this->operador())
            ->get(route('planillas.index', [
                'cola' => 'transferencias',
                'stages' => ['paid', 'transfer_reported'],
                'medio' => 'cheque',
                'pagoDesde' => '2099-01-01',
            ]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('transfers', 1)
                ->where('transfers.0.installmentId', $informada->id));
    }

    public function test_excel_descarga_solo_las_cuotas_filtradas_e_indica_el_criterio(): void
    {
        $sinFondos = $this->cuota();
        $pagadaFuera = $this->cuotas()->whereKeyNot($sinFondos->id)->firstOrFail();
        $pagadaFuera->forceFill(['workflow_status' => InstallmentWorkflowStatus::LegacySettled])->save();

        $response = $this->actingAs($this->operador())->get(route('planillas.print', [
            'cola' => 'todas',
            'formato' => 'xlsx',
            'stages' => ['paid_before_opening'],
        ]));

        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $path = tempnam(sys_get_temp_dir(), 'planilla').'.xlsx';
        ob_start();
        $response->baseResponse->sendContent();
        $content = ob_get_clean();
        $this->assertIsString($content);
        file_put_contents($path, $content);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $this->assertStringContainsString('Pagada fuera del circuito', $sheet->getCell('A1')->getFormattedValue());
            $this->assertSame(3, $sheet->getHighestRow());
            $this->assertSame($pagadaFuera->haber->expediente->display_number, $sheet->getCell('A3')->getFormattedValue());
        } finally {
            @unlink($path);
        }
    }

    /** Cobrada en efectivo y con su recibo, la cuota entra en la fila. */
    public function test_una_cuota_cobrada_en_efectivo_aparece_en_el_mostrador(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('counter', 1)
                ->where('counter.0.installmentId', $cuota->id)
                ->where('counter.0.amount', $cuota->expected_amount)
                // El recibo de ingreso va antes que el de egreso, y la fila
                // lo lleva porque el expediente los archiva juntos.
                ->whereNot('counter.0.incomeReceiptNumber', null),
            );
    }

    /**
     * La fila enlaza al haber por su número, no por su id.
     *
     * `Haber::resolveRouteBinding()` resuelve por `haber_number` dentro del
     * expediente, así que mandar el id global abre **otro haber del mismo
     * expediente**: el que casualmente lleve ese ordinal. Pasó en
     * producción —la fila de un beneficiario llevaba a la de otro— y no da
     * error de ningún lado, porque el haber al que llega existe.
     */
    public function test_la_fila_enlaza_al_haber_por_su_numero(): void
    {
        /*
         * Un haber cuyo id global no coincida con su ordinal: es el único
         * caso que distingue. Con los dos en 1 —el primer haber del primer
         * expediente— el test pasaría con el id puesto y no protegería nada.
         */
        $cuota = BeneficiaryInstallment::query()
            ->where('workflow_status', InstallmentWorkflowStatus::Active)
            ->whereHas('haber', fn ($q) => $q
                ->whereColumn('id', '!=', 'haber_number')
                ->where('workflow_status', HaberWorkflowStatus::Active))
            ->orderBy('id')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();
        $this->cobrar($cuota->refresh());

        $haber = $cuota->refresh()->haber;

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counter.0.expedienteId', $haber->expediente_id)
                ->where('counter.0.haberNumber', $haber->haber_number),
            );
    }

    /**
     * El total no lo suma el navegador.
     *
     * Baja como cadena desde el servidor: `Number` sobre importes es un
     * `float` sobre dinero de terceros, y eso el modelo lo prohíbe en toda
     * la pila.
     */
    public function test_el_total_del_mostrador_baja_sumado_y_como_cadena(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counterTotal', $cuota->expected_amount),
            );
    }

    /**
     * Una etiqueta que bloquea el pago la saca de la lista.
     *
     * Es **la** prueba de esta pantalla: la cola no evalúa la traba del
     * §2.2.7 por su cuenta, se la pregunta a quien la sabe. Si algún día
     * alguien reescribe el filtro en SQL, este test se cae.
     */
    public function test_una_cuota_bloqueada_no_aparece_aunque_este_financiada(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $etiqueta = HaberManagementLabel::query()->create([
            'code' => 'RETENIDA',
            'description' => 'Retenida por el área',
            'blocks_payment' => true,
            'is_active' => true,
        ]);

        $cuota->forceFill(['management_label_id' => $etiqueta->id])->save();

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('counter', 0));

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('all.data.0.status', 'Entrega pendiente · pago retenido'));
    }

    /** El cheque se entrega en mano, pero no sale del cajón. */
    public function test_una_cuota_en_cheque_no_entra_en_la_planilla_de_efectivo(): void
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'cheque'])->save();

        $this->cobrar($cuota->refresh(), [
            'chequeNumber' => '12345678',
            'chequeBank' => 'Banco Nación',
        ]);

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('counter', 0));
    }

    /** Entregada, deja de estar por entregar. */
    public function test_una_cuota_ya_entregada_sale_de_la_cola(): void
    {
        $cuota = $this->cuotaCobradaEnEfectivo();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement', $cuota), [
                'idempotencyKey' => 'entrega-'.Str::random(10),
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('planillas.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('counter', 0));
    }

    /**
     * Los tres estados intermedios entran; los dos extremos no.
     *
     * `Pending` queda afuera porque ahí no salió un peso, y `Confirmed`
     * porque ya está en los libros. Lo que la cola muestra es exactamente
     * lo que existe en el banco y no en el libro.
     *
     * **Los egresos de este caso se escriben directo y con método
     * `cash`.** No es comodidad: un egreso por transferencia exige su
     * Orden —lo impone `disbursements_transfer_needs_order_check`— y
     * montar cinco Órdenes acá sería reescribir el escenario de
     * `EgresoPorTransferenciaTest`, que ya lo prueba de punta a punta. Lo
     * que se prueba en este caso es el recorte del scope, y para eso el
     * método de cada fila da igual; que una transferencia de verdad
     * aparezca en la cola lo prueba aquel test, contra el circuito real.
     */
    public function test_la_cola_de_transferencias_lleva_solo_lo_que_salio_sin_confirmar(): void
    {
        [$primera, $segunda, $tercera, $cuarta] = $this->variasCuotas(4);

        $this->egreso($primera, DisbursementStatus::Pending);
        $this->egreso($segunda, DisbursementStatus::ReportReceived);
        $this->egreso($tercera, DisbursementStatus::BankDebitObserved);
        $this->egreso($cuarta, DisbursementStatus::ReadyForValidation);

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'transferencias']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cola', 'transferencias')
                ->has('transfers', 3),
            );
    }

    /** Cada fila dice qué está esperando, que es lo que decide a quién ir a buscar. */
    public function test_cada_transferencia_dice_que_le_falta(): void
    {
        $this->egreso($this->cuota(), DisbursementStatus::ReportReceived);

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'transferencias']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('transfers.0.status', DisbursementStatus::ReportReceived->value)
                ->where(
                    'transfers.0.missingStep',
                    'Falta que el débito aparezca en el extracto. Un informe sin débito no genera egreso.',
                ),
            );
    }

    /** Una cola de trabajo es de quien la trabaja. */
    public function test_el_rol_de_consulta_no_entra(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('planillas.index'))
            ->assertForbidden();
    }

    public function test_la_planilla_tampoco(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('planillas.print'))
            ->assertForbidden();
    }

    /** Un parámetro raro cae en el mostrador, no en un error. */
    public function test_una_cola_inexistente_cae_en_el_mostrador(): void
    {
        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'cualquier-cosa']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('cola', 'mostrador'));
    }

    private function cuotaCobradaEnEfectivo(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        $this->cobrar($cuota->refresh());

        return $cuota->refresh();
    }

    /**
     * Cobrar por mostrador: financia la cuota y emite el recibo de ingreso.
     *
     * @param  array<string, mixed>  $extra
     */
    private function cobrar(BeneficiaryInstallment $cuota, array $extra = []): void
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'receivedDate' => BusinessDate::today()->toDateString(),
                'idempotencyKey' => 'cobro-'.Str::random(10),
                ...$extra,
            ])
            ->assertSessionHasNoErrors();
    }

    /**
     * Un egreso en el estado que haga falta, escrito directo.
     *
     * Los estados intermedios del circuito bancario no se alcanzan con un
     * solo acto —son tres, y el §12.3 los describe llegando en cualquier
     * orden—; recorrerlos por HTTP en cada caso probaría el circuito, que
     * ya tiene sus propios tests, y no lo que esta cola muestra.
     */
    private function egreso(
        BeneficiaryInstallment $cuota,
        DisbursementStatus $estado,
        DisbursementMethod $metodo = DisbursementMethod::Cash,
    ): Disbursement {
        return Disbursement::query()->create([
            'beneficiary_installment_id' => $cuota->id,
            'method' => $metodo,
            'amount' => $cuota->expected_amount,
            'status' => $estado,
            'report_received_at' => in_array($estado, [
                DisbursementStatus::ReportReceived,
                DisbursementStatus::ReadyForValidation,
            ], true) ? now() : null,
            'bank_debit_observed_at' => in_array($estado, [
                DisbursementStatus::BankDebitObserved,
                DisbursementStatus::ReadyForValidation,
            ], true) ? now() : null,
        ]);
    }

    private function cuota(): BeneficiaryInstallment
    {
        return $this->cuotas()->firstOrFail();
    }

    /**
     * Varias cuotas distintas, para los casos que necesitan más de una.
     *
     * De cualquier expediente: lo que estos casos necesitan es una cuota a
     * la que colgarle un egreso, y un egreso vivo ocupa el lugar de su
     * cuota —hay un índice único parcial que lo impone—, así que no pueden
     * compartirla.
     *
     * @return list<BeneficiaryInstallment>
     */
    private function variasCuotas(int $cuantas): array
    {
        $cuotas = BeneficiaryInstallment::query()
            ->orderBy('id')
            ->limit($cuantas)
            ->get()
            ->values()
            ->all();

        $this->assertCount(
            $cuantas,
            $cuotas,
            'El seeder de demo no trae suficientes cuotas para este caso.',
        );

        return $cuotas;
    }

    /** @return Builder<BeneficiaryInstallment> */
    private function cuotas(): Builder
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        return BeneficiaryInstallment::query()
            ->whereIn('haber_id', $expediente->haberes()->select('id'))
            ->orderBy('id');
    }
}
