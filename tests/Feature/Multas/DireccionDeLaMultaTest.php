<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La ficha de la multa cuelga de su expediente y va por su número dentro de
 * él: `/multas/expedientes/12/169607-2024-0/multa/2`. Ni el id global, que no
 * le dice nada a nadie, ni la carátula, que puede ser el nombre de una
 * persona.
 */
class DireccionDeLaMultaTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_numera_las_multas_dentro_de_cada_expediente(): void
    {
        $primera = $this->multa('169607/24-0');
        $segunda = $this->multa('169607/24-0');
        $otra = $this->multa('120276/25');

        expect([$primera->fine_number, $segunda->fine_number, $otra->fine_number])->toBe([1, 2, 1]);
    }

    public function test_una_carga_directa_tambien_sale_numerada(): void
    {
        $primera = $this->multa('169607/24-0');

        // Como la importación del Excel histórico: sin pasar por el Action.
        $id = DB::table('fines')->insertGetId([
            'fine_case_id' => $primera->fine_case_id, 'caption_original' => 'HISTÓRICA', 'caption' => 'HISTÓRICA',
            'provenance' => 'import', 'registered_on' => null, 'registered_precision' => 'unknown',
        ]);

        expect(Fine::query()->findOrFail($id)->fine_number)->toBe(2);
    }

    public function test_el_numero_no_se_cambia_ni_se_repite(): void
    {
        $primera = $this->multa('169607/24-0');
        $segunda = $this->multa('169607/24-0');

        // En su propia transacción: el error no deja abortada la del test.
        try {
            DB::transaction(fn () => DB::table('fines')->where('id', $segunda->id)->update(['fine_number' => 5]));
            $this->fail('El número de la multa no tenía que poder cambiarse.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('fine_number');
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fines_case_number_unique');

        DB::table('fines')->insert([
            'fine_case_id' => $primera->fine_case_id, 'fine_number' => 1, 'caption_original' => 'X', 'caption' => 'X',
            'provenance' => 'import', 'registered_on' => null, 'registered_precision' => 'unknown',
        ]);
    }

    public function test_la_ficha_cuelga_del_expediente_y_va_por_su_numero(): void
    {
        $this->multa('169607/24-0');
        $segunda = $this->multa('169607/24-0');

        expect(route('multas.show', $segunda->showParameters(), absolute: false))
            ->toBe("/multas/expedientes/{$segunda->fine_case_id}/169607-2024-0/multa/2");

        $this->actingAs($this->operador())
            ->get(route('multas.show', $segunda->showParameters()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('multa.id', $segunda->id));
    }

    public function test_el_numero_de_otro_expediente_no_abre_la_multa(): void
    {
        $this->multa('169607/24-0');
        $segunda = $this->multa('169607/24-0');
        $otra = $this->multa('120276/25');
        $expedienteDeOtra = FineCase::query()->findOrFail($otra->fine_case_id);

        // El 120276/25 tiene una sola multa: su «multa 2» no existe, aunque otro expediente la tenga.
        $this->actingAs($this->operador())
            ->get(route('multas.show', ['fineCase' => $expedienteDeOtra, 'fine' => $segunda->fine_number]))
            ->assertNotFound();
    }

    public function test_la_ficha_se_acomoda_al_numero_actual_del_expediente(): void
    {
        $multa = $this->multa('169607/24-0');

        $this->actingAs($this->operador())
            ->get("/multas/expedientes/{$multa->fine_case_id}/multa/1")
            ->assertRedirect(route('multas.show', $multa->showParameters()));
    }

    public function test_la_lista_enlaza_directo_a_la_ficha(): void
    {
        $multa = $this->multa('169607/24-0');

        $this->actingAs($this->operador())
            ->get(route('multas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('multas.0.href', route('multas.show', $multa->showParameters(), absolute: false)));
    }

    private function multa(string $expediente): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°'.random_int(100, 9999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }
}
