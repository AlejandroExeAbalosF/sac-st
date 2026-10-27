<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Una multa tiene una sola resolución de origen: la que la impone. Las que
 * vienen después la reducen, la revocan o la ratifican, con su propio papel.
 */
class UnaResolucionDeOrigenTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_base_rechaza_una_segunda_resolucion_de_origen(): void
    {
        $multa = $this->multa();
        $otra = $this->documentoDelExpediente($multa, 'resolution', 'N°0800/26');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ya tiene su resolucion de origen');

        DB::table('fine_document_links')->insert([
            'fine_id' => $multa->id, 'fine_case_id' => $multa->fine_case_id,
            'fine_document_id' => $otra->id, 'role' => 'origin',
        ]);
    }

    public function test_la_base_acepta_otra_resolucion_con_otro_papel_y_varias_actas_de_origen(): void
    {
        $multa = $this->multa();
        $ratifica = $this->documentoDelExpediente($multa, 'resolution', 'N°0800/26');
        $acta = $this->documentoDelExpediente($multa, 'act', 'N°2000/25', 'LAB');

        DB::table('fine_document_links')->insert([
            ['fine_id' => $multa->id, 'fine_case_id' => $multa->fine_case_id, 'fine_document_id' => $ratifica->id, 'role' => 'ratification'],
            ['fine_id' => $multa->id, 'fine_case_id' => $multa->fine_case_id, 'fine_document_id' => $acta->id, 'role' => 'origin'],
        ]);

        expect(DB::table('fine_document_links')->where('fine_id', $multa->id)->count())->toBe(4);
    }

    public function test_vincular_otra_resolucion_como_origen_dice_que_hacer(): void
    {
        $multa = $this->multa();
        $otra = $this->documentoDelExpediente($multa, 'resolution', 'N°0800/26');

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), ['documentId' => $otra->id, 'role' => 'origin'])
            ->assertSessionHasErrors(['role' => 'La multa ya tiene su resolución de origen. Una resolución posterior se vincula como ratificación, o se registra como ajuste si cambia el importe.']);

        $this->post(route('multas.documentos.store', $multa), ['documentId' => $otra->id, 'role' => 'ratification'])
            ->assertSessionHasNoErrors();
    }

    public function test_un_acta_se_puede_sumar_como_origen(): void
    {
        $multa = $this->multa();
        $acta = $this->documentoDelExpediente($multa, 'act', 'N°2000/25', 'LAB');

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), ['documentId' => $acta->id, 'role' => 'origin'])
            ->assertSessionHasNoErrors();
    }

    private function multa(): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1025288/25', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->with('fineCase')->latest('id')->firstOrFail();
    }

    private function documentoDelExpediente(Fine $multa, string $tipo, string $texto, ?string $tipoDeActa = null): FineDocument
    {
        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.documentos.store', $multa->fineCase), [
                'document' => ['kind' => $tipo, 'actType' => $tipoDeActa, 'text' => $texto, 'issuedOn' => '2026-06-01'],
            ])
            ->assertSessionHasNoErrors();

        return FineDocument::query()->where('original_text', $texto)->sole();
    }
}
