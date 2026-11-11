<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Shared\Actions\RenameAttachment;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentKind;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\Confidentiality;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Support\Attachments;
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

    /** Lo que identifica el archivo no cambia: solo la descripción se corrige. */
    public function test_un_adjunto_no_se_puede_editar(): void
    {
        $adjunto = $this->adjunto();

        foreach (['mime_type' => 'image/png', 'sha256' => str_repeat('0', 64), 'original_filename' => 'otro.pdf'] as $columna => $valor) {
            try {
                DB::transaction(fn () => DB::table('attachments')->where('id', $adjunto->id)->update([$columna => $valor]));
                $this->fail("La base dejó cambiar {$columna}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('Un adjunto no se edita', $e->getMessage());
            }
        }
    }

    /** Ni siquiera la descripción junto con otra columna. */
    public function test_la_descripcion_no_sirve_de_puerta_para_cambiar_otra_columna(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);

        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => 'otro', 'size_bytes' => 1]);
    }

    public function test_la_base_deja_corregir_la_descripcion_pero_no_vaciarla(): void
    {
        $adjunto = $this->adjunto();

        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => 'Resolución 1234/25, foja 3']);
        $this->assertSame('Resolución 1234/25, foja 3', $adjunto->fresh()?->title);

        $this->expectException(QueryException::class);
        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => '   ']);
    }

    /**
     * Corregir la descripción desde la pantalla deja el título anterior en
     * la auditoría, y el archivo igual.
     */
    public function test_se_corrige_la_descripcion_y_queda_auditada(): void
    {
        $adjunto = $this->adjunto();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->from('/inicio')
            ->patch(route('adjuntos.rename', $adjunto), ['title' => '  Resolución 1234/25  '])
            ->assertRedirect('/inicio')
            ->assertSessionHasNoErrors();

        $corregido = $adjunto->fresh();
        $this->assertSame('Resolución 1234/25', $corregido?->title);
        $this->assertSame($adjunto->object_key, $corregido?->object_key);

        $evento = AuditEvent::query()->where('action', 'adjunto.renombrado')->sole();
        $this->assertSame('Attachment', $evento->subject_type);
        $this->assertSame($adjunto->id, $evento->subject_id);
        $this->assertSame(['title' => $adjunto->title], $evento->old_values);
        $this->assertSame(['title' => 'Resolución 1234/25'], $evento->new_values);
        $this->assertSame($operador->id, $evento->user_id);
    }

    /** El título anterior auditado es el de la base, no el que tenía la pantalla. */
    public function test_la_auditoria_registra_el_titulo_que_de_verdad_se_reemplazo(): void
    {
        $adjunto = $this->adjunto();
        $enPantalla = $adjunto->replicate();
        $enPantalla->id = $adjunto->id;
        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => 'Lo corrigió otra persona']);

        app(RenameAttachment::class)->handle($enPantalla, 'Versión final', $this->operador());

        $this->assertSame(
            ['title' => 'Lo corrigió otra persona'],
            AuditEvent::query()->where('action', 'adjunto.renombrado')->sole()->old_values,
        );
    }

    public function test_sin_permiso_no_se_corrige_la_descripcion(): void
    {
        $adjunto = $this->adjunto();

        $this->actingAs($this->operador('consulta'))
            ->patch(route('adjuntos.rename', $adjunto), ['title' => 'otro'])
            ->assertForbidden();

        $this->assertFalse(AttachmentData::fromModel($adjunto, $this->operador('consulta'))->canRename);
        $this->assertTrue(AttachmentData::fromModel($adjunto, $this->operador())->canRename);
    }

    /** Quien no puede ver un reservado tampoco lo renombra. */
    public function test_un_reservado_no_lo_renombra_quien_no_lo_puede_ver(): void
    {
        $adjunto = $this->adjunto(confidencialidad: Confidentiality::Restricted);

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('adjuntos.rename', $adjunto), ['title' => 'otro'])
            ->assertForbidden();
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

    /** La vista previa responde a la misma regla que la descarga. */
    public function test_lo_reservado_tampoco_se_ve_en_pantalla_sin_permiso(): void
    {
        $adjunto = $this->adjunto(confidencialidad: Confidentiality::Restricted);

        $this->actingAs($this->operador('consulta'))
            ->get(route('adjuntos.preview', $adjunto))
            ->assertForbidden();
    }

    /** Sin `adjuntos.ver` no hay archivo, aunque sea interno. */
    public function test_sin_permiso_de_ver_adjuntos_no_se_abre_ninguno(): void
    {
        $adjunto = $this->adjunto();
        $sinPermisos = User::factory()->create();

        $this->actingAs($sinPermisos)
            ->get(route('adjuntos.preview', $adjunto))
            ->assertForbidden();

        $this->assertFalse(AttachmentData::fromModel($adjunto, $sinPermisos)->canOpen);
    }

    /**
     * Lo que la pantalla recibe dice qué puede pedir.
     *
     * `canOpen` sale de la misma policy que el controlador: si dijera que
     * sí donde el servidor dice que no, la tarjeta pediría una miniatura
     * que vuelve con un 403.
     */
    public function test_la_pantalla_sabe_si_puede_pedir_el_archivo(): void
    {
        $reservado = $this->adjunto(confidencialidad: Confidentiality::Restricted);

        $this->assertFalse(AttachmentData::fromModel($reservado, $this->operador('consulta'))->canOpen);
        $this->assertTrue(AttachmentData::fromModel($reservado, $this->operador('contador'))->canOpen);
        $this->assertFalse(AttachmentData::fromModel($reservado, null)->canOpen);
    }

    /** Foto, hoja de PDF o archivo para descargar, según el tipo guardado. */
    public function test_el_tipo_de_vista_sale_del_mime(): void
    {
        $this->assertSame(AttachmentKind::Image, $this->adjunto('foto.webp', 'image/webp')->kind());
        $this->assertSame(AttachmentKind::Pdf, $this->adjunto('hoja.pdf', 'application/pdf')->kind());
        $this->assertSame(AttachmentKind::Other, $this->adjunto('planilla.csv', 'text/csv')->kind());
        // Un SVG es `image/`, pero no se muestra: es un documento con scripts.
        $this->assertSame(AttachmentKind::Other, $this->adjunto('dibujo.svg', 'image/svg+xml')->kind());
    }

    /**
     * El vigente de cada sujeto es el más reciente, en una sola consulta.
     *
     * Corregir con una foto mejor deja las dos —los adjuntos no se
     * reescriben—, y la que se muestra es la nueva.
     */
    public function test_el_vigente_es_el_mas_reciente_de_cada_sujeto(): void
    {
        $viejo = $this->adjunto('viejo.pdf', sujeto: 1);
        $nuevo = $this->adjunto('nuevo.pdf', sujeto: 1);
        $otro = $this->adjunto('otro.pdf', sujeto: 2);
        $this->adjunto('caratula.pdf', sujeto: 2, tipo: 'otro_papel');

        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $vigentes = app(Attachments::class)->latestFor(
            AttachmentSubject::Expediente,
            [1, 2, 3],
            null,
            documentType: 'caratula',
        );

        $ids = array_map(fn (AttachmentData $a): int => $a->id, $vigentes);
        ksort($ids);

        $this->assertSame([1 => $nuevo->id, 2 => $otro->id], $ids);
        $this->assertNotSame($viejo->id, $vigentes[1]->id);
        // Los adjuntos y sus autores: dos consultas, no una por sujeto.
        $this->assertLessThanOrEqual(2, $consultas);
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
        int $sujeto = 1,
        string $tipo = 'caratula',
    ): Attachment {
        Storage::disk('local')->put('expediente/'.$filename, 'contenido');

        return Attachment::query()->create([
            'subject_type' => AttachmentSubject::Expediente,
            'subject_id' => $sujeto,
            'document_type' => $tipo,
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
