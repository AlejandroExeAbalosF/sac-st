<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Models\Person;
use Database\Seeders\PersonaDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Las contrapartes de muestra, que estos tests buscan por id.
     *
     * Las ponía la migración de `people`, sin mirar el entorno. Ahora son
     * un juego de datos que se pide, y este archivo lo pide porque apunta
     * a CIACSA y a García por número.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PersonaDemoSeeder::class);
    }

    public function test_people_can_be_searched_without_typing_accents(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('people.index', [
                'role' => 'beneficiary',
                'q' => 'garcia',
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.id', 201)
            ->assertJsonPath('data.0.name', 'García, Claudio Adrián');
    }

    public function test_the_people_master_is_paginated_without_hiding_its_total(): void
    {
        $totalEsperado = Person::query()->count() + 60;
        $ahora = now();
        $filas = [];

        foreach (range(1, 60) as $numero) {
            $filas[] = [
                'type' => 'company',
                'legal_name' => sprintf('Organización de prueba %03d', $numero),
                'is_active' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
        }

        DB::table('people')->insert($filas);

        $this->actingAs($this->operador())
            ->get(route('personas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('personas/index')
                ->has('personas.data', 50)
                ->where('personas.total', $totalEsperado)
                ->where('personas.last_page', 2));
    }

    public function test_search_respects_the_contextual_role(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('people.index', [
                'role' => 'employer',
                'q' => 'García',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_person_is_normalized_created_and_linked_to_its_role(): void
    {
        $user = $this->operador();

        $response = $this->actingAs($user)->postJson(route('people.store'), [
            'role' => 'beneficiary',
            'type' => 'individual',
            'firstName' => '  María   Elena  ',
            'lastName' => '  Pérez  ',
            'document' => '12.345.678',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('reused', false)
            ->assertJsonPath('data.name', 'Pérez, María Elena')
            ->assertJsonPath('data.document', '12345678');

        $person = Person::query()->where('document', '12345678')->firstOrFail();

        $this->assertSame($user->id, $person->created_by);
        $this->assertDatabaseHas('person_roles', [
            'person_id' => $person->id,
            'role' => 'beneficiary',
        ]);
    }

    public function test_a_beneficiary_can_be_created_with_an_optional_unverified_cbu(): void
    {
        $response = $this->actingAs($this->operador())->postJson(route('people.store'), [
            'role' => 'beneficiary',
            'type' => 'individual',
            'firstName' => 'Lucía',
            'lastName' => 'Pérez',
            'document' => '33444555',
            'cbu' => '2850000300000000000024',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('bankAccount.cbu', '2850000300000000000024')
            ->assertJsonPath('bankAccount.verificationStatus', 'unverified');

        $person = Person::query()->where('document', '33444555')->firstOrFail();

        $this->assertDatabaseHas('person_bank_accounts', [
            'person_id' => $person->id,
            'cbu' => '2850000300000000000024',
            'verification_status' => 'unverified',
            'is_active' => true,
        ]);
    }

    public function test_an_invalid_cbu_does_not_create_the_beneficiary(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Lucía',
                'lastName' => 'Pérez',
                'document' => '33444555',
                'cbu' => '2850000300000000000025',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cbu');

        $this->assertDatabaseMissing('people', ['document' => '33444555']);
        $this->assertDatabaseCount('person_bank_accounts', 0);
    }

    public function test_a_cbu_is_reused_for_the_same_existing_beneficiary(): void
    {
        $payload = [
            'role' => 'beneficiary',
            'type' => 'individual',
            'firstName' => 'Claudio Adrián',
            'lastName' => 'García',
            'document' => '28114902',
            'cbu' => '2850000300000000000024',
        ];

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->operador())
                ->postJson(route('people.store'), $payload)
                ->assertOk()
                ->assertJsonPath('reused', true);
        }

        $this->assertDatabaseCount('person_bank_accounts', 1);
        $this->assertDatabaseHas('person_bank_accounts', [
            'person_id' => 201,
            'cbu' => '2850000300000000000024',
        ]);
    }

    public function test_an_employer_can_not_receive_a_cbu_from_the_contextual_form(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Empresa de prueba SA',
                'document' => '30710442892',
                'cbu' => '2850000300000000000024',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cbu');
    }

    public function test_an_existing_document_is_reused_instead_of_duplicated(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'individual',
                'firstName' => 'Claudio',
                'lastName' => 'García',
                'document' => '28114902',
            ])
            ->assertOk()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('data.id', 201);

        $this->assertDatabaseCount('people', 16);
        $this->assertDatabaseHas('person_roles', [
            'person_id' => 201,
            'role' => 'employer',
        ]);
    }

    public function test_a_company_requires_a_valid_cuit(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Empresa de prueba SA',
                'document' => '20-12345678-0',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_a_beneficiary_can_not_be_an_organization(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'company',
                'legalName' => 'Empresa de prueba SA',
                'document' => '20-12345678-6',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    /**
     * La validación del formulario mira el tipo que se envía, no el de la
     * ficha que se va a reutilizar. Un catálogo importado puede traer una
     * organización con documento corto, y ahí el rango de longitudes deja
     * de separar los tipos por accidente.
     */
    public function test_an_organization_with_a_short_document_can_not_become_a_beneficiary(): void
    {
        Person::query()->create([
            'type' => 'company',
            'legal_name' => 'Cooperativa Vieja',
            'document' => '9876543',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Sea',
                'lastName' => 'Quien',
                'document' => '9876543',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');

        $this->assertDatabaseMissing('person_roles', [
            'role' => 'beneficiary',
            'person_type' => 'company',
        ]);
    }

    /**
     * El nombre de una ficha no puede quedar a medias.
     *
     * `people.name` es una columna generada sobre estas tres, así que una
     * persona física sin apellido dejaría sin nombre a la ficha entera, y
     * con ella al comprobante que se imprima.
     */
    /**
     * El expediente muchas veces trae el CUIL y no el DNI. Se guarda el DNI
     * que tiene adentro: el CUIL no agrega identidad, la duplica, y dos
     * fichas del mismo humano es plata atribuida a quien no corresponde.
     */
    public function test_a_pasted_cuil_is_stored_as_its_document_number(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Claudio Adrián',
                'lastName' => 'García',
                // El CUIL de García, que ya está en el maestro con su DNI.
                'document' => '20-28114902-5',
            ])
            ->assertOk()
            ->assertJsonPath('reused', true)
            ->assertJsonPath('data.id', 201)
            ->assertJsonPath('data.document', '28114902');

        $this->assertDatabaseCount('people', 16);
    }

    /**
     * El prefijo del CUIL —20, 23, 24 o 27— depende del sexo y de las
     * colisiones: no se deduce del DNI. Si el papel lo trajo, se guarda,
     * porque después no se puede reconstruir.
     */
    public function test_a_pasted_cuil_keeps_its_prefix(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Ana María',
                'lastName' => 'Quiroga',
                'document' => '20-33444555-1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.document', '33444555');

        $this->assertDatabaseHas('people', [
            'document' => '33444555',
            'tax_identifier' => '20334445551',
        ]);
    }

    /** Con el DNI solo, no hay prefijo que inventar. */
    public function test_a_plain_document_leaves_the_tax_identifier_empty(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Ana María',
                'lastName' => 'Quiroga',
                'document' => '33444555',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('people', [
            'document' => '33444555',
            'tax_identifier' => null,
        ]);
    }

    /**
     * El expediente nuevo trae el CUIL de alguien que ya estaba cargado con
     * el DNI solo. Es el mismo caso que el domicilio: el papel completa un
     * dato que faltaba.
     */
    public function test_reusing_a_person_fills_in_the_missing_tax_identifier(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Claudio Adrián',
                'lastName' => 'García',
                'document' => '20-28114902-5',
            ])
            ->assertOk()
            ->assertJsonPath('reused', true);

        $this->assertDatabaseHas('people', [
            'id' => 201,
            'document' => '28114902',
            'tax_identifier' => '20281149025',
        ]);
    }

    /** El CUIL no puede pertenecer a otro documento que el de su ficha. */
    public function test_the_database_refuses_a_tax_identifier_from_another_document(): void
    {
        $this->expectException(QueryException::class);

        // El CUIL de otra persona sobre la ficha de García.
        DB::table('people')->where('id', 201)->update([
            'tax_identifier' => '20334445551',
        ]);
    }

    /** Y una organización no lleva CUIL: su CUIT ya es su documento. */
    public function test_the_database_refuses_a_tax_identifier_on_an_organization(): void
    {
        $this->expectException(QueryException::class);

        DB::table('people')->where('id', 101)->update([
            'tax_identifier' => '20281149025',
        ]);
    }

    /**
     * Un CUIL cuyo verificador no cierra no se recorta a ocho dígitos
     * cualquiera: ocho dígitos mal copiados siguen siendo un DNI válido, y
     * ahí el error queda guardado para siempre.
     */
    public function test_a_cuil_with_a_broken_check_digit_is_rejected(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Ana María',
                'lastName' => 'Quiroga',
                'document' => '20299394155',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document' => 'Ese CUIL no es válido: el dígito verificador no cierra. Revisá los dígitos, o cargá directamente el DNI.',
            ]);

        $this->assertDatabaseMissing('people', ['document' => '29939415']);
    }

    /**
     * El verificador solo no alcanza: `20-29939415-9` es aritmética
     * impecable y es el CUIL de un humano, no el CUIT de un organismo.
     */
    public function test_an_organization_can_not_be_registered_with_a_personal_cuil(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Dirección de Rentas',
                'document' => '20299394159',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document' => 'Ese número es el CUIL de una persona física, no el CUIT de una organización. Si el empleador es una persona, cargalo con el tipo «Persona».',
            ]);

        $this->assertDatabaseMissing('people', ['document' => '20299394159']);
    }

    /**
     * El titular es una ficha del maestro, no un nombre copiado: quien está
     * al frente de una empresa puede ser además beneficiario de otro
     * expediente, y dos copias del mismo humano se desfasan en cuanto
     * alguien corrige una.
     *
     * El alta contextual lo identifica por su documento —el operador tiene
     * el papel delante— y usa la ficha que ya existe.
     */
    public function test_an_organization_reuses_an_existing_person_as_its_owner(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Dirección de Rentas',
                'document' => '30710442890',
                // El CUIL de García, que ya está en el maestro.
                'ownerDocument' => '20-28114902-5',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('people', [
            'document' => '30710442890',
            'owner_person_id' => 201,
        ]);

        // Y no se creó una segunda ficha para el mismo humano.
        $this->assertDatabaseCount('people', 17);
    }

    /** Si el titular no está, se registra ahí mismo y nace sin rol. */
    public function test_an_unknown_owner_is_registered_without_a_role(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Dirección de Rentas',
                'document' => '30710442890',
                'ownerDocument' => '17455980',
                'ownerFirstName' => 'Nora Beatriz',
                'ownerLastName' => 'Achad',
            ])
            ->assertCreated();

        $titular = Person::query()->where('document', '17455980')->firstOrFail();

        $this->assertSame('Achad, Nora Beatriz', $titular->name);
        $this->assertDatabaseMissing('person_roles', ['person_id' => $titular->id]);
        $this->assertDatabaseHas('people', [
            'document' => '30710442890',
            'owner_person_id' => $titular->id,
        ]);
    }

    /**
     * Un documento que no está en el maestro necesita el nombre: va a dar
     * de alta una ficha, y una ficha sin nombre no sirve para nada.
     */
    public function test_an_unknown_owner_needs_a_name(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Dirección de Rentas',
                'document' => '30710442890',
                'ownerDocument' => '17455980',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ownerLastName');

        $this->assertDatabaseMissing('people', ['document' => '30710442890']);
    }

    /** Y un nombre sin documento permitiría registrarlo dos veces. */
    public function test_an_owner_name_without_a_document_is_rejected(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Dirección de Rentas',
                'document' => '30710442890',
                'ownerFirstName' => 'Nora Beatriz',
                'ownerLastName' => 'Achad',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ownerDocument');
    }

    /** Una persona física no tiene titular: lo es. */
    public function test_an_individual_can_not_carry_an_owner(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Ana María',
                'lastName' => 'Quiroga',
                'document' => '33444555',
                'ownerDocument' => '28114902',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ownerDocument');
    }

    /**
     * El campo del titular resuelve mientras se tipea, así que contesta con
     * la ficha o con nada, y sabe sacarle el DNI a un CUIL.
     */
    public function test_the_resolver_finds_a_person_by_a_pasted_cuil(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('people.resolve', ['document' => '20-28114902-5']))
            ->assertOk()
            ->assertJsonPath('data.id', 201)
            ->assertJsonPath('documentNumber', '28114902');
    }

    public function test_the_resolver_answers_nothing_for_an_unknown_document(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('people.resolve', ['document' => '17455980']))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Y explica por qué un número largo no sirve, en vez de hablar de dígitos. */
    public function test_the_resolver_explains_a_broken_cuil(): void
    {
        $this->actingAs($this->operador())
            ->getJson(route('people.resolve', ['document' => '20299394155']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document' => 'Ese CUIL no es válido: el dígito verificador no cierra.',
            ]);

        $this->actingAs($this->operador())
            ->getJson(route('people.resolve', ['document' => '30710442890']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'document' => 'Ese es el CUIT de una organización, no el de una persona.',
            ]);
    }

    /**
     * Las dos reglas viven en la base, no solo en el formulario: la FK
     * compuesta contra `(id, type)` obliga a que el titular sea persona
     * física, y el CHECK a que solo una organización lo lleve.
     */
    public function test_the_database_refuses_an_organization_as_owner(): void
    {
        $this->expectException(QueryException::class);

        DB::table('people')->where('id', 101)->update(['owner_person_id' => 102]);
    }

    public function test_the_database_refuses_an_owner_on_an_individual(): void
    {
        $this->expectException(QueryException::class);

        DB::table('people')->where('id', 201)->update(['owner_person_id' => 202]);
    }

    /**
     * El titular se puede dar de alta desde su propio buscador, y nace sin
     * rol: no interviene en el circuito. Por eso no aparece después entre
     * los beneficiarios ni entre los empleadores.
     */
    public function test_a_person_can_be_registered_without_a_role(): void
    {
        $respuesta = $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'type' => 'individual',
                'firstName' => 'Nora Beatriz',
                'lastName' => 'Achad',
                'document' => '17455980',
            ])
            ->assertCreated();

        $id = $respuesta->json('data.id');

        $this->assertDatabaseMissing('person_roles', ['person_id' => $id]);

        $this->actingAs($this->operador())
            ->getJson(route('people.index', ['role' => 'beneficiary', 'q' => 'achad']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Sin rol sí lo encuentra: es como lo busca el campo del titular.
        $this->actingAs($this->operador())
            ->getJson(route('people.index', ['q' => 'achad']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $id);
    }

    public function test_the_database_refuses_an_individual_without_a_surname(): void
    {
        $this->expectException(QueryException::class);

        DB::table('people')->insert([
            'type' => 'individual',
            'first_name' => 'Ana',
            'last_name' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Y al revés: una organización no lleva nombre de pila. */
    public function test_the_database_refuses_an_organization_with_a_given_name(): void
    {
        $this->expectException(QueryException::class);

        DB::table('people')->insert([
            'type' => 'company',
            'legal_name' => 'Panadería La Esquina',
            'first_name' => 'Ana',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_beneficiary_that_is_an_organization(): void
    {
        $this->expectException(QueryException::class);

        // CIACSA, id 101 del catálogo, es una organización.
        DB::table('person_roles')->insert([
            'person_id' => 101,
            'role' => 'beneficiary',
            'person_type' => 'company',
            'created_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_role_that_lies_about_the_type(): void
    {
        $this->expectException(QueryException::class);

        // 201 es persona física; la FK compuesta contra (id, type) impide
        // anotarla como organización para esquivar el CHECK anterior.
        DB::table('person_roles')->insert([
            'person_id' => 201,
            'role' => 'employer',
            'person_type' => 'company',
            'created_at' => now(),
        ]);
    }

    public function test_an_employer_can_be_registered_without_a_document(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'Panadería La Esquina',
                'document' => '',
            ])
            ->assertCreated()
            ->assertJsonPath('data.document', null);

        $this->assertDatabaseHas('people', [
            'name' => 'Panadería La Esquina',
            'document' => null,
        ]);
    }

    public function test_the_same_nameless_employer_is_reused_instead_of_duplicated(): void
    {
        $operador = $this->operador();

        foreach ([1, 2] as $intento) {
            $this->actingAs($operador)
                ->postJson(route('people.store'), [
                    'role' => 'employer',
                    'type' => 'company',
                    'legalName' => 'Panadería La Esquina',
                ])
                ->assertJsonPath('reused', $intento === 2);
        }

        $this->assertDatabaseCount('people', 17);
    }

    /**
     * Sin documento no se puede decidir por el operador: unificarlas le
     * atribuiría plata a quien no corresponde, y crear una segunda deja dos
     * fichas de lo mismo.
     */
    public function test_a_nameless_employer_that_collides_with_a_registered_one_is_rejected(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'employer',
                'type' => 'company',
                'legalName' => 'ciacsa',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('legalName');

        $this->assertDatabaseCount('people', 16);
    }

    public function test_a_beneficiary_still_needs_a_document(): void
    {
        $this->actingAs($this->operador())
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Ana María',
                'lastName' => 'Quiroga',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document');
    }

    public function test_only_some_roles_can_register_people(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->getJson(route('people.index', ['role' => 'beneficiary']))
            ->assertOk();

        $this->actingAs($this->operador('consulta'))
            ->postJson(route('people.store'), [
                'role' => 'beneficiary',
                'type' => 'individual',
                'firstName' => 'Sea',
                'lastName' => 'Quien',
                'document' => '12345678',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('people', ['document' => '12345678']);
    }
}
