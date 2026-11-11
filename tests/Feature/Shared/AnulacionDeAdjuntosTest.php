<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Actions\VoidAttachment;
use App\Modules\Shared\Data\AttachmentData;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\SharedReasonCode;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Support\Attachments;
use App\Modules\Shared\Support\Reason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Anular un adjunto opcional: queda guardado, fuera de la vista.
 *
 * Cada regla se prueba en los dos lados: el mensaje legible de la Action y
 * el rechazo de la base, que es la que también frena al administrador.
 */
class AnulacionDeAdjuntosTest extends TestCase
{
    use RefreshDatabase;

    /* ── La base ─────────────────────────────────────────────────────── */

    public function test_un_adjunto_nace_vigente(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('nace vigente');

        $this->adjunto(extra: [
            'voided_at' => now(),
            'voided_by' => $this->operador()->id,
            'void_reason_code' => 'wrong_file',
        ]);
    }

    public function test_la_base_anula_un_respaldo_de_la_lista(): void
    {
        $adjunto = $this->adjunto();

        $this->anularEnLaBase($adjunto);

        $this->assertNotNull($adjunto->fresh()?->voided_at);
    }

    /** La prueba de un hecho no se anula, ni siquiera desde la base. */
    public function test_la_base_no_anula_lo_que_no_esta_en_la_lista(): void
    {
        $ticketDelTraslado = $this->adjunto(AttachmentSubject::CashTransfer, 'cash_transfer_ticket');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se anula');

        $this->anularEnLaBase($ticketDelTraslado);
    }

    public function test_la_base_no_anula_lo_que_genero_el_sistema(): void
    {
        $generado = $this->adjunto(source: 'generated');

        $this->expectException(QueryException::class);

        $this->anularEnLaBase($generado);
    }

    public function test_la_base_no_anula_dos_veces(): void
    {
        $adjunto = $this->adjunto();
        $this->anularEnLaBase($adjunto);

        $this->expectException(QueryException::class);

        $this->anularEnLaBase($adjunto, 'duplicate');
    }

    /** Anular es completar los datos de la anulación y nada más. */
    public function test_anular_no_sirve_de_puerta_para_cambiar_otra_columna(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);

        DB::table('attachments')->where('id', $adjunto->id)->update([
            'voided_at' => now(),
            'voided_by' => $this->operador()->id,
            'void_reason_code' => 'wrong_file',
            'title' => 'otro',
        ]);
    }

    public function test_un_anulado_no_se_renombra(): void
    {
        $adjunto = $this->adjunto();
        $this->anularEnLaBase($adjunto);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('anulado no se renombra');

        DB::table('attachments')->where('id', $adjunto->id)->update(['title' => 'otro']);
    }

    /** Con «Otro», el detalle es el motivo: la base lo exige. */
    public function test_con_otro_hace_falta_el_detalle(): void
    {
        $adjunto = $this->adjunto();

        $this->expectException(QueryException::class);

        $this->anularEnLaBase($adjunto, 'other');
    }

    /** La lista de PHP y la de la base son la misma. */
    public function test_las_dos_listas_de_anulables_coinciden(): void
    {
        $definicion = (string) DB::selectOne(
            "SELECT pg_get_functiondef('attachment_is_voidable(text, text, text)'::regprocedure) AS def",
        )->def;

        preg_match_all("/\\('([a-z_]+)',\\s*'([a-z_]+)'\\)/", $definicion, $pares, PREG_SET_ORDER);
        $enLaBase = array_map(fn (array $par): array => [$par[1], $par[2]], $pares);

        sort($enLaBase);
        $enPhp = Attachment::VOIDABLE;
        sort($enPhp);

        $this->assertSame($enPhp, $enLaBase);
    }

    /* ── La Action ───────────────────────────────────────────────────── */

    public function test_se_anula_desde_la_pantalla_y_queda_auditado(): void
    {
        $adjunto = $this->adjunto();
        $operador = $this->operador();

        $this->actingAs($operador)
            ->from('/inicio')
            ->post(route('adjuntos.void', $adjunto), ['reasonCode' => 'wrong_file', 'reason' => 'Era el del otro expediente'])
            ->assertRedirect('/inicio')
            ->assertSessionHasNoErrors();

        $anulado = $adjunto->fresh();
        $this->assertNotNull($anulado?->voided_at);
        $this->assertSame($operador->id, $anulado?->voided_by);
        $this->assertSame(SharedReasonCode::WrongFile, $anulado?->void_reason_code);

        $evento = AuditEvent::query()->where('action', 'adjunto.anulado')->sole();
        $this->assertSame('Attachment', $evento->subject_type);
        $this->assertSame('wrong_file', $evento->metadata['motivo_codigo'] ?? null);
        $this->assertSame('deposit_ticket #'.$adjunto->subject_id, $evento->metadata['sujeto'] ?? null);

        $dto = AttachmentData::fromModel($anulado->load('voider'), $operador);
        $this->assertNotNull($dto->voided);
        $this->assertSame('Se subió el archivo equivocado: Era el del otro expediente', $dto->voided->reason);
        $this->assertFalse($dto->canVoid);
        $this->assertFalse($dto->canRename);
    }

    /** El mensaje legible, antes del rechazo de la base. */
    public function test_la_action_explica_por_que_no_se_anula(): void
    {
        $ticketDelTraslado = $this->adjunto(AttachmentSubject::CashTransfer, 'cash_transfer_ticket');

        try {
            app(VoidAttachment::class)->handle($ticketDelTraslado, $this->motivo(), $this->operador());
            $this->fail('Se anuló la prueba de un hecho.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('es la prueba de un hecho', $e->errors()['reasonCode'][0]);
        }
    }

    /**
     * Dos personas anulan el mismo archivo con la pantalla vieja: la segunda
     * relee la fila y se encuentra con que ya estaba anulado.
     */
    public function test_dos_anulaciones_del_mismo_archivo_registran_una(): void
    {
        $adjunto = $this->adjunto();
        $enLaOtraPantalla = Attachment::query()->findOrFail($adjunto->id);

        app(VoidAttachment::class)->handle($adjunto, $this->motivo(), $this->operador());

        try {
            app(VoidAttachment::class)->handle($enLaOtraPantalla, $this->motivo(), $this->operador());
            $this->fail('Se anuló dos veces.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya estaba anulado', $e->errors()['reasonCode'][0]);
        }

        $this->assertSame(1, AuditEvent::query()->where('action', 'adjunto.anulado')->count());
    }

    /**
     * `Gate::before` le concede todo al administrador: el estado del archivo
     * es lo que lo frena a él.
     */
    public function test_al_administrador_no_se_le_ofrece_anular_la_prueba_de_un_hecho(): void
    {
        $admin = $this->operador('administrador');

        $this->assertFalse(AttachmentData::fromModel($this->adjunto(AttachmentSubject::CashTransfer, 'cash_transfer_ticket'), $admin)->canVoid);
        $this->assertTrue(AttachmentData::fromModel($this->adjunto(), $admin)->canVoid);
    }

    public function test_sin_permiso_no_se_anula(): void
    {
        $adjunto = $this->adjunto();

        $this->actingAs($this->operador('consulta'))
            ->post(route('adjuntos.void', $adjunto), ['reasonCode' => 'wrong_file'])
            ->assertForbidden();

        $this->assertNull($adjunto->fresh()?->voided_at);
    }

    /* ── Lo vigente y el historial ───────────────────────────────────── */

    /** Al anular el último, vuelve a verse el anterior. */
    public function test_el_vigente_ignora_lo_anulado(): void
    {
        $anterior = $this->adjunto(sujeto: 7);
        $ultimo = $this->adjunto(sujeto: 7);

        $adjuntos = app(Attachments::class);
        $this->assertSame($ultimo->id, $adjuntos->latestOf(AttachmentSubject::DepositTicket, 7, null)?->id);

        app(VoidAttachment::class)->handle($ultimo, $this->motivo(), $this->operador());

        $this->assertSame($anterior->id, $adjuntos->latestOf(AttachmentSubject::DepositTicket, 7, null)?->id);

        $historial = $adjuntos->historyFor(AttachmentSubject::DepositTicket, [7], $this->operador())[7];
        $this->assertSame(2, $historial->total);
        $this->assertSame(1, $historial->voided);
    }

    /** Con el único anulado, el historial es el camino a él. */
    public function test_el_historial_trae_los_anulados_con_su_motivo(): void
    {
        $adjunto = $this->adjunto(sujeto: 9);
        app(VoidAttachment::class)->handle($adjunto, $this->motivo(), $this->operador());

        $this->actingAs($this->operador('consulta'))
            ->getJson(route('adjuntos.history', ['subject' => 'deposit_ticket', 'id' => 9, 'type' => 'deposit_ticket']))
            ->assertOk()
            ->assertJsonPath('0.id', $adjunto->id)
            ->assertJsonPath('0.voided.reason', 'Se subió el archivo equivocado')
            ->assertJsonPath('0.canOpen', true)
            ->assertJsonPath('0.canVoid', false);
    }

    /**
     * Sin `adjuntos.ver` la pantalla no recibe el conteo: ofrecer el botón
     * terminaba en un 403 al abrirlo.
     */
    public function test_a_quien_no_ve_adjuntos_no_se_le_entrega_el_historial(): void
    {
        $this->adjunto(sujeto: 5);
        $adjuntos = app(Attachments::class);

        $this->assertSame([], $adjuntos->historyFor(AttachmentSubject::DepositTicket, [5], User::factory()->create()));
        $this->assertSame([], $adjuntos->historyFor(AttachmentSubject::DepositTicket, [5], null));
        $this->assertSame(1, $adjuntos->historyFor(AttachmentSubject::DepositTicket, [5], $this->operador('consulta'))[5]->total);
    }

    public function test_sin_permiso_de_ver_adjuntos_no_hay_historial(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson(route('adjuntos.history', ['subject' => 'deposit_ticket', 'id' => 9]))
            ->assertForbidden();
    }

    /* ── Andamiaje ───────────────────────────────────────────────────── */

    private function motivo(): Reason
    {
        return new Reason(SharedReasonCode::WrongFile);
    }

    private function anularEnLaBase(Attachment $adjunto, string $codigo = 'wrong_file'): void
    {
        DB::table('attachments')->where('id', $adjunto->id)->update([
            'voided_at' => now(),
            'voided_by' => $this->operador()->id,
            'void_reason_code' => $codigo,
        ]);
    }

    /**
     * Por defecto, la foto de un ticket de depósito: un respaldo opcional.
     *
     * @param  array<string, mixed>  $extra
     */
    private function adjunto(
        AttachmentSubject $subject = AttachmentSubject::DepositTicket,
        string $tipo = 'deposit_ticket',
        string $source = 'scanned',
        int $sujeto = 1,
        array $extra = [],
    ): Attachment {
        $clave = 'pruebas/'.Str::random(12).'.pdf';

        return Attachment::query()->create([
            'subject_type' => $subject,
            'subject_id' => $sujeto,
            'document_type' => $tipo,
            'title' => 'Comprobante',
            'storage_disk' => 'local',
            'object_key' => $clave,
            'original_filename' => 'comprobante.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $clave),
            'source' => $source,
            'created_by' => null,
            ...$extra,
        ]);
    }
}
