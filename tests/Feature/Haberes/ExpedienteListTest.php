<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExpedienteListTest extends TestCase
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

    public function test_guests_can_not_see_the_list()
    {
        $this->get(route('expedientes.index'))->assertRedirect(route('login'));
    }

    public function test_it_lists_the_expedientes_with_their_haberes()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/index')->has('expedientes', 6)
                // Ordenado por fecha de recepción descendente: primero el
                // de 2026-07-18, último el de 2018.
                ->where('expedientes.0.displayNumber', '125957/2026')
                ->has('expedientes.0.haberes', 1)
                // Un solo acto, cinco trabajadores: el caso que descartó
                // la consignación intermedia entre expediente y haber.
                ->where('expedientes.5.displayNumber', '45905/2018')
                // Un acto, cinco haberes: el caso que demostró que no hacía
                // falta una tabla intermedia entre expediente y haber.
                ->where('expedientes.4.displayNumber', '120326/2019')
                ->has('expedientes.4.haberes', 5)
            );
    }

    public function test_the_amounts_travel_as_decimal_strings()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expedientes.0.recognizedTotalAmount', '1204500.00')
                ->where('expedientes.0.haberes.0.assignedAmount', '1204500.00')
            );
    }

    public function test_it_searches_by_expediente_number()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['q' => '45905']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expedientes', 1)
                ->where('expedientes.0.displayNumber', '45905/2018')
            );
    }

    /**
     * Buscar por beneficiario tiene que encontrar el expediente que lo
     * contiene, aunque el nombre no esté en la carátula: es el caso de los
     * expedientes con varios trabajadores.
     */
    public function test_it_searches_by_beneficiary_inside_the_expediente()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['q' => 'Guaymás']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expedientes', 1)
                ->where('expedientes.0.displayNumber', '120326/2019')
            );
    }

    public function test_the_search_ignores_accents_and_case()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['q' => 'guaymas']))
            ->assertInertia(fn (Assert $page) => $page->has('expedientes', 1));
    }

    public function test_it_searches_by_document_number()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['q' => '28114902']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expedientes', 1)
                ->where('expedientes.0.haberes.0.beneficiaryName', 'García, Claudio Adrián')
            );
    }

    public function test_a_search_without_matches_returns_an_empty_list()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['q' => 'no-existe-nada']))
            ->assertInertia(fn (Assert $page) => $page->has('expedientes', 0));
    }

    public function test_the_list_is_paginated_and_reports_the_real_total(): void
    {
        foreach (range(1, 30) as $number) {
            Expediente::query()->create([
                'source_system' => 'test',
                'external_id' => "pagination-{$number}",
                'canonical_number' => "TEST-{$number}",
                'display_number' => "P-{$number}/2026",
                'year' => 2026,
                'received_date' => now()->subDays($number),
                'employer_id' => 101,
                'employer_role' => 'employer',
                'subject' => "Expediente de paginación {$number}",
                'status' => 'active',
            ]);
        }

        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('expedientes', 25)
                ->where('pagination.currentPage', 1)
                ->where('pagination.lastPage', 2)
                ->where('pagination.total', 36)
                ->where('pagination.from', 1)
                ->where('pagination.to', 25)
                ->where('pagination.previous', null)
                ->where('pagination.next', fn (mixed $url): bool => is_string($url) && str_contains($url, 'page=2'))
            );
    }

    /*
    |---------------------------------------------------------------------
    | Cuándo se cargó y cuándo se tocó
    |---------------------------------------------------------------------
    */

    /**
     * Sin cambios después del alta, la columna no inventa una fecha.
     *
     * Es la mitad que se olvida: mostrar ahí la fecha de carga haría creer
     * que alguien modificó el expediente el día que lo creó.
     */
    public function test_un_expediente_recien_cargado_no_trae_modificacion(): void
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expedientes.0.displayNumber', '125957/2026')
                ->where('expedientes.0.createdAt', fn (mixed $fecha): bool => is_string($fecha) && $fecha !== '')
                ->where('expedientes.0.lastChange', null)
            );
    }

    /** Corregir la ficha sí deja fecha, y con el nombre de quién la tocó. */
    public function test_corregir_la_ficha_deja_fecha_y_autor(): void
    {
        $expediente = Expediente::query()
            ->where('display_number', '125957/2026')
            ->firstOrFail();

        $operador = $this->operador();

        $this->actingAs($operador)
            ->patch(route('expedientes.update', $expediente), [
                'subject' => 'Carátula corregida',
                'receivedDate' => $expediente->received_date?->toDateString(),
                'employerId' => $expediente->employer_id,
                'employerRepresentative' => $expediente->employer_representative,
                'declaredTotalAmount' => $expediente->declared_total_amount,
                'externalReference' => $expediente->external_reference,
                'notes' => $expediente->notes,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($operador)
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expedientes.0.displayNumber', '125957/2026')
                ->where('expedientes.0.lastChange.by', $operador->refresh()->name)
                ->where('expedientes.0.lastChange.at', fn (mixed $fecha): bool => is_string($fecha) && $fecha !== '')
            );
    }

    /**
     * La razón por la que esto sale de `audit_events` y no de `updated_at`.
     *
     * Anular un expediente arrastra a sus haberes con un `update` masivo,
     * y Eloquent le pone `updated_at` de hoy a cada uno. Leer ese
     * timestamp haría que un haber que nadie tocó apareciera modificado
     * hoy, y mandaría al operador a buscar un cambio que no existe. La
     * auditoría registra el hecho una sola vez y contra el expediente,
     * que es de quien fue la decisión.
     */
    public function test_anular_el_expediente_no_le_inventa_una_modificacion_a_sus_haberes(): void
    {
        $expediente = Expediente::query()
            ->where('display_number', '125957/2026')
            ->firstOrFail();

        $haber = $expediente->haberes()->firstOrFail();
        $tocadoAntes = $haber->updated_at;

        $this->travel(1)->minutes();

        $this->actingAs($this->operador('contador'))
            ->patch(route('expedientes.cancel', $expediente), [
                'reason' => 'Cargado sobre el expediente equivocado.',
            ])
            ->assertSessionHasNoErrors();

        // La trampa es real: el timestamp del haber se movió solo.
        $this->assertTrue($haber->refresh()->updated_at->greaterThan($tocadoAntes));

        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['vista' => 'haberes', 'anulados' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('haberes.0.expedienteNumber', '125957/2026')
                ->where('haberes.0.lastChange', null)
            );

        // Y el expediente, que sí fue anulado, lo dice.
        $this->actingAs($this->operador())
            ->get(route('expedientes.index', ['anulados' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expedientes.0.displayNumber', '125957/2026')
                ->where('expedientes.0.lastChange.at', fn (mixed $fecha): bool => is_string($fecha) && $fecha !== '')
            );
    }

    /**
     * Los haberes del acordeón cuentan lo mismo que su expediente.
     *
     * Y tienen que contarlo **en las dos pantallas**: el detalle dibuja
     * esos haberes con el mismo componente que el listado, así que si la
     * búsqueda se cableara en una sola, la misma fila diría «nunca se
     * modificó» en un lado y la verdad en el otro.
     */
    public function test_el_haber_anidado_trae_su_propia_modificacion(): void
    {
        $expediente = Expediente::query()
            ->where('display_number', '120326/2019')
            ->firstOrFail();

        $haber = $expediente->haberes()->orderBy('id')->firstOrFail();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->patch(route('haberes.haber.cancel', [$expediente, $haber]), [
                'reason' => 'El acta no lo reconocía; se cargó de más.',
            ])
            ->assertSessionHasNoErrors();

        $autor = $contador->refresh()->name;

        $this->actingAs($this->operador())
            ->get(route('expedientes.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expedientes.4.displayNumber', '120326/2019')
                ->where('expedientes.4.haberes.0.lastChange.by', $autor)
                // Sus hermanos no se tocaron y no inventan nada.
                ->where('expedientes.4.haberes.1.lastChange', null)
            );

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.haberes.0.lastChange.by', $autor)
                ->where('expediente.haberes.1.lastChange', null)
            );
    }
}
