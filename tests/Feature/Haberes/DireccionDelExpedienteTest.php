<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La dirección del expediente de Haberes: `/haberes/expedientes/75/0030064-125957-2026-0`.
 *
 * El id identifica y el número completo describe, como en Multas. La forma
 * corta `125957/2026` no va en la dirección: no dice la instancia y no es
 * única.
 */
class DireccionDelExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_direccion_lleva_el_id_y_el_numero_completo(): void
    {
        $expediente = $this->expediente('0030064-222222/2026-0');

        expect(route('expedientes.show', $expediente, absolute: false))
            ->toBe("/haberes/expedientes/{$expediente->id}/0030064-222222-2026-0");

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.routeKey', "{$expediente->id}/0030064-222222-2026-0"));
    }

    public function test_el_id_solo_o_un_numero_viejo_llevan_a_la_direccion_actual(): void
    {
        $expediente = $this->expediente('0030064-222222/2026-0');
        $this->actingAs($this->operador());

        $this->get("/haberes/expedientes/{$expediente->id}")
            ->assertRedirect(route('expedientes.show', $expediente));
        $this->get("/haberes/expedientes/{$expediente->id}/0030064-999999-2020-0")
            ->assertRedirect(route('expedientes.show', $expediente));
        $this->get("/haberes/expedientes/{$expediente->id}/editar")
            ->assertRedirect(route('expedientes.edit', $expediente));
    }

    public function test_la_consulta_no_cambia_el_expediente_al_redirigir(): void
    {
        $este = $this->expediente('0030064-222222/2026-0');
        $otro = $this->expediente('0030064-333333/2026-0');

        // Una clave de la consulta con el nombre del parámetro no pisa a la ruta: viaja como consulta.
        $this->actingAs($this->operador())
            ->get("/haberes/expedientes/{$este->id}?expediente={$otro->id}&pestana=cuotas")
            ->assertRedirect(route('expedientes.show', $este)."?expediente={$otro->id}&pestana=cuotas");
    }

    public function test_las_pantallas_que_cuelgan_del_expediente_llevan_el_numero(): void
    {
        $expediente = $this->expediente('0030064-222222/2026-0');
        $clave = "{$expediente->id}/0030064-222222-2026-0";

        expect(route('expedientes.edit', $expediente, absolute: false))->toBe("/haberes/expedientes/{$clave}/editar")
            ->and(route('expedientes.history', $expediente, absolute: false))->toBe("/haberes/expedientes/{$clave}/historial")
            ->and(route('haberes.haber.create', $expediente, absolute: false))->toBe("/haberes/expedientes/{$clave}/haberes/nuevo");

        $this->actingAs($this->operador())
            ->get(route('expedientes.edit', $expediente))
            ->assertOk();
    }

    public function test_un_numero_suelto_no_es_una_direccion(): void
    {
        $this->actingAs($this->operador())
            ->get('/haberes/expedientes/222222-2026')
            ->assertNotFound();
    }

    public function test_un_expediente_sin_numero_canonico_va_solo_con_su_id(): void
    {
        $expediente = $this->expediente('0030064-222222/2026-0');
        DB::table('expedientes')->where('id', $expediente->id)->update(['canonical_number' => null]);
        $expediente->refresh();

        expect($expediente->getRouteKey())->toBe((string) $expediente->id);

        $this->actingAs($this->operador())
            ->get("/haberes/expedientes/{$expediente->id}")
            ->assertOk();
    }

    private function expediente(string $numero): Expediente
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [...$this->datos(), 'number' => $numero])
            ->assertSessionHasNoErrors();

        return Expediente::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(): array
    {
        return [
            'subject' => 'Acta acuerdo',
            'receivedDate' => BusinessDate::today()->subDay()->toDateString(),
            'employerId' => 101,
        ];
    }
}
