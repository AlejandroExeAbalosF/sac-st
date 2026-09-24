<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Los archivos del sistema: guardados, servidos y nunca reescritos.
 *
 * El DER lo dice sin rodeos: *«Los archivos son inmutables. Cada reenvío
 * de Pase o documento firmado genera otro attachment»*. Corregir un
 * adjunto no es editarlo, es subir el nuevo y que el anterior siga ahí.
 */
class AdjuntosTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_adjunto_no_se_puede_editar(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);

        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => 'otro']);
    }

    public function test_un_adjunto_no_se_puede_borrar(): void
    {
        $this->adjunto();

        $this->expectException(QueryException::class);

        DB::table('attachments')->delete();
    }

    /** Ni siquiera cambiando el archivo al que apunta. */
    public function test_un_adjunto_no_puede_apuntar_a_otro_archivo(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);

        DB::table('attachments')
            ->where('id', $adjunto->id)
            ->update(['object_key' => 'otro/archivo.pdf']);
    }

    public function test_se_descarga_con_su_nombre_original(): void
    {
        $adjunto = $this->adjunto();

        $this->actingAs($this->operador('consulta'))
            ->get(route('adjuntos.download', $adjunto))
            ->assertOk()
            ->assertDownload($adjunto->original_filename);
    }

    /**
     * La vista previa es lo que permite poner la foto al lado del
     * formulario mientras se copian los datos.
     */
    public function test_una_imagen_se_puede_ver_en_pantalla(): void
    {
        $adjunto = $this->adjunto(filename: 'ticket.jpg', mime: 'image/jpeg');

        $respuesta = $this->actingAs($this->operador())
            ->get(route('adjuntos.preview', $adjunto))
            ->assertOk();

        $this->assertStringStartsWith('inline;', (string) $respuesta->headers->get('content-disposition'));
        $this->assertSame('nosniff', $respuesta->headers->get('x-content-type-options'));
    }

    /** Ver un CSV en pantalla no aporta nada: se descarga. */
    public function test_lo_que_no_se_ve_en_pantalla_no_tiene_vista_previa(): void
    {
        $adjunto = $this->adjunto(filename: 'extracto.csv', mime: 'text/csv');

        $this->actingAs($this->operador())
            ->get(route('adjuntos.preview', $adjunto))
            ->assertNotFound();
    }

    /**
     * Un SVG no se abre en pantalla aunque sea `image/`.
     *
     * Es un documento con scripts: abrirlo en el navegador es ejecutarlo
     * con la sesión de quien lo mira. Se descarga, como cualquier archivo
     * que no sea una foto o un PDF.
     */
    public function test_un_svg_no_se_abre_en_pantalla(): void
    {
        $adjunto = $this->adjunto(filename: 'dibujo.svg', mime: 'image/svg+xml');

        $this->actingAs($this->operador())
            ->get(route('adjuntos.preview', $adjunto))
            ->assertNotFound();
    }

    /** Un nombre con tildes o con ñ llega entero al navegador. */
    public function test_la_vista_previa_respeta_los_nombres_con_acentos(): void
    {
        $adjunto = $this->adjunto(filename: 'comprobante-año.webp', mime: 'image/webp');

        $disposicion = (string) $this->actingAs($this->operador())
            ->get(route('adjuntos.preview', $adjunto))
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringStartsWith('inline;', $disposicion);
        $this->assertStringContainsString("filename*=utf-8''comprobante-a%C3%B1o.webp", $disposicion);
    }

    public function test_lo_reservado_exige_su_permiso(): void
    {
        $adjunto = $this->adjunto(confidencialidad: Confidentiality::Restricted);

        $this->actingAs($this->operador('consulta'))
            ->get(route('adjuntos.download', $adjunto))
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->get(route('adjuntos.download', $adjunto))
            ->assertOk();
    }

    public function test_sin_sesion_no_hay_archivos(): void
    {
        $adjunto = $this->adjunto();

        $this->get(route('adjuntos.download', $adjunto))->assertRedirect(route('login'));
    }

    /**
     * El archivo del extracto quedó como adjunto.
     *
     * Antes vivía en `bank_statement_imports.stored_path`, con una nota que
     * decía que se movería cuando `attachments` existiera. Existe.
     */
    public function test_el_extracto_importado_queda_como_adjunto(): void
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertSessionHasNoErrors();

        $import = BankStatementImport::query()->firstOrFail();

        $adjunto = Attachment::query()
            ->forSubject(AttachmentSubject::Import, $import->id)
            ->firstOrFail();

        $this->assertSame('bank_statement', $adjunto->document_type);
        $this->assertSame('macro-online.csv', $adjunto->original_filename);
        // La misma huella que la importación: es el mismo archivo.
        $this->assertSame($import->file_sha256, $adjunto->sha256);
        Storage::disk('local')->assertExists($adjunto->object_key);
    }

    /** Y se va con él cuando la importación se revierte. */
    public function test_revertir_la_importacion_se_lleva_el_archivo(): void
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ]);

        $import = BankStatementImport::query()->firstOrFail();
        $adjunto = Attachment::query()
            ->forSubject(AttachmentSubject::Import, $import->id)
            ->firstOrFail();
        $objectKey = $adjunto->object_key;

        $this->actingAs($this->operador('contador'))
            ->delete(route('banco.extractos.destroy', $import))
            ->assertRedirect();

        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertMissing($objectKey);
    }

    private function adjunto(
        string $filename = 'documento.pdf',
        string $mime = 'application/pdf',
        Confidentiality $confidencialidad = Confidentiality::Internal,
    ): Attachment {
        Storage::disk('local')->put('expediente/'.$filename, 'contenido');

        return Attachment::query()->create([
            'subject_type' => AttachmentSubject::Expediente,
            'subject_id' => 1,
            'document_type' => 'caratula',
            'title' => $filename,
            'storage_disk' => 'local',
            'object_key' => 'expediente/'.$filename,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'contenido'),
            'source' => 'uploaded',
            'confidentiality' => $confidencialidad,
            'created_by' => null,
        ]);
    }
}
