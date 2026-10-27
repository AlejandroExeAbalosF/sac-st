<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Actions\LinkFineDocument;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Cada papel con su archivo: el del expediente en el expediente, el de la
 * resolución y el de cada acta en ese documento.
 */
class ArchivosDeLosDocumentosTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_alta_deja_cada_archivo_en_su_papel(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta([
                'caseFile' => UploadedFile::fake()->create('caratula.pdf', 20, 'application/pdf'),
                'resolutionFile' => UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf'),
                'acts' => [
                    ['actType' => 'HYS', 'text' => 'N°1025288/25', 'issuedOn' => null, 'file' => UploadedFile::fake()->image('acta-hys.jpg')],
                    ['actType' => 'LAB', 'text' => 'N°2000/25', 'issuedOn' => null],
                ],
            ]))
            ->assertSessionHasNoErrors();

        $multa = Fine::query()->sole();
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');
        $actaHys = $this->documento(FineDocumentKind::Act, 'N°1025288/25');

        expect($this->duenios())->toEqualCanonicalizing([
            ['fine_case', $multa->fine_case_id, 'caratula.pdf'],
            ['fine_document', $resolucion->id, 'resolucion.pdf'],
            ['fine_document', $actaHys->id, 'acta-hys.webp'],
        ]);

        expect(AuditEvent::query()->where('action', 'documento-multa.adjunto-agregado')->count())->toBe(2);

        $this->get(route('multas.expedientes.show', $multa->fineCase))
            ->assertInertia(fn (Assert $page) => $page
                ->has('adjuntos', 1)
                ->where('multas.0.fine.id', $multa->id)
                ->where('multas.0.number', $multa->fine_number)
                ->has('multas.0.documents', 3)
                ->where('multas.0.documents.0.id', $resolucion->id)
                ->where('multas.0.documents.0.roles', ['origin'])
                ->has("adjuntosDeDocumentos.{$resolucion->id}", 1)
                ->has("adjuntosDeDocumentos.{$actaHys->id}", 1));

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where("adjuntosDeDocumentos.{$actaHys->id}.0.originalFilename", 'acta-hys.webp'));
    }

    public function test_la_resolucion_ya_cargada_recibe_su_archivo(): void
    {
        $this->actingAs($this->operador())->post(route('multas.store'), $this->alta())->assertSessionHasNoErrors();
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');

        $datos = $this->alta(['caption' => 'OTRA EMPRESA S.A.', 'acts' => []]);
        unset($datos['resolution']);

        $this->post(route('multas.store'), [
            ...$datos,
            'resolutionId' => $resolucion->id,
            'resolutionFile' => UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        expect($this->duenios())->toBe([['fine_document', $resolucion->id, 'resolucion.pdf']]);
    }

    public function test_las_hojas_conservan_los_roles_de_cada_multa_sin_repetir_documentos(): void
    {
        $this->actingAs($this->operador())->post(route('multas.store'), $this->alta())->assertSessionHasNoErrors();
        $primera = Fine::query()->sole();
        $resolucion = $this->documento(FineDocumentKind::Resolution, 'N°0649/26');
        app(LinkFineDocument::class)->handle($primera, $resolucion, FineDocumentRole::Ratification, null);

        $datos = $this->alta(['caption' => 'OTRA EMPRESA', 'acts' => []]);
        unset($datos['resolution']);
        $this->post(route('multas.store'), [...$datos, 'resolutionId' => $resolucion->id])->assertSessionHasNoErrors();

        $this->get(route('multas.expedientes.show', $primera->fineCase))
            ->assertInertia(fn (Assert $page) => $page
                ->has('multas', 2)
                ->has('multas.0.documents', 2)
                ->where('multas.0.documents.0.id', $resolucion->id)
                ->where('multas.0.documents.0.roles', ['origin', 'ratification'])
                ->has('multas.1.documents', 1)
                ->where('multas.1.documents.0.id', $resolucion->id)
                ->where('multas.1.documents.0.roles', ['origin']));
    }

    public function test_un_archivo_que_no_es_un_papel_frena_el_alta_entera(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta([
                'acts' => [[
                    'actType' => 'HYS',
                    'text' => 'N°1025288/25',
                    'issuedOn' => null,
                    'file' => UploadedFile::fake()->create('acta.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                ]],
            ]))
            ->assertSessionHasErrors(['acts.0.file' => 'El archivo del acta puede ser una foto JPG, PNG o WEBP, o un PDF.']);

        expect(Fine::query()->count())->toBe(0)
            ->and(Attachment::query()->count())->toBe(0);
    }

    public function test_si_el_alta_vuelve_atras_no_quedan_archivos_en_el_disco(): void
    {
        // El último archivo falla en la base, después de que los otros dos ya se escribieron.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_rechaza_acta() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'acta rechazada por el test';
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_rechaza_acta BEFORE INSERT ON attachments
                FOR EACH ROW WHEN (NEW.original_filename LIKE 'acta%')
                EXECUTE FUNCTION test_rechaza_acta();
            SQL);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->operador())->post(route('multas.store'), $this->alta([
                'caseFile' => UploadedFile::fake()->create('caratula.pdf', 20, 'application/pdf'),
                'resolutionFile' => UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf'),
                'acts' => [['actType' => 'HYS', 'text' => 'N°1025288/25', 'issuedOn' => null, 'file' => UploadedFile::fake()->create('acta.pdf', 20, 'application/pdf')]],
            ]));
            $this->fail('El alta tenía que fallar.');
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain('acta rechazada por el test');
        }

        expect(Fine::query()->count())->toBe(0)
            ->and(Attachment::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    }

    public function test_se_adjunta_despues_al_documento_y_hace_falta_el_permiso_de_cargar(): void
    {
        $this->actingAs($this->operador())->post(route('multas.store'), $this->alta())->assertSessionHasNoErrors();
        $acta = $this->documento(FineDocumentKind::Act, 'N°1025288/25');
        $expediente = Fine::query()->sole()->fineCase;

        $this->actingAs($this->operador('consulta'))
            ->post(route('multas.documentos.adjuntos.store', $acta), ['file' => UploadedFile::fake()->image('acta.jpg')])
            ->assertForbidden();

        $this->actingAs($this->operador())
            ->from(route('multas.expedientes.show', $expediente))
            ->post(route('multas.documentos.adjuntos.store', $acta), ['file' => UploadedFile::fake()->image('acta.jpg'), 'title' => 'Foja 2'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.expedientes.show', $expediente));

        $adjunto = Attachment::query()->sole();
        expect($adjunto->subject_type)->toBe(AttachmentSubject::FineDocument)
            ->and($adjunto->subject_id)->toBe($acta->id)
            ->and($adjunto->title)->toBe('Foja 2');

        $evento = AuditEvent::query()->where('action', 'documento-multa.adjunto-agregado')->sole();
        expect($evento->subject_type)->toBe('FineDocument')
            ->and($evento->subject_id)->toBe($acta->id);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function alta(array $cambios = []): array
    {
        return [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1025288/25', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
            ...$cambios,
        ];
    }

    private function documento(FineDocumentKind $tipo, string $texto): FineDocument
    {
        return FineDocument::query()->where('kind', $tipo)->where('original_text', $texto)->sole();
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    private function duenios(): array
    {
        return array_values(Attachment::query()->orderBy('id')->get()
            ->map(fn (Attachment $a): array => [$a->subject_type->value, (int) $a->subject_id, (string) $a->original_filename])
            ->all());
    }
}
