<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La segunda lectura del listado: los haberes sin el expediente delante.
 *
 * Es la misma pantalla y la misma ruta; lo que cambia es `vista`. Lo que
 * estos tests cuidan es que sea una consulta propia y no el listado de
 * expedientes aplanado: la paginación cuenta haberes, el orden es el del
 * expediente que los trajo y la búsqueda devuelve la fila de la persona.
 *
 * Y que las cuotas del acordeón viajen resueltas y en lote: la fila se
 * despliega para mostrarlas, y eso no puede costar una consulta por haber.
 */
class HaberListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_guests_can_not_see_the_list()
    {
        $this->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertRedirect(route('login'));
    }

    /**
     * Los seis expedientes del relevamiento reconocen diez haberes: uno
     * de ellos tiene cinco beneficiarios, que es el caso que hace que
     * este listado no coincida con el de expedientes.
     */
    public function test_it_lists_every_haber_flattened()
    {
        $total = Haber::query()->count();

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/index')
                ->where('vista', 'haberes')
                ->has('haberes', $total)
                ->where('pagination.total', $total)
                // El expediente más nuevo primero, igual que en la otra vista.
                ->where('haberes.0.expedienteNumber', '125957/2026')
                ->missing('expedientes')
            );
    }

    /**
     * La fila viaja con el contexto que la ubica. Sin el expediente y el
     * empleador, un nombre suelto no dice de dónde salió.
     */
    public function test_each_row_carries_its_expediente_and_employer()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('haberes.0.beneficiaryName', 'García, Claudio Adrián')
                ->where('haberes.0.expedienteNumber', '125957/2026')
                ->where('haberes.0.employerName', fn (mixed $nombre): bool => is_string($nombre) && $nombre !== '')
                // Nada de float en el camino de PHP al navegador.
                ->where('haberes.0.assignedAmount', '1204500.00')
            );
    }

    public function test_it_searches_by_beneficiary_and_returns_that_haber()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes', 'q' => 'Guaymás']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('haberes', 1)
                ->where('haberes.0.expedienteNumber', '120326/2019')
            );
    }

    /**
     * Buscar por expediente en esta vista trae sus haberes, no el
     * expediente: son cinco filas, una por trabajador.
     */
    public function test_searching_by_expediente_returns_its_haberes()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes', 'q' => '120326']))
            ->assertInertia(fn (Assert $page) => $page->has('haberes', 5));
    }

    public function test_it_searches_by_document_number()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes', 'q' => '28114902']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('haberes', 1)
                ->where('haberes.0.beneficiaryName', 'García, Claudio Adrián')
            );
    }

    /**
     * Los anulados se esconden contando haberes, no expedientes: un
     * expediente vigente con su único haber anulado no aparece.
     */
    public function test_cancelled_haberes_are_hidden_unless_asked_for()
    {
        $haber = Haber::query()->firstOrFail();
        $haber->update(['workflow_status' => 'cancelled']);

        $vigentes = Haber::query()->whereNot('workflow_status', 'cancelled')->count();

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('haberes', $vigentes)
                ->where('anulados', 1)
            );

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes', 'anulados' => 1]))
            ->assertInertia(fn (Assert $page) => $page->has('haberes', $vigentes + 1));
    }

    /**
     * La paginación cuenta haberes. Es la razón de que esta vista sea una
     * consulta propia: aplanar la página de expedientes daría un total
     * que no es el de ninguna de las dos listas.
     */
    public function test_the_list_is_paginated_over_haberes()
    {
        /*
         * Un beneficiario no puede repetirse dentro del mismo expediente
         * —lo impide un único compuesto—, así que las filas de relleno
         * salen de cruzar los que ya existen con los demás expedientes.
         */
        $beneficiarios = Haber::query()->pluck('beneficiary_id')->unique();

        foreach (Expediente::query()->pluck('id') as $expedienteId) {
            foreach ($beneficiarios as $beneficiaryId) {
                Haber::query()->firstOrCreate(
                    [
                        'expediente_id' => $expedienteId,
                        'beneficiary_id' => $beneficiaryId,
                    ],
                    [
                        'beneficiary_role' => 'beneficiary',
                        'assigned_amount' => '1000.00',
                        'expected_installment_count' => 1,
                        'workflow_status' => 'active',
                    ],
                );
            }
        }

        $total = Haber::query()->count();
        $this->assertGreaterThan(25, $total);

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('haberes', 25)
                ->where('pagination.total', $total)
                ->where('pagination.lastPage', (int) ceil($total / 25))
                // La vista sobrevive al paso de página.
                ->where('pagination.next', fn (mixed $url): bool => is_string($url) && str_contains($url, 'vista=haberes'))
            );
    }

    /**
     * La fila trae sus cuotas, que es lo que el acordeón despliega.
     *
     * Con lo que hace falta para que no mientan: la etiqueta de gestión
     * —es la que hace visible la cuota trabada— y la etapa del circuito,
     * que es más fina que `status` y responde dónde está el dinero.
     */
    public function test_each_row_carries_its_installments()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertInertia(fn (Assert $page) => $page
                // Dos cuotas de 602.250, las del acta de García.
                ->has('haberes.0.installments', 2)
                ->where('haberes.0.installments.0.number', 1)
                // Nada de float en el camino de PHP al navegador.
                ->where('haberes.0.installments.0.expectedAmount', '602250.00')
                ->where('haberes.0.installments.0.managementLabel', 'T.CONOC.')
                ->where('haberes.0.installments.0.concept', 'Pago convenio homologado')
                ->where('haberes.0.installments.0.stage', fn (mixed $etapa): bool => is_string($etapa) && $etapa !== '')
                ->where('haberes.0.installments.1.number', 2)
            );
    }

    /**
     * El acordeón no se paga con una consulta por fila.
     *
     * Las cuotas, lo imputado, el recibo de ingreso, el traslado al banco
     * y la etapa se resuelven en lote: la página con veinticinco haberes
     * tiene que costar lo mismo que la que trae diez. Sin esta guarda, un
     * `foreach` con una consulta adentro pasa desapercibido hasta que el
     * listado tarda tres segundos con datos reales.
     */
    public function test_the_installments_cost_the_same_for_one_row_than_for_a_full_page()
    {
        $operador = $this->operador();

        /*
         * La primera pasada se descarta: es la que llena la caché de
         * permisos, y esas consultas no son del listado. Sin esto la
         * medición diría que crecer cuesta dos consultas más, y el número
         * no tendría nada que ver con las cuotas.
         */
        $this->consultasDelListado($operador);

        $conLosSembrados = $this->consultasDelListado($operador);

        $this->llenarLaPagina();

        $this->assertSame(
            $conLosSembrados,
            $this->consultasDelListado($operador),
            'El listado de haberes gana consultas al crecer: alguna se está haciendo por fila.',
        );
    }

    /** Sin `vista`, la pantalla sigue siendo la de expedientes. */
    public function test_the_expediente_view_is_still_the_default()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('vista', 'expedientes')
                ->has('expedientes')
                ->missing('haberes')
            );
    }

    /** Cuántas consultas cuesta dibujar la primera página. */
    private function consultasDelListado(User $operador): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($operador)
            ->get(route('expedientes.index', ['vista' => 'haberes']))
            ->assertOk();

        $consultas = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $consultas;
    }

    /**
     * Haberes de relleno hasta pasar los veinticinco de la página, cada
     * uno con sus cuotas: sin cuotas, el listado no tendría qué repetir.
     *
     * Un beneficiario no puede repetirse dentro del mismo expediente —lo
     * impide un único compuesto—, así que salen de cruzar los que ya
     * existen con los demás expedientes.
     */
    private function llenarLaPagina(): void
    {
        $beneficiarios = Haber::query()->pluck('beneficiary_id')->unique();

        foreach (Expediente::query()->pluck('id') as $expedienteId) {
            foreach ($beneficiarios as $beneficiaryId) {
                $haber = Haber::query()->firstOrCreate(
                    [
                        'expediente_id' => $expedienteId,
                        'beneficiary_id' => $beneficiaryId,
                    ],
                    [
                        'beneficiary_role' => 'beneficiary',
                        'assigned_amount' => '1000.00',
                        'expected_installment_count' => 2,
                        'workflow_status' => 'active',
                    ],
                );

                if ($haber->wasRecentlyCreated) {
                    foreach ([1, 2] as $numero) {
                        BeneficiaryInstallment::query()->create([
                            'haber_id' => $haber->id,
                            'installment_number' => $numero,
                            'expected_amount' => '500.00',
                            'expected_medium' => 'cash',
                            'workflow_status' => 'active',
                        ]);
                    }
                }
            }
        }

        $this->assertGreaterThan(25, Haber::query()->count());
    }
}
