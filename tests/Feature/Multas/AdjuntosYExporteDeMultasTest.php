<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Support\FineListQuery;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Support\Excel\SpreadsheetReader;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Los papeles escaneados de Multas y la cuenta corriente en una planilla.
 */
class AdjuntosYExporteDeMultasTest extends TestCase
{
    use RefreshDatabase;

    // ── Adjuntos ────────────────────────────────────────────────────────

    public function test_the_resolution_photo_goes_to_the_case_and_every_fine_shows_it(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.adjuntos.store', $multa->fine_case_id), [
                'file' => UploadedFile::fake()->image('resolucion.jpg'),
                'title' => 'Resolución N°0649/26',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.expedientes.show', $multa->fine_case_id));

        $adjunto = Attachment::query()->sole();
        expect($adjunto->subject_type)->toBe(AttachmentSubject::FineCase)
            ->and($adjunto->subject_id)->toBe($multa->fine_case_id)
            ->and($adjunto->title)->toBe('Resolución N°0649/26');
        Storage::disk($adjunto->storage_disk)->assertExists($adjunto->object_key);

        expect(AuditEvent::query()->where('action', 'expediente-multa.adjunto-agregado')->sole()->metadata)
            // La foto se normaliza a WEBP; el nombre lo dice.
            ->toMatchArray(['titulo' => 'Resolución N°0649/26', 'archivo' => 'resolucion.webp']);

        $consulta = $this->operador('consulta');
        $this->actingAs($consulta)
            ->get(route('multas.expedientes.show', $multa->fine_case_id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('adjuntos', 1)
                ->where('adjuntos.0.title', 'Resolución N°0649/26')
                ->where('adjuntos.0.viewable', true));
        $this->actingAs($consulta)
            ->get(route('multas.show', $multa))
            ->assertInertia(fn (Assert $page) => $page
                ->has('multa.attachments', 0)
                ->has('adjuntosDelExpediente', 1));
    }

    public function test_a_fine_and_a_previous_payment_keep_their_own_files(): void
    {
        $multa = $this->multa();
        $operador = $this->operador();
        $this->actingAs($operador)->post(route('multas.antecedentes.store', $multa), [
            'kind' => 'payment', 'amount' => '280000.00', 'occurredOn' => '2026-05', 'evidence' => 'Recibo de papel 64685',
        ]);
        $antecedente = FineHistoricalMovement::query()->sole();

        $this->actingAs($operador)
            ->post(route('multas.adjuntos.store', $multa), ['file' => UploadedFile::fake()->create('nota.pdf', 20, 'application/pdf')])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.show', $multa));
        $this->actingAs($operador)
            ->post(route('multas.antecedentes.adjuntos.store', $antecedente), ['file' => UploadedFile::fake()->image('recibo.png')])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.show', $multa));

        $this->actingAs($operador)
            ->get(route('multas.show', $multa))
            ->assertInertia(fn (Assert $page) => $page
                ->has('multa.attachments', 1)
                ->has('multa.historicalMovements.0.attachments', 1)
                ->where('multa.historicalMovements.0.attachments.0.originalFilename', 'recibo.webp')
                ->where('multa.attachments.0.createdByName', $operador->name)
                ->where('can.viewAttachments', true));

        // El comprobante del antecedente se audita sobre su multa: es donde se lee.
        $evento = AuditEvent::query()->where('action', 'multa.respaldo-de-antecedente-agregado')->sole();
        expect($evento->subject_type)->toBe('Fine')
            ->and($evento->subject_id)->toBe($multa->id);
    }

    public function test_an_attachment_is_a_scanned_paper_and_needs_the_load_permission(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.adjuntos.store', $multa), ['file' => UploadedFile::fake()->create('planilla.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
            ->assertSessionHasErrors(['file' => 'El archivo puede ser una foto JPG, PNG o WEBP, o un PDF.']);
        $this->actingAs($this->operador())
            ->post(route('multas.adjuntos.store', $multa), [])
            ->assertSessionHasErrors('file');

        $this->actingAs($this->operador('consulta'))
            ->post(route('multas.adjuntos.store', $multa), ['file' => UploadedFile::fake()->image('foto.jpg')])
            ->assertForbidden();
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.expedientes.adjuntos.store', $multa->fine_case_id), ['file' => UploadedFile::fake()->image('foto.jpg')])
            ->assertForbidden();

        expect(Attachment::query()->count())->toBe(0);
    }

    public function test_the_database_only_accepts_known_fine_subjects(): void
    {
        $fila = fn (string $sujeto): array => [
            'subject_type' => $sujeto, 'subject_id' => 1, 'document_type' => 'fine_support', 'source' => 'uploaded',
            'title' => 'x', 'original_filename' => 'x.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'object_key' => "x-{$sujeto}", 'sha256' => str_repeat('a', 64),
        ];

        foreach (['fine_case', 'fine', 'fine_historical_movement', 'fine_payment'] as $sujeto) {
            DB::table('attachments')->insert($fila($sujeto));
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('attachments_subject_type_check');

        DB::table('attachments')->insert($fila('fine_debt_entry'));
    }

    // ── Exporte ─────────────────────────────────────────────────────────

    public function test_the_export_has_the_same_rows_as_the_filtered_list(): void
    {
        $this->multa();
        $this->multa(caratula: 'DISTRIBUIDORA VIDT SRL', expediente: '170001/25');

        $filas = $this->excel($this->operador('consulta'), ['q' => 'cristomax']);

        expect($filas)->toHaveCount(2)
            ->and($filas[0])->toBe([
                'Expediente', 'Carátula', 'Persona', 'Documentos de origen', 'Fecha de registro',
                'Importe vigente', 'Pagado en el sistema', 'Pagado antes del sistema', 'Saldo',
                'Excedente', 'Estado', 'Procedencia',
            ])
            ->and($filas[1][0])->toBe('169607/2024-0')
            ->and($filas[1][1])->toBe('CRISTOMAX S.A.S')
            ->and($filas[1][4])->toBe('07/05/2026')
            ->and($filas[1][5])->toBe('1.400.000,00')
            ->and($filas[1][8])->toBe('1.400.000,00');

        expect($this->excel($this->operador('consulta'), []))->toHaveCount(3);
    }

    public function test_the_export_walks_every_block_in_the_list_order(): void
    {
        $caso = (int) DB::table('fine_cases')->insertGetId(['original_text' => '1/2020', 'number' => 1, 'year' => 2020]);
        foreach ([['2025-03-01', 'month'], [null, 'unknown'], ['2026-01-10', 'day'], [null, 'unknown'], ['2025-03-01', 'month']] as [$fecha, $precision]) {
            $this->multaImportada($caso, $fecha, $precision);
        }

        $filtros = ['q' => '', 'estado' => '', 'anio' => null];
        $enPantalla = app(FineListQuery::class)->query($filtros)->pluck('fines.id')->all();
        $exportadas = collect(iterator_to_array(app(FineListQuery::class)->chunks($filtros, 2), false))
            ->flatten(1)
            ->map(fn (Fine $multa): int => $multa->id)
            ->all();

        expect($exportadas)->toBe($enPantalla)
            ->and($exportadas)->toHaveCount(5)
            // Lo último que se registró, primero; las sin fecha, al final.
            ->and(Fine::query()->find($exportadas[0])?->registered_on?->toDateString())->toBe('2026-01-10')
            ->and(Fine::query()->find($exportadas[4])?->registered_on)->toBeNull();
    }

    public function test_the_export_needs_the_view_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('multas.exportar'))
            ->assertForbidden();
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    private function multa(string $caratula = 'CRISTOMAX S.A.S', string $expediente = '169607/24-0'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°'.random_int(100, 9999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°'.random_int(100000, 999999), 'issuedOn' => null]],
            'caption' => $caratula,
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function multaImportada(int $caso, ?string $fecha, string $precision): void
    {
        $resolucion = DB::table('fine_documents')->insertGetId([
            'kind' => 'resolution', 'number' => random_int(1, 999999), 'year' => 2025,
            'original_text' => 'N°'.random_int(1, 999999).'/25', 'issued_on' => null, 'issued_precision' => 'unknown',
        ]);
        DB::table('fine_case_documents')->insert(['fine_case_id' => $caso, 'fine_document_id' => $resolucion]);

        $multa = DB::table('fines')->insertGetId([
            'fine_case_id' => $caso, 'caption_original' => 'HISTÓRICA', 'caption' => 'HISTÓRICA',
            'provenance' => 'import', 'registered_on' => $fecha, 'registered_precision' => $precision,
        ]);
        DB::table('fine_document_links')->insert([
            'fine_id' => $multa, 'fine_case_id' => $caso, 'fine_document_id' => $resolucion, 'role' => 'origin',
        ]);
        DB::table('fine_debt_entries')->insert([
            'fine_id' => $multa, 'kind' => 'constitution', 'amount' => '1000.00', 'fine_document_id' => $resolucion,
            'effective_on' => null, 'effective_precision' => 'unknown', 'provenance' => 'import',
        ]);
    }

    /**
     * @param  array<string, string>  $filtros
     * @return list<list<string>>
     */
    private function excel(User $usuario, array $filtros): array
    {
        $respuesta = $this->actingAs($usuario)
            ->get(route('multas.exportar', $filtros))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archivo = tempnam(sys_get_temp_dir(), 'multas').'.xlsx';
        file_put_contents($archivo, $respuesta->streamedContent());

        try {
            return app(SpreadsheetReader::class)->spreadsheet($archivo);
        } finally {
            @unlink($archivo);
        }
    }
}
