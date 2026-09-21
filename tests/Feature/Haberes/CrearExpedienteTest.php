<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El alta se parte en dos: primero el expediente, después sus haberes.
 *
 * No es una concesión a la comodidad: el expediente vuelve con el tiempo
 * trayendo el ticket de la cuota siguiente, así que el camino de «abrir un
 * expediente ya cargado y completarlo» hay que construirlo igual. El alta
 * usa ese mismo camino.
 */
class CrearExpedienteTest extends TestCase
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

    public function test_the_form_offers_the_employers()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/create')
                ->has('empleadores')
            );
    }

    public function test_the_expediente_number_must_follow_the_sice_format()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [...$this->datos(), 'number' => 'ABC-123'])
            ->assertSessionHasErrors('number');
    }

    public function test_it_accepts_the_short_form_and_completes_the_prefix()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [...$this->datos(), 'number' => '333333/2026'])
            ->assertSessionHasNoErrors();
    }

    public function test_it_can_not_be_received_in_the_future()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [
                ...$this->datos(),
                'receivedDate' => now()->addWeek()->toDateString(),
            ])
            ->assertSessionHasErrors('receivedDate');
    }

    public function test_the_employer_must_exist_in_the_people_master(): void
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [
                ...$this->datos(),
                'employerId' => 999999,
            ])
            ->assertSessionHasErrors('employerId');
    }

    /**
     * Después de guardar se va al detalle, que es donde se le agregan los
     * haberes. Es el punto del reparto: nada queda sin guardar mientras se
     * carga el resto.
     */
    public function test_after_saving_it_goes_to_the_detail_of_the_new_expediente()
    {
        $respuesta = $this->actingAs($this->operador())
            ->post(route('expedientes.store'), $this->datos());

        $respuesta->assertSessionHasNoErrors();
        // El id lo asigna la secuencia y no se puede predecir: lo que
        // importa es que lleve al expediente recién creado.
        $nuevo = Expediente::query()->where('display_number', '222222/2026')->firstOrFail();

        $respuesta->assertRedirect(route('expedientes.show', $nuevo));
        $this->assertToast(
            'Expediente registrado.',
            'Agregale ahora los haberes que reconoce.',
        );
        $this->assertSame('Antecedente remitido por Mesa de Entradas.', $nuevo->notes);
    }

    public function test_the_new_expediente_starts_with_no_haberes()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), $this->datos());

        $nuevo = Expediente::query()->where('display_number', '222222/2026')->firstOrFail();

        $this->get(route('expedientes.show', $nuevo))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/show')
                ->where('expediente.displayNumber', '222222/2026')
                ->where('expediente.canonicalNumber', '0030064-222222/2026-0')
                ->has('expediente.haberes', 0)
            );
    }

    /*
     |--------------------------------------------------------------------
     | Verificación de existencia
     |--------------------------------------------------------------------
     |
     | El expediente vuelve con el tiempo, así que el operador no sabe si
     | lo que tiene en la mano ya está cargado. La pantalla se lo dice
     | mientras escribe; el control duro está en la validación.
     */

    public function test_the_form_reports_nothing_when_no_number_is_given()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create'))
            ->assertInertia(fn (Assert $page) => $page->where('existente', null));
    }

    public function test_it_reports_when_the_number_is_already_loaded()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create', ['expediente' => '0030064-125957/2026-0']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('existente.displayNumber', '125957/2026')
                ->where('existente.subject', 'Acta acuerdo — García Claudio Adrián c/ CIACSA')
                ->has('existente.haberes', 1)
            );
    }

    /**
     * `125957/2026` y `0030064-125957/2026-0` son el mismo expediente: la
     * forma corta omite el prefijo de la Secretaría y la instancia. Si la
     * comparación fuera sobre lo tipeado, el duplicado entraría igual.
     */
    public function test_the_short_form_finds_the_same_expediente()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create', ['expediente' => '125957/2026']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('existente.displayNumber', '125957/2026')
            );
    }

    public function test_a_free_number_reports_nothing()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create', ['expediente' => '999999/2026']))
            ->assertInertia(fn (Assert $page) => $page->where('existente', null));
    }

    public function test_a_malformed_number_reports_nothing()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.create', ['expediente' => 'no-es-un-numero']))
            ->assertInertia(fn (Assert $page) => $page->where('existente', null));
    }

    /**
     * El control que de verdad impide el duplicado. Un expediente cargado
     * dos veces duplica sus importes en todos los reportes.
     */
    public function test_an_already_loaded_expediente_can_not_be_created_again()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [
                ...$this->datos(),
                'number' => '0030064-125957/2026-0',
            ])
            ->assertSessionHasErrors('number');
    }

    public function test_the_duplicate_is_rejected_even_using_the_short_form()
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [
                ...$this->datos(),
                'number' => '125957/2026',
            ])
            ->assertSessionHasErrors('number');
    }

    public function test_an_unknown_expediente_is_not_found()
    {
        $this->actingAs($this->operador())
            ->get(route('expedientes.show', 9999))
            ->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Lo que identifica al expediente es su número. La carátula ayuda a
     * reconocerlo y a veces no viene en el papel; esperar a tenerla frena
     * la carga sin ganar nada.
     */
    public function test_the_caratula_is_optional(): void
    {
        $datos = $this->datos();
        unset($datos['subject']);

        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), $datos)
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('expedientes', [
            'display_number' => '222222/2026',
            'subject' => null,
        ]);
    }

    public function test_the_expediente_number_is_still_required(): void
    {
        $datos = $this->datos();
        $datos['number'] = '';

        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), $datos)
            ->assertSessionHasErrors('number');
    }

    /** Sin carátula el detalle se sigue titulando con el número. */
    public function test_the_detail_is_titled_by_the_number(): void
    {
        $datos = $this->datos();
        unset($datos['subject']);

        $this->actingAs($this->operador())->post(route('expedientes.store'), $datos);

        $nuevo = Expediente::query()->where('display_number', '222222/2026')->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $nuevo))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('expediente.displayNumber', '222222/2026')
                ->where('expediente.subject', null));
    }

    private function datos(): array
    {
        return [
            'number' => '0030064-222222/2026-0',
            'subject' => 'Acta acuerdo — García Claudio Adrián c/ CIACSA',
            'receivedDate' => now()->subDay()->toDateString(),
            'employerId' => 101,
            'declaredTotalAmount' => '1204500.00',
            'notes' => 'Antecedente remitido por Mesa de Entradas.',
        ];
    }
}
