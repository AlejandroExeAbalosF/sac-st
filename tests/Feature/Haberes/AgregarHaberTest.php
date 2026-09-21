<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\HaberManagementLabel;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Alta de un haber dentro de un expediente ya cargado.
 *
 * Acá viven las validaciones cruzadas del modelo: la suma de las cuotas
 * contra el importe del haber, la unicidad del beneficiario y la numeración
 * de las cuotas.
 */
class AgregarHaberTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Los seis casos del relevamiento.
     *
     * Se siembran de verdad en PostgreSQL: lo que estos tests comprueban
     * son restricciones, triggers y tipos, no PHP.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Cruz Mirta Elena c/ Servicios Integrales SRL: un solo haber. */
    private const SIN_HABERES = '77420/2024';

    /**
     * El expediente por su número, nunca por su id.
     *
     * `RefreshDatabase` revierte las filas pero no las secuencias de
     * PostgreSQL, así que los ids del sembrado cambian en cada test. El
     * número es lo estable —y además es como lo busca el operador—.
     */
    public function test_each_installment_keeps_its_medium_and_notes(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['expectedInstallmentCount'] = 2;
        $datos['installments'] = [
            [
                'number' => 1,
                'amount' => '600000.00',
                'expectedMedium' => 'cash',
                'notes' => 'Cobrado por mostrador con el ticket 00071514.',
            ],
            [
                'number' => 2,
                'amount' => '600000.00',
                'expectedMedium' => 'bank',
                'notes' => '',
            ],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasNoErrors();

        $haber = $expediente->haberes()->latest('id')->firstOrFail();

        $this->assertDatabaseHas('beneficiary_installments', [
            'haber_id' => $haber->id,
            'installment_number' => 1,
            'expected_medium' => 'cash',
            'notes' => 'Cobrado por mostrador con el ticket 00071514.',
        ]);

        // Cuotas distintas del mismo haber pueden usar medios distintos.
        $this->assertDatabaseHas('beneficiary_installments', [
            'haber_id' => $haber->id,
            'installment_number' => 2,
            'expected_medium' => 'bank',
            'notes' => null,
        ]);
    }

    /**
     * El medio previsto es obligatorio, y lo dice el formulario.
     *
     * Una cuota sin medio no se puede leer: no dice si se cobra por
     * mostrador o si se espera un depósito del empleador, y de eso depende
     * todo el circuito que viene después —qué botón aparece, si lleva Orden
     * de Pago, por dónde sale el dinero—.
     */
    public function test_the_expected_medium_is_required(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        unset($datos['installments'][0]['expectedMedium']);

        $antes = BeneficiaryInstallment::query()->count();

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasErrors('installments.0.expectedMedium');

        // Y no quedó a medias: no se creó ninguna cuota.
        $this->assertSame($antes, BeneficiaryInstallment::query()->count());
    }

    /**
     * Y la base lo impone igual, sin pasar por el formulario.
     *
     * El mensaje legible es del `FormRequest`; esto es lo que evita que un
     * Action, un seeder o una importación dejen una cuota sin medio. Los
     * dos lados, como pide el modelo.
     */
    public function test_the_database_refuses_an_installment_without_medium(): void
    {
        $haber = Haber::query()->orderBy('id')->firstOrFail();

        $this->expectException(QueryException::class);

        BeneficiaryInstallment::query()->forceCreate([
            'haber_id' => $haber->id,
            'installment_number' => 99,
            'expected_amount' => '1000.00',
            'expected_medium' => null,
            'workflow_status' => 'active',
        ]);
    }

    public function test_an_unknown_medium_is_rejected(): void
    {
        $datos = $this->haber();
        $datos['installments'][0]['expectedMedium'] = 'bitcoin';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasErrors('installments.0.expectedMedium');
    }

    /**
     * La pantalla anuncia que el concepto en blanco es «el mismo del
     * haber», y eso ahora significa guardar `null` y resolverlo al leer,
     * no copiar el texto en cada cuota.
     *
     * La copia parecía equivalente y no lo era: la vía que agrega una cuota
     * meses después guarda `null`, así que corregir el concepto del haber
     * alcanzaba a unas cuotas y a otras no.
     */
    public function test_an_empty_installment_concept_is_stored_as_inherited(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['concept'] = 'Indemnización por despido';
        $datos['installments'][0]['concept'] = '';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasNoErrors();

        $haber = $expediente->haberes()->latest('id')->firstOrFail();

        // El concepto queda en el haber…
        $this->assertSame('Indemnización por despido', $haber->concept);

        // …y la cuota no guarda copia.
        $this->assertDatabaseHas('beneficiary_installments', [
            'haber_id' => $haber->id,
            'description' => null,
        ]);

        // Pero se muestra igual: la resolución ocurre al leer. El
        // expediente ya traía un haber del sembrado, así que el nuevo es el
        // segundo.
        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertInertia(fn (Assert $page) => $page->where(
                'expediente.haberes.1.installments.0.concept',
                'Indemnización por despido',
            ));
    }

    public function test_an_installment_with_its_own_concept_overrides_the_haber_one(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['concept'] = 'Indemnización por despido';
        $datos['installments'][0]['concept'] = 'SAC proporcional';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('beneficiary_installments', [
            'haber_id' => $expediente->haberes()->latest('id')->value('id'),
            'description' => 'SAC proporcional',
        ]);
    }

    public function test_a_single_installment_is_stored_as_a_single_payment(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['expectedInstallmentCount'] = 1;
        $datos['installments'] = [['number' => 1, 'amount' => $datos['assignedAmount'], 'expectedMedium' => 'cash']];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('haberes', [
            'id' => $expediente->haberes()->latest('id')->value('id'),
            'expected_installment_count' => 1,
            'payment_terms' => 'single',
        ]);
    }

    public function test_more_than_one_installment_is_stored_as_instalments(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['expectedInstallmentCount'] = 2;
        $datos['installments'] = [
            ['number' => 1, 'amount' => '600000.00', 'expectedMedium' => 'cash'],
            ['number' => 2, 'amount' => '600000.00', 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('haberes', [
            'id' => $expediente->haberes()->latest('id')->value('id'),
            'expected_installment_count' => 2,
            'payment_terms' => 'installments',
        ]);
    }

    /**
     * Un acto puede reconocer varios haberes —el expediente de Bulacio
     * tiene cinco— y volver al detalle entre uno y otro obliga a arrancar
     * de nuevo cada vez.
     */
    public function test_saving_and_adding_another_comes_back_to_a_blank_form(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), [
                ...$this->haber(),
                'andAnother' => true,
            ])
            ->assertRedirect(route('haberes.haber.create', $expediente->id))
            ->assertSessionHas('status');
    }

    public function test_saving_without_adding_another_goes_back_to_the_expediente(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $this->haber())
            ->assertRedirect(route('expedientes.show', $expediente));
    }

    /**
     * Un haber que espera transferencia lleva a su propia pantalla.
     *
     * El comprobante del depósito llega dentro del expediente, así que
     * quien acaba de cargar el haber suele tener el papel en la mano. En
     * el detalle del haber cada cuota ofrece cargarlo; en el del
     * expediente habría que buscar el haber primero.
     */
    public function test_un_haber_por_transferencia_lleva_a_cargar_el_comprobante(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['installments'][0]['expectedMedium'] = 'bank';

        $respuesta = $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos);

        $respuesta->assertSessionHasNoErrors();

        $haber = $expediente->haberes()->latest('id')->firstOrFail();

        $respuesta->assertRedirect(route('haberes.haber.show', [$expediente, $haber]));

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/haber-show')
                // La cuota llega sin comprobante: es lo que hace que la
                // tarjeta ofrezca cargarlo.
                ->where('haber.installments.0.expectedMedium', 'bank')
                ->where('haber.installments.0.depositTicket', null));
    }

    /** En efectivo no hay comprobante bancario, y el expediente sigue siendo el destino. */
    public function test_un_haber_en_efectivo_no_desvia_a_cargar_comprobante(): void
    {
        $expediente = $this->expediente(self::SIN_HABERES);
        $datos = $this->haber();
        $datos['installments'][0]['expectedMedium'] = 'cash';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $expediente), $datos)
            ->assertRedirect(route('expedientes.show', $expediente));
    }

    /** El detalle ya no arma el formulario, así que no carga sus catálogos. */
    public function test_the_detail_no_longer_carries_the_form_catalogues(): void
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $this->expediente('125957/2026')))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/show')
                ->missing('beneficiarios'));
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }

    /** La etiqueta por su código, por el mismo motivo que el expediente. */
    private function etiqueta(string $codigo): int
    {
        return HaberManagementLabel::query()->where('code', $codigo)->value('id');
    }

    /**
     * El alta de un haber tiene pantalla propia, y ahí van sus catálogos.
     * El detalle se quedó solo con lo que necesita para mostrar y corregir
     * las cuotas ya cargadas.
     */
    public function test_the_form_screen_offers_the_catalogues_and_the_context()
    {
        $expediente = $this->expediente('125957/2026');

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.create', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/haber-create')
                ->has('beneficiarios')
                ->has('etiquetas', 3)
                // El contexto contra el que se controla lo que se carga:
                // cuánto ya reconoce y a quiénes.
                ->where('expediente.displayNumber', '125957/2026')
                ->has('expediente.haberes', 1)
            );
    }

    public function test_a_valid_haber_is_added_to_the_expediente()
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $this->haber())
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->get(route('expedientes.show', $this->expediente(self::SIN_HABERES)))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expediente.haberes', 2)
                ->where('expediente.haberes.1.beneficiaryName', 'Tinte, Olga Isabel')
            );
    }

    public function test_it_needs_at_least_the_first_installment()
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), [
                ...$this->haber(),
                'installments' => [],
            ])
            ->assertSessionHasErrors('installments');
    }

    /**
     * El control central: mientras falten cuotas por definir la suma puede
     * ser menor, pero nunca mayor.
     */
    public function test_the_installments_can_not_exceed_the_haber_amount()
    {
        $datos = $this->haber();
        $datos['installments'][0]['amount'] = '9000000.00';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasErrors('installments');
    }

    public function test_the_haber_amount_can_not_be_reduced_below_its_installments(): void
    {
        $haber = $this->expediente('125957/2026')->haberes()->firstOrFail();

        $this->expectException(QueryException::class);

        $haber->update(['assigned_amount' => '100.00']);
        DB::statement('SET CONSTRAINTS haber_assigned_amount_sum IMMEDIATE');
    }

    /**
     * El caso normal según el área: el expediente llega con un solo ticket
     * aunque el acta prevea tres cuotas.
     */
    public function test_a_partial_load_is_allowed_while_installments_are_missing()
    {
        $datos = $this->haber();
        $datos['expectedInstallmentCount'] = 3;
        $datos['installments'] = [
            ['number' => 1, 'amount' => '400000.00', 'managementLabelId' => $this->etiqueta('T.CONOC.'), 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasNoErrors();
    }

    public function test_once_every_expected_installment_is_loaded_the_sum_must_match_exactly()
    {
        $datos = $this->haber();
        $datos['assignedAmount'] = '1200000.00';
        $datos['expectedInstallmentCount'] = 2;
        // Las dos previstas ya están, pero suman 1.100.000 y el haber son
        // 1.200.000: falta plata sin ninguna cuota donde ponerla.
        $datos['installments'] = [
            ['number' => 1, 'amount' => '600000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ['number' => 2, 'amount' => '500000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasErrors('installments');
    }

    public function test_when_every_expected_installment_is_loaded_and_the_sum_matches_it_passes()
    {
        $datos = $this->haber();
        $datos['assignedAmount'] = '1200000.00';
        $datos['expectedInstallmentCount'] = 2;
        $datos['installments'] = [
            ['number' => 1, 'amount' => '600000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ['number' => 2, 'amount' => '600000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasNoErrors();
    }

    /**
     * Los centavos son lo que rompe una validación hecha con punto
     * flotante. Es el caso Tinte, tal cual figura en el relevamiento.
     */
    public function test_cents_add_up_exactly()
    {
        $datos = $this->haber();
        $datos['assignedAmount'] = '5790.06';
        $datos['expectedInstallmentCount'] = 2;
        $datos['installments'] = [
            ['number' => 1, 'amount' => '3890.06', 'managementLabelId' => null, 'concept' => 'Concepto A', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ['number' => 2, 'amount' => '1900.00', 'managementLabelId' => null, 'concept' => 'Concepto B', 'dueDate' => null, 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasNoErrors();
    }

    /**
     * Un beneficiario tiene un solo haber por expediente. El expediente 1
     * ya tiene el de García.
     */
    public function test_the_same_beneficiary_can_not_have_two_haberes_in_one_expediente()
    {
        $datos = $this->haber();
        $datos['beneficiaryId'] = 201; // García, Claudio Adrián

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente('125957/2026')), $datos)
            ->assertSessionHasErrors('beneficiaryId');
    }

    public function test_installment_numbers_can_not_repeat()
    {
        $datos = $this->haber();
        $datos['expectedInstallmentCount'] = 2;
        $datos['installments'] = [
            ['number' => 1, 'amount' => '400000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ['number' => 1, 'amount' => '400000.00', 'managementLabelId' => null, 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasErrors('installments.1.number');
    }

    public function test_amounts_must_be_positive()
    {
        $datos = $this->haber();
        $datos['installments'][0]['amount'] = '0';

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $datos)
            ->assertSessionHasErrors('installments.0.amount');
    }

    public function test_the_beneficiary_must_exist_in_the_people_master(): void
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), [
                ...$this->haber(),
                'beneficiaryId' => 999999,
            ])
            ->assertSessionHasErrors('beneficiaryId');
    }

    public function test_a_bank_account_can_be_kept_as_the_haber_preference_while_unverified(): void
    {
        $accountId = DB::table('person_bank_accounts')->insertGetId([
            'person_id' => 207,
            'cbu' => '2850000300000000000024',
            'verification_status' => 'unverified',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = [
            ...$this->haber(),
            'defaultBankAccountId' => $accountId,
        ];

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), $data)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('haberes', [
            'beneficiary_id' => 207,
            'default_bank_account_id' => $accountId,
        ]);
    }

    public function test_a_haber_can_not_use_another_persons_bank_account(): void
    {
        $accountId = DB::table('person_bank_accounts')->insertGetId([
            'person_id' => 201,
            'cbu' => '2850000300000000000024',
            'verification_status' => 'unverified',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', $this->expediente(self::SIN_HABERES)), [
                ...$this->haber(),
                'defaultBankAccountId' => $accountId,
            ])
            ->assertSessionHasErrors('defaultBankAccountId');

        $this->assertDatabaseMissing('haberes', [
            'beneficiary_id' => 207,
            'default_bank_account_id' => $accountId,
        ]);
    }

    public function test_it_can_not_be_added_to_an_unknown_expediente()
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.haber.store', 9999), $this->haber())
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function haber(): array
    {
        return [
            'beneficiaryId' => 207, // Tinte, Olga Isabel
            'assignedAmount' => '1200000.00',
            'expectedInstallmentCount' => 2,
            'concept' => 'Pago convenio homologado por Res. N° 3269/2025',
            'installments' => [
                ['number' => 1, 'amount' => '600000.00', 'managementLabelId' => $this->etiqueta('T.CONOC.'), 'concept' => '', 'dueDate' => null, 'expectedMedium' => 'cash'],
            ],
        ];
    }
}
