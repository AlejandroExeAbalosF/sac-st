<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Database\Seeders\PersonaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Corrección de una ficha del maestro.
 *
 * Existe por una consecuencia concreta de que el documento del empleador
 * sea opcional: alguien cargado hoy con la razón social nada más tiene que
 * poder recibir su CUIT cuando llegue el expediente siguiente.
 */
class EditarPersonaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las contrapartes de muestra, que estos tests corrigen por nombre.
     *
     * Las ponía la migración de `people`, sin mirar el entorno. Ahora son
     * un juego de datos que se pide, y este archivo lo pide porque busca a
     * CIACSA y a García para editarlos.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PersonaDemoSeeder::class);
    }

    public function test_an_employer_without_document_can_receive_one_later(): void
    {
        $persona = Person::query()->create([
            'type' => 'company',
            'legal_name' => 'Panadería La Esquina',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'legalName' => 'Panadería La Esquina SRL',
                'document' => '30-71123456-6',
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'name' => 'Panadería La Esquina SRL',
            'document' => '30711234566',
        ]);
    }

    /** La columna generada se recalcula sola al cambiar el nombre. */
    public function test_the_search_name_follows_the_corrected_name(): void
    {
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'legalName' => 'Compañía Íntegra SA',
                'document' => $persona->document,
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            'compania integra sa',
            Person::query()->whereKey($persona->id)->value('search_name'),
        );
    }

    /**
     * Corregir el apellido de una persona física rehace el nombre completo
     * y con él la columna de búsqueda: las dos las calcula la base sobre
     * las mismas columnas, así que no pueden quedar desfasadas.
     */
    public function test_correcting_a_surname_rebuilds_the_display_name(): void
    {
        $persona = Person::query()->where('document', '28114902')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'firstName' => 'Claudio Adrián',
                'lastName' => 'Garcías',
                'document' => $persona->document,
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'name' => 'Garcías, Claudio Adrián',
            'search_name' => 'garcias, claudio adrian',
        ]);
    }

    /**
     * El titular llega después: el expediente que dio de alta al organismo
     * puede no decir quién está al frente.
     */
    public function test_an_organization_can_receive_its_owner_later(): void
    {
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'legalName' => 'CIACSA',
                'document' => $persona->document,
                'ownerPersonId' => 201,
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'owner_person_id' => 201,
        ]);
    }

    /**
     * Corregir el CUIT no puede convertir a un organismo en una persona:
     * `20-29939415-9` es aritmética impecable y es el CUIL de un humano.
     */
    public function test_an_organization_cuit_can_not_be_corrected_into_a_personal_cuil(): void
    {
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'legalName' => 'CIACSA',
                'document' => '20299394159',
                'isActive' => true,
            ])
            ->assertSessionHasErrors('document');

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'document' => '30710442892',
        ]);
    }

    /**
     * Corregir el apellido no puede costar el CUIL. La pantalla devuelve el
     * número entero, pero aunque volviera solo el DNI el servidor conserva
     * el prefijo mientras el documento no cambie.
     */
    public function test_correcting_a_name_does_not_drop_the_tax_identifier(): void
    {
        $persona = Person::query()->where('document', '28114902')->firstOrFail();
        $persona->forceFill(['tax_identifier' => '20281149025'])->save();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'firstName' => 'Claudio Adrián',
                'lastName' => 'Garcías',
                'document' => '28114902',
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'tax_identifier' => '20281149025',
        ]);
    }

    /** Pero si cambia el documento, ese CUIL ya no es de nadie. */
    public function test_changing_the_document_drops_the_tax_identifier(): void
    {
        $persona = Person::query()->where('document', '28114902')->firstOrFail();
        $persona->forceFill(['tax_identifier' => '20281149025'])->save();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'firstName' => 'Claudio Adrián',
                'lastName' => 'García',
                'document' => '28114903',
                'isActive' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'document' => '28114903',
            'tax_identifier' => null,
        ]);
    }

    public function test_a_document_that_belongs_to_someone_else_is_rejected(): void
    {
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'legalName' => 'CIACSA',
                // El CUIT de Transporte Andino SRL.
                'document' => '30698844120',
                'isActive' => true,
            ])
            ->assertSessionHasErrors('document');
    }

    /**
     * El beneficiario es quien cobra: sin documento no hay a quién pagarle
     * ni con qué emitir el recibo.
     */
    public function test_a_beneficiary_can_not_be_left_without_a_document(): void
    {
        $this->seed(HaberesDemoSeeder::class);

        $persona = Person::query()->where('document', '28114902')->firstOrFail();

        $this->actingAs($this->operador())
            ->patch(route('personas.update', $persona), [
                'firstName' => 'Claudio Adrián',
                'lastName' => 'García',
                'document' => '',
                'isActive' => true,
            ])
            ->assertSessionHasErrors('document');

        $this->assertDatabaseHas('people', [
            'id' => $persona->id,
            'document' => '28114902',
        ]);
    }

    public function test_editing_requires_permission(): void
    {
        $persona = Person::query()->where('name', 'CIACSA')->firstOrFail();

        $this->actingAs($this->operador('consulta'))
            ->patch(route('personas.update', $persona), [
                'legalName' => 'Otro nombre',
                'document' => $persona->document,
                'isActive' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('people', ['id' => $persona->id, 'name' => 'CIACSA']);
    }

    public function test_the_master_page_lists_and_filters(): void
    {
        $this->actingAs($this->operador())
            ->get(route('personas.index', ['q' => 'garcia']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('personas/index')
                ->has('personas.data', 1)
                ->where('personas.data.0.name', 'García, Claudio Adrián')
                ->where('personas.data.0.roles.0', 'beneficiary'));
    }
}
