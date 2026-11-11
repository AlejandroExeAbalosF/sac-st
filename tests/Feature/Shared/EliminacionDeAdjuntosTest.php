<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Actions\PurgeAttachment;
use App\Modules\Shared\Actions\VoidAttachment;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\SharedReasonCode;
use App\Modules\Shared\Jobs\DeleteAttachmentFile;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AttachmentFileDeletion;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Support\AttachmentFileRemover;
use App\Modules\Shared\Support\Reason;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * Eliminar del todo un adjunto anulado: la fila y el archivo.
 *
 * Es el segundo paso después de anular, solo del administrador y sin
 * vuelta. El archivo se borra después del commit y, si falla, la tarea
 * queda pendiente y se reintenta.
 */
class EliminacionDeAdjuntosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /* ── La base ─────────────────────────────────────────────────────── */

    /** `sacst_app` no es dueño: borrar solo pasa por `purge_attachment()`. */
    public function test_un_vigente_no_se_elimina_ni_con_la_funcion(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Solo se elimina un adjunto anulado');

        DB::select('SELECT purge_attachment(?, ?)', [$adjunto->id, $this->operador('administrador')->id]);
    }

    /**
     * La tarea de borrado la anota la base a partir de la fila que elimina:
     * nadie manda a borrar el archivo de un adjunto que sigue existiendo.
     */
    public function test_no_se_anota_el_borrado_de_un_archivo_en_uso(): void
    {
        $adjunto = $this->anulado();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('lo usa un adjunto');

        AttachmentFileDeletion::query()->create([
            'attachment_id' => $adjunto->id,
            'disk' => 'local',
            'object_key' => $adjunto->object_key,
            'requested_by' => $this->operador('administrador')->id,
        ]);
    }

    /** Si otro adjunto comparte el archivo, se elimina la fila y el archivo se queda. */
    public function test_un_archivo_compartido_no_se_manda_a_borrar(): void
    {
        $adjunto = $this->anulado();
        $otro = Attachment::query()->create([
            ...collect($adjunto->getAttributes())->only(['subject_type', 'subject_id', 'document_type', 'title', 'storage_disk', 'object_key', 'original_filename', 'mime_type', 'size_bytes', 'sha256', 'source'])->all(),
        ]);

        app(PurgeAttachment::class)->handle($adjunto, $this->motivo(), $this->operador('administrador'));

        $this->assertNull(Attachment::query()->find($adjunto->id));
        $this->assertNotNull($otro->fresh());
        $this->assertSame(0, AttachmentFileDeletion::query()->count());
        Storage::disk('local')->assertExists($adjunto->object_key);
    }

    public function test_una_tarea_de_borrado_solo_anota_sus_intentos(): void
    {
        $tarea = $this->tareaHuerfana();

        DB::table('attachment_file_deletions')->where('id', $tarea->id)->update(['attempts' => 1, 'last_error' => 'sin disco']);
        DB::table('attachment_file_deletions')->where('id', $tarea->id)->update(['attempts' => 2, 'deleted_at' => now()]);

        foreach ([['object_key' => 'otro'], ['attempts' => 1], ['deleted_at' => null], ['kept_at' => now()]] as $cambio) {
            try {
                DB::transaction(fn () => DB::table('attachment_file_deletions')->where('id', $tarea->id)->update($cambio));
                $this->fail('La tarea dejó cambiar '.json_encode($cambio));
            } catch (QueryException $e) {
                $this->assertStringContainsString('solo registra sus intentos', $e->getMessage());
            }
        }

        $this->expectException(QueryException::class);
        DB::table('attachment_file_deletions')->where('id', $tarea->id)->delete();
    }

    /* ── La Action ───────────────────────────────────────────────────── */

    public function test_el_administrador_elimina_un_anulado_y_queda_auditado(): void
    {
        Queue::fake();
        $adjunto = $this->anulado();
        $admin = $this->operador('administrador');

        $this->actingAs($admin)
            ->from('/inicio')
            ->delete(route('adjuntos.purge', $adjunto), ['reasonCode' => 'sensitive_data', 'reason' => 'Nota médica de otra persona'])
            ->assertRedirect('/inicio')
            ->assertSessionHasNoErrors();

        $this->assertNull(Attachment::query()->find($adjunto->id));

        // La anotó la base, a partir de la fila que eliminó.
        $tarea = AttachmentFileDeletion::query()->sole();
        $this->assertSame($adjunto->id, $tarea->attachment_id);
        $this->assertSame($adjunto->object_key, $tarea->object_key);
        $this->assertSame($admin->id, $tarea->requested_by);
        Queue::assertPushed(DeleteAttachmentFile::class, fn (DeleteAttachmentFile $job): bool => $job->deletionId === $tarea->id);

        $evento = AuditEvent::query()->where('action', 'adjunto.eliminado')->sole();
        $this->assertSame($adjunto->id, $evento->subject_id);
        $this->assertSame('sensitive_data', $evento->metadata['motivo_codigo'] ?? null);
        $this->assertSame($adjunto->sha256, $evento->metadata['sha256'] ?? null);
        $this->assertSame($adjunto->original_filename, $evento->metadata['archivo'] ?? null);
        $this->assertSame('Se subió el archivo equivocado', $evento->metadata['anulacion'] ?? null);
    }

    /** El archivo se borra después del commit, y la tarea queda cumplida. */
    public function test_el_archivo_desaparece_del_disco(): void
    {
        $adjunto = $this->anulado();

        app(PurgeAttachment::class)->handle($adjunto, $this->motivo(), $this->operador('administrador'));

        Storage::disk('local')->assertMissing($adjunto->object_key);
        $this->assertNotNull(AttachmentFileDeletion::query()->sole()->deleted_at);
    }

    public function test_la_action_no_elimina_un_vigente(): void
    {
        $adjunto = $this->adjunto();

        try {
            app(PurgeAttachment::class)->handle($adjunto, $this->motivo(), $this->operador('administrador'));
            $this->fail('Se eliminó un vigente.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya anulado', $e->errors()['reasonCode'][0]);
        }

        $this->assertNotNull($adjunto->fresh());
        $this->assertSame(0, AttachmentFileDeletion::query()->count());
    }

    /**
     * `Gate::before` le concede todo al administrador: el estado del archivo
     * es lo que le saca «Eliminar» de un vigente.
     */
    public function test_al_administrador_solo_se_le_ofrece_eliminar_un_anulado(): void
    {
        $admin = $this->operador('administrador');

        $this->assertFalse(AttachmentData::fromModel($this->adjunto(), $admin)->canPurge);
        $this->assertTrue(AttachmentData::fromModel($this->anulado()->load('voider'), $admin)->canPurge);
        $this->assertFalse(AttachmentData::fromModel($this->anulado()->load('voider'), $this->operador('contador'))->canPurge);
    }

    public function test_solo_el_administrador_elimina(): void
    {
        $adjunto = $this->anulado();

        foreach (['contador', 'administrativo', 'consulta'] as $rol) {
            $this->actingAs($this->operador($rol))
                ->delete(route('adjuntos.purge', $adjunto), ['reasonCode' => 'wrong_file'])
                ->assertForbidden();
        }

        $this->assertNotNull($adjunto->fresh());
    }

    /** El permiso no se delega: la matriz de roles no lo puede dar. */
    public function test_eliminar_adjuntos_no_se_delega(): void
    {
        $this->assertContains('adjuntos.eliminar', app(PermissionCatalog::class)->nonDelegable());
    }

    /* ── El borrado del archivo ──────────────────────────────────────── */

    /** Que `delete()` no falle no alcanza: el archivo tiene que no estar. */
    public function test_un_archivo_que_sigue_en_el_disco_deja_la_tarea_pendiente(): void
    {
        $tarea = $this->tareaHuerfana();

        $disco = Mockery::mock(Filesystem::class);
        $disco->shouldReceive('delete')->andReturn(false);
        $disco->shouldReceive('exists')->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disco);

        $this->assertFalse(app(AttachmentFileRemover::class)->process($tarea));

        $pendiente = $tarea->fresh();
        $this->assertNull($pendiente?->deleted_at);
        $this->assertSame(1, $pendiente?->attempts);
        $this->assertNotNull($pendiente?->last_error);
    }

    /**
     * Un archivo que, al procesar la tarea, vuelve a estar en uso se
     * conserva: el borrado nunca confía solo en que la tarea sea correcta.
     */
    public function test_un_archivo_en_uso_al_procesarse_se_conserva(): void
    {
        $tarea = $this->tareaHuerfana();
        Attachment::query()->create([
            'subject_type' => AttachmentSubject::DepositTicket,
            'subject_id' => 2,
            'document_type' => 'deposit_ticket',
            'title' => 'Otro',
            'storage_disk' => 'local',
            'object_key' => $tarea->object_key,
            'original_filename' => 'otro.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'sha256' => hash('sha256', 'otro'),
            'source' => 'scanned',
        ]);

        $this->assertTrue(app(AttachmentFileRemover::class)->process($tarea));

        Storage::disk('local')->assertExists($tarea->object_key);
        $cerrada = $tarea->fresh();
        $this->assertNotNull($cerrada?->kept_at);
        $this->assertNull($cerrada?->deleted_at);
    }

    /** El barrido retoma lo que quedó pendiente si el proceso se cortó. */
    public function test_el_barrido_retoma_los_borrados_pendientes(): void
    {
        $tarea = $this->tareaHuerfana();

        $this->artisan('attachments:sweep-deletions')->assertSuccessful();

        Storage::disk('local')->assertMissing($tarea->object_key);
        $this->assertNotNull($tarea->fresh()?->deleted_at);
    }

    /**
     * Más de un lote: el conjunto de pendientes se achica mientras se
     * recorre, y paginar por desplazamiento salteaba tareas.
     */
    public function test_el_barrido_no_saltea_tareas_de_un_lote_al_otro(): void
    {
        $solicitante = $this->operador('administrador')->id;
        $filas = [];

        for ($i = 0; $i < 1001; $i++) {
            $filas[] = ['attachment_id' => 100000 + $i, 'disk' => 'local', 'object_key' => 'ya-no-esta/'.$i, 'requested_by' => $solicitante];
        }

        foreach (array_chunk($filas, 500) as $lote) {
            DB::table('attachment_file_deletions')->insert($lote);
        }

        $this->artisan('attachments:sweep-deletions')->assertSuccessful();

        $this->assertSame(0, AttachmentFileDeletion::query()->pending()->count());
    }

    /* ── Andamiaje ───────────────────────────────────────────────────── */

    private function motivo(): Reason
    {
        return new Reason(SharedReasonCode::SensitiveData);
    }

    /** Una tarea pendiente cuyo archivo ya no usa ningún adjunto, con el archivo en el disco. */
    private function tareaHuerfana(): AttachmentFileDeletion
    {
        $clave = 'deposit_ticket/huerfano-'.Str::random(8).'.pdf';
        Storage::disk('local')->put($clave, 'contenido');

        return AttachmentFileDeletion::query()->create([
            'attachment_id' => 999999,
            'disk' => 'local',
            'object_key' => $clave,
            'requested_by' => $this->operador('administrador')->id,
        ]);
    }

    private function anulado(): Attachment
    {
        $adjunto = $this->adjunto();
        app(VoidAttachment::class)->handle($adjunto, new Reason(SharedReasonCode::WrongFile), $this->operador());

        return $adjunto->fresh() ?? $adjunto;
    }

    /** La foto de un ticket de depósito: un respaldo opcional. */
    private function adjunto(): Attachment
    {
        $clave = 'deposit_ticket/'.Str::random(12).'.pdf';
        Storage::disk('local')->put($clave, 'contenido');

        return Attachment::query()->create([
            'subject_type' => AttachmentSubject::DepositTicket,
            'subject_id' => 1,
            'document_type' => 'deposit_ticket',
            'title' => 'Comprobante',
            'storage_disk' => 'local',
            'object_key' => $clave,
            'original_filename' => 'comprobante.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 9,
            'sha256' => hash('sha256', $clave),
            'source' => 'scanned',
            'created_by' => null,
        ]);
    }
}
