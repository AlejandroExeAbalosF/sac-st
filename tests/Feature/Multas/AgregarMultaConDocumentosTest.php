<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * «Agregar multa» con documentos fijos desde el «+» de la ficha del
 * expediente: una resolución, la resolución y las actas de un grupo, o un
 * documento sin multa.
 *
 * Solo quedan fijos documentos de ese expediente y del tipo que corresponde,
 * y el alta rechaza la que no los respete.
 */
class AgregarMultaConDocumentosTest extends TestCase
{
    use RefreshDatabase;

    public function test_llegan_marcadas_la_resolucion_y_las_actas_del_grupo(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');
        $acta = $this->documento(FineDocumentKind::Act, 'N°1025288/25');

        $this->actingAs($this->operador())
            ->get(route('multas.expedientes.multas.create', [$multa->fineCase, 'resolucion' => $resolucion->id, 'actas' => [$acta->id]]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('preseleccion.fixed', true)
                ->where('preseleccion.resolutionId', $resolucion->id)
                ->where('preseleccion.actIds', [$acta->id]));
    }

    public function test_se_descarta_lo_que_no_es_del_expediente_o_no_es_del_tipo(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');
        $this->multa('120276/25', 'N°0700/26', 'N°2000/25');
        $resolucionAjena = $this->documento(FineDocumentKind::Resolution, 'N°0700/26');
        $actaAjena = $this->documento(FineDocumentKind::Act, 'N°2000/25');
        $resolucionPropia = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');

        $this->actingAs($this->operador())
            ->get(route('multas.expedientes.multas.create', [
                $multa->fineCase,
                'resolucion' => $resolucionAjena->id,
                // Una resolución pasada como acta, una acta de otro expediente y algo que no es un número.
                'actas' => [$resolucionPropia->id, $actaAjena->id, 'x'],
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // Sin nada válido no hay nada fijo: es el formulario libre.
                ->where('preseleccion.fixed', false)
                ->where('preseleccion.resolutionId', null)
                ->where('preseleccion.actIds', []));
    }

    public function test_sin_consulta_no_llega_nada_marcado(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');

        $this->actingAs($this->operador())
            ->get(route('multas.expedientes.multas.create', $multa->fineCase))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preseleccion.fixed', false)
                ->where('preseleccion.resolutionId', null)
                ->where('preseleccion.actIds', []));

        $this->get(route('multas.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preseleccion.fixed', false)
                ->where('preseleccion.resolutionId', null)
                ->where('preseleccion.actIds', []));
    }

    public function test_sin_resolucion_elegida_ni_escrita_pide_elegirla(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');

        $this->actingAs($this->operador())
            ->post(route('multas.store'), [
                'fineCaseId' => $multa->fine_case_id,
                'acts' => [],
                'caption' => 'OTRA EMPRESA S.A.',
                'registeredOn' => '2026-06-01',
                'amount' => '500000.00',
            ])
            ->assertSessionHasErrors(['resolution.text' => 'Elegí la resolución del expediente o cargá la que no está cargada.']);

        expect(Fine::query()->count())->toBe(1);
    }

    public function test_con_documentos_fijos_se_carga_con_exactamente_esos(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');
        $acta = $this->documento(FineDocumentKind::Act, 'N°1025288/25');
        $fijos = ['resolutionId' => $resolucion->id, 'actIds' => [$acta->id]];

        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta($multa, [
                'fixedDocuments' => $fijos,
                'resolutionId' => $resolucion->id,
                'actIds' => [$acta->id],
            ]))
            ->assertSessionHasNoErrors();

        $nueva = Fine::query()->with('documents')->latest('id')->firstOrFail();
        expect($nueva->documents->pluck('id')->sort()->values()->all())
            ->toBe(collect([$resolucion->id, $acta->id])->sort()->values()->all());
    }

    public function test_con_documentos_fijos_no_se_cambian_ni_se_agregan(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');
        $acta = $this->documento(FineDocumentKind::Act, 'N°1025288/25');
        $fijos = ['resolutionId' => $resolucion->id, 'actIds' => [$acta->id]];
        $this->actingAs($this->operador());

        $intentos = [
            'sin el acta fija' => ['resolutionId' => $resolucion->id, 'actIds' => []],
            'con un acta nueva' => ['resolutionId' => $resolucion->id, 'actIds' => [$acta->id], 'acts' => [['actType' => 'LAB', 'text' => 'N°3000/25', 'issuedOn' => null]]],
            'con otra resolución' => ['resolution' => ['text' => 'N°0800/26', 'issuedOn' => '2026-06-01'], 'actIds' => [$acta->id]],
        ];

        foreach ($intentos as $caso => $cambio) {
            $this->post(route('multas.store'), $this->alta($multa, ['fixedDocuments' => $fijos, ...$cambio]))
                ->assertSessionHasErrors('fixedDocuments', "Tenía que rechazar el alta {$caso}.");
        }

        expect(Fine::query()->count())->toBe(1);
    }

    public function test_con_un_acta_fija_la_resolucion_se_elige_o_se_escribe(): void
    {
        $multa = $this->multa('169607/24-0', 'N°0649/26', 'N°1025288/25');
        $acta = $this->documento(FineDocumentKind::Act, 'N°1025288/25');

        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta($multa, [
                'fixedDocuments' => ['resolutionId' => null, 'actIds' => [$acta->id]],
                'resolution' => ['text' => 'N°0800/26', 'issuedOn' => '2026-06-01'],
                'actIds' => [$acta->id],
            ]))
            ->assertSessionHasNoErrors();

        expect(Fine::query()->count())->toBe(2);
    }

    /**
     * @param  array<string, mixed>  $documentos
     * @return array<string, mixed>
     */
    private function alta(Fine $multa, array $documentos): array
    {
        return [
            'fineCaseId' => $multa->fine_case_id,
            'acts' => [],
            'caption' => 'OTRA EMPRESA S.A.',
            'registeredOn' => '2026-06-01',
            'amount' => '500000.00',
            ...$documentos,
        ];
    }

    private function multa(string $expediente, string $resolucion, string $acta): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => $resolucion, 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => $acta, 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->with('fineCase')->latest('id')->firstOrFail();
    }

    private function documento(FineDocumentKind $tipo, string $texto): FineDocument
    {
        return FineDocument::query()->where('kind', $tipo)->where('original_text', $texto)->sole();
    }
}
