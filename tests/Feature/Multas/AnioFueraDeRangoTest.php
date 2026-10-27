<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\TwoDigitYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El año de dos cifras de un número: el siglo sale de la fecha del papel, y
 * solo se bloquea lo que no tiene ninguna lectura posible.
 *
 * Antes el siglo salía de una regla fija —hasta el año próximo, este siglo—
 * y «12312/29» con fecha de 2026 se leía 1929: la base lo rechazaba con un
 * error. Que el año del número no coincida con la fecha es un aviso en la
 * pantalla, no un rechazo.
 */
class AnioFueraDeRangoTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_siglo_es_el_que_deja_el_anio_mas_cerca_de_la_referencia(): void
    {
        expect(TwoDigitYear::expand(29, 2026))->toBe(2029)
            ->and(TwoDigitYear::expand(98, 2026))->toBe(1998)
            ->and(TwoDigitYear::expand(26, 2026))->toBe(2026)
            ->and(TwoDigitYear::expand(2025, 2026))->toBe(2025)
            // Sin referencia, el pivote de siempre.
            ->and(TwoDigitYear::expand(29))->toBe(1929);
    }

    public function test_la_resolucion_lee_el_siglo_de_su_fecha_y_se_guarda(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta(['resolution' => ['text' => '12312/29', 'issuedOn' => '2026-05-07']]))
            ->assertSessionHasNoErrors();

        expect($this->documento(FineDocumentKind::Resolution, '12312/29')->year)->toBe(2029);
    }

    public function test_un_acta_sin_fecha_lee_el_siglo_de_su_resolucion(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta(['acts' => [['actType' => 'HYS', 'text' => 'N°1025288/98', 'issuedOn' => null]]]))
            ->assertSessionHasNoErrors();

        expect($this->documento(FineDocumentKind::Act, 'N°1025288/98')->year)->toBe(1998);
    }

    public function test_un_anio_de_cuatro_cifras_fuera_de_rango_se_rechaza_en_su_renglon(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta(['acts' => [['actType' => 'HYS', 'text' => 'N°1025288/1850', 'issuedOn' => null]]]))
            ->assertSessionHasErrors(['acts.0.text' => 'El acta queda con el año 1850, y se aceptan años de 1990 a 2100. Revisá el número, o escribí el año con cuatro cifras.']);

        expect(Fine::query()->count())->toBe(0);
    }

    public function test_un_expediente_sin_lectura_posible_se_rechaza_en_el_campo(): void
    {
        // El expediente no tiene fecha: «/29» queda en 1929 y no hay de dónde deducir otro siglo.
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta(['caseNumber' => '169607/29']))
            ->assertSessionHasErrors(['caseNumber' => 'El expediente queda con el año 1929, y se aceptan años de 1990 a 2100. Revisá el número, o escribí el año con cuatro cifras.']);

        expect(FineCase::query()->count())->toBe(0);
    }

    public function test_la_base_sigue_rechazando_el_anio_fuera_de_rango(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('fine_documents_year_check');

        DB::table('fine_documents')->insert([
            'kind' => 'resolution', 'number' => 12312, 'year' => 1929, 'original_text' => '12312/29',
            'issued_precision' => 'unknown', 'provenance' => 'manual',
        ]);
    }

    private function documento(FineDocumentKind $tipo, string $texto): FineDocument
    {
        return FineDocument::query()->where('kind', $tipo)->where('original_text', $texto)->sole();
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function alta(array $cambios): array
    {
        return [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
            ...$cambios,
        ];
    }
}
