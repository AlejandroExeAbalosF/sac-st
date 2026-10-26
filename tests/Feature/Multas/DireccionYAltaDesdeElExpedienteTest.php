<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La dirección del expediente: `/multas/expedientes/75/169607-2024-0`.
 *
 * El id identifica, en su propio tramo; el número solo describe. Con el id
 * pegado al número —`169607-2024-75`— no se sabía si 75 era el sufijo o el
 * id, y una corrección del número rompía las direcciones guardadas.
 */
class DireccionYAltaDesdeElExpedienteTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_direccion_lleva_el_id_y_despues_el_numero(): void
    {
        $expediente = $this->expediente('169607/24-0');

        expect(route('multas.expedientes.show', $expediente->routeParameters(), absolute: false))
            ->toBe("/multas/expedientes/{$expediente->id}/169607-2024-0");

        $this->actingAs($this->operador())
            ->get(route('multas.expedientes.show', $expediente->routeParameters()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.id', $expediente->id)
                ->where('expediente.routeNumber', '169607-2024-0'));
    }

    public function test_el_id_solo_o_un_numero_equivocado_llevan_a_la_direccion_actual(): void
    {
        $expediente = $this->expediente('169607/24-0');
        $actual = route('multas.expedientes.show', $expediente->routeParameters());
        $this->actingAs($this->operador());

        $this->get("/multas/expedientes/{$expediente->id}")->assertRedirect($actual);
        $this->get("/multas/expedientes/{$expediente->id}/999999-2020-9")->assertRedirect($actual);
    }

    public function test_el_numero_no_se_confunde_con_el_id(): void
    {
        // A: 169607/2024 sin sufijo. B: el mismo número con el id de A como sufijo.
        $a = $this->expediente('169607/24');
        $b = $this->expediente("169607/24-{$a->id}");
        $this->actingAs($this->operador());

        $this->get("/multas/expedientes/{$b->id}/169607-2024-{$a->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('expediente.id', $b->id));
        $this->get("/multas/expedientes/{$a->id}/169607-2024")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('expediente.id', $a->id));

        // Un número suelto no es una dirección: buscar por número es del buscador.
        $this->get("/multas/expedientes/169607-2024-{$a->id}")->assertNotFound();
    }

    public function test_una_direccion_guardada_sobrevive_a_la_correccion_del_numero(): void
    {
        $expediente = $this->expediente('169607/24-0');
        $vieja = route('multas.expedientes.show', $expediente->routeParameters());

        app(ReviseFineIdentifiers::class)->handle(
            $expediente,
            ['number' => 169607, 'year' => 2024, 'suffix' => '1'],
            ['number' => 169607, 'year' => 2024, 'suffix' => '0'],
            'El sufijo del papel es 1',
            true,
            null,
        );

        $this->actingAs($this->operador())
            ->get($vieja)
            ->assertRedirect(route('multas.expedientes.show', ['fineCase' => $expediente->id, 'numero' => '169607-2024-1']));
    }

    public function test_un_expediente_importado_sin_numero_va_solo_con_su_id(): void
    {
        $id = DB::table('fine_cases')->insertGetId([
            'original_text' => 'EXPTE. SIN NÚMERO', 'provenance' => 'import',
        ]);
        $expediente = FineCase::query()->findOrFail($id);

        expect($expediente->routeNumber())->toBeNull()
            ->and($expediente->routeParameters())->toBe(['fineCase' => $id]);

        $this->actingAs($this->operador())
            ->get("/multas/expedientes/{$id}")
            ->assertOk();
    }

    public function test_agregar_multa_desde_la_ficha_fija_el_expediente(): void
    {
        $expediente = $this->expediente('169607/24-0');

        $this->actingAs($this->operador())
            ->get(route('multas.expedientes.multas.create.numerada', $expediente->routeParameters()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/create')
                ->where('fijo', true)
                ->where('existente.id', $expediente->id));

        $this->post(route('multas.store'), [
            'fineCaseId' => $expediente->id,
            'resolution' => ['text' => 'N°0700/26', 'issuedOn' => '2026-06-01'],
            'acts' => [],
            'caption' => 'OTRA EMPRESA S.A.',
            'registeredOn' => '2026-06-01',
            'amount' => '500000.00',
        ])->assertSessionHasNoErrors();

        expect(Fine::query()->where('fine_case_id', $expediente->id)->count())->toBe(2)
            ->and(FineCase::query()->count())->toBe(1);
    }

    public function test_agregar_multa_tambien_se_acomoda_a_la_direccion_actual(): void
    {
        $expediente = $this->expediente('169607/24-0');
        $this->actingAs($this->operador());

        $this->get("/multas/expedientes/{$expediente->id}/agregar-multa")
            ->assertRedirect(route('multas.expedientes.multas.create.numerada', $expediente->routeParameters()));

        $id = DB::table('fine_cases')->insertGetId([
            'original_text' => 'EXPTE. SIN NÚMERO', 'provenance' => 'import',
        ]);
        $this->get("/multas/expedientes/{$id}/agregar-multa")->assertOk();
    }

    public function test_se_agrega_tambien_a_un_expediente_importado_sin_numero(): void
    {
        $id = DB::table('fine_cases')->insertGetId([
            'original_text' => 'EXPTE. SIN NÚMERO', 'provenance' => 'import',
        ]);

        $this->actingAs($this->operador())->post(route('multas.store'), [
            'fineCaseId' => $id,
            'resolution' => ['text' => 'N°0701/26', 'issuedOn' => '2026-06-01'],
            'acts' => [],
            'caption' => 'EMPRESA IMPORTADA S.A.',
            'registeredOn' => '2026-06-01',
            'amount' => '100000.00',
        ])->assertSessionHasNoErrors();

        expect(Fine::query()->sole()->fine_case_id)->toBe($id);
    }

    public function test_hace_falta_el_numero_o_el_expediente(): void
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'resolution' => ['text' => 'N°0702/26', 'issuedOn' => '2026-06-01'],
            'caption' => 'SIN EXPEDIENTE',
            'registeredOn' => '2026-06-01',
            'amount' => '100000.00',
        ])->assertSessionHasErrors('caseNumber');

        $this->post(route('multas.store'), [
            'fineCaseId' => 999999,
            'resolution' => ['text' => 'N°0703/26', 'issuedOn' => '2026-06-01'],
            'caption' => 'EXPEDIENTE INEXISTENTE',
            'registeredOn' => '2026-06-01',
            'amount' => '100000.00',
        ])->assertSessionHasErrors('fineCaseId');
    }

    public function test_agregar_multa_pide_el_permiso_de_cargar(): void
    {
        $expediente = $this->expediente('169607/24-0');

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.expedientes.multas.create.numerada', $expediente->routeParameters()))
            ->assertForbidden();
    }

    private function expediente(string $numero): FineCase
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $numero,
            'resolution' => ['text' => 'N°'.random_int(100, 9999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return FineCase::query()->latest('id')->firstOrFail();
    }
}
