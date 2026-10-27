<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Un documento vinculado por error se desvincula anulando el vínculo: queda a
 * la vista como desvinculado, con el motivo, y el documento sigue en el
 * expediente. Lo que respalda la obligación no se desvincula.
 */
class DesvincularDocumentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_desvincular_un_acta_la_saca_de_la_multa_y_la_deja_a_la_vista(): void
    {
        $multa = $this->multa();
        $acta = $this->acta($multa);

        $this->actingAs($this->operador('contador'))
            ->post($this->anular($multa, $acta, 'origin'), ['reasonCode' => 'wrong_document', 'reason' => 'El acta es de otra empresa'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.show', $multa->showParameters()));

        expect($multa->documents()->whereKey($acta->id)->exists())->toBeFalse()
            ->and($multa->fineCase->documents()->whereKey($acta->id)->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'multa.documento-desvinculado')->sole()->metadata)
            ->toMatchArray(['papel' => 'Origen', 'reason' => 'Documento equivocado: El acta es de otra empresa']);

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->has('multa.documents', 1)
                ->has('multa.revokedDocuments', 1)
                ->where('multa.revokedDocuments.0.documentId', $acta->id)
                ->where('multa.revokedDocuments.0.role', 'origin')
                ->where('multa.revokedDocuments.0.reason', 'Documento equivocado: El acta es de otra empresa')
                ->whereNot('multa.revokedDocuments.0.revokedByName', null));
    }

    public function test_desvincular_pide_el_permiso_de_ajustar_y_un_motivo(): void
    {
        $multa = $this->multa();
        $acta = $this->acta($multa);

        $this->actingAs($this->operador())
            ->post($this->anular($multa, $acta, 'origin'), ['reasonCode' => 'wrong_document', 'reason' => 'El acta es de otra empresa'])
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->post($this->anular($multa, $acta, 'origin'), ['reason' => 'Sin elegir'])
            ->assertSessionHasErrors('reasonCode');

        $this->post($this->anular($multa, $acta, 'origin'), ['reasonCode' => 'other', 'reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->post($this->anular($multa, $acta, 'origin'), ['reasonCode' => 'misread_paper'])
            ->assertSessionHasErrors('reasonCode');

        expect($multa->documents()->whereKey($acta->id)->exists())->toBeTrue();
    }

    public function test_la_resolucion_del_importe_original_no_se_desvincula(): void
    {
        $multa = $this->multa();
        $resolucion = $this->resolucionDeOrigen($multa);

        $this->actingAs($this->operador('contador'))
            ->post($this->anular($multa, $resolucion, 'origin'), ['reasonCode' => 'wrong_document', 'reason' => 'Se cargó la que no era'])
            ->assertSessionHasErrors(['reason' => 'Es el documento del importe original de la multa: no se desvincula. Si se leyó mal, corregí su número o su fecha.']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('respalda un movimiento vigente');

        DB::table('fine_document_links')
            ->where('fine_id', $multa->id)
            ->where('fine_document_id', $resolucion->id)
            ->update(['revoked_at' => now(), 'revocation_reason_code' => 'other', 'revocation_reason' => 'Directo en la base']);
    }

    public function test_el_respaldo_de_un_ajuste_se_desvincula_solo_despues_de_revertirlo(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->post(route('multas.ajustes.store', $multa), [
                'kind' => 'reduction',
                'amount' => '400000.00',
                'effectiveOn' => '2026-06-01',
                'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
                'reasonCode' => 'appeal_admitted',
                'reason' => 'Recurso jerárquico parcialmente admitido',
            ])
            ->assertSessionHasNoErrors();

        $respaldo = FineDocument::query()->where('original_text', 'N°0900/26')->sole();

        $this->post($this->anular($multa, $respaldo, 'modification'), ['reasonCode' => 'data_entry_error'])
            ->assertSessionHasErrors(['reason' => 'Respalda una reducción vigente de la obligación: revertí esa reducción antes de desvincularlo.']);

        $ajuste = FineDebtEntry::query()->where('fine_id', $multa->id)->where('kind', 'reduction')->sole();
        $this->post(route('multas.ajustes.reverse', $ajuste), ['reasonCode' => 'wrong_fine', 'reason' => 'Se cargó en la multa equivocada'])
            ->assertSessionHasNoErrors();

        $this->post($this->anular($multa, $respaldo, 'modification'), ['reasonCode' => 'data_entry_error'])
            ->assertSessionHasNoErrors();
    }

    public function test_un_documento_desvinculado_se_puede_volver_a_vincular(): void
    {
        $multa = $this->multa();
        $acta = $this->acta($multa);

        $this->actingAs($this->operador('contador'))
            ->post($this->anular($multa, $acta, 'origin'), ['reasonCode' => 'data_entry_error', 'reason' => 'Se desvinculó por error'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), ['documentId' => $acta->id, 'role' => 'origin'])
            ->assertSessionHasNoErrors();

        expect($multa->documents()->whereKey($acta->id)->wherePivot('role', 'origin')->count())->toBe(1)
            ->and($multa->revokedDocuments()->whereKey($acta->id)->count())->toBe(1);
    }

    public function test_anulada_la_resolucion_de_origen_entra_la_correcta(): void
    {
        $manual = $this->multa();
        $equivocada = $this->documento($manual, 'resolution', 'N°0700/26');
        $correcta = $this->documento($manual, 'resolution', 'N°0701/26');

        // Una importada sin documento en su importe original: su resolución de origen se puede corregir.
        $multaId = DB::table('fines')->insertGetId([
            'fine_case_id' => $manual->fine_case_id, 'caption_original' => 'HISTÓRICA', 'caption' => 'HISTÓRICA',
            'provenance' => 'import', 'registered_on' => null, 'registered_precision' => 'unknown',
        ]);
        DB::table('fine_document_links')->insert([
            'fine_id' => $multaId, 'fine_case_id' => $manual->fine_case_id, 'fine_document_id' => $equivocada->id, 'role' => 'origin',
        ]);
        DB::table('fine_debt_entries')->insert([
            'fine_id' => $multaId, 'kind' => 'constitution', 'amount' => '1000.00', 'fine_document_id' => null,
            'effective_on' => null, 'effective_precision' => 'unknown', 'provenance' => 'import',
        ]);
        $importada = Fine::query()->findOrFail($multaId);

        $this->actingAs($this->operador('contador'))
            ->post($this->anular($importada, $equivocada, 'origin'), ['reasonCode' => 'wrong_document', 'reason' => 'La resolución era de otro expediente'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $importada), ['documentId' => $correcta->id, 'role' => 'origin'])
            ->assertSessionHasNoErrors();

        expect($importada->documents()->pluck('fine_documents.id')->all())->toBe([$correcta->id]);
    }

    public function test_la_base_solo_admite_anular_el_vinculo(): void
    {
        $multa = $this->multa();
        $acta = $this->acta($multa);
        $vinculo = fn () => DB::table('fine_document_links')
            ->where('fine_id', $multa->id)
            ->where('fine_document_id', $acta->id);

        $this->rechaza('solo se anula', fn () => $vinculo()->update(['role' => 'support']));
        $this->rechaza('no se borra', fn () => $vinculo()->delete());
        $this->rechaza('fine_document_links_revocation_check', fn () => $vinculo()->update(['revoked_at' => now()]));
        $this->rechaza('fine_document_links_revocation_check', fn () => $vinculo()->update(['revoked_at' => now(), 'revocation_reason_code' => 'other']));
        $this->rechaza('fine_document_links_revocation_check', fn () => $vinculo()->update(['revoked_at' => now(), 'revocation_reason_code' => 'appeal_admitted']));

        $vinculo()->update(['revoked_at' => now(), 'revocation_reason_code' => 'wrong_document']);

        $this->rechaza('ya esta anulado', fn () => $vinculo()->update(['revocation_reason' => 'Otro motivo']));
    }

    public function test_un_movimiento_no_cita_un_documento_desvinculado(): void
    {
        $multa = $this->multa();
        $acta = $this->acta($multa);

        DB::table('fine_document_links')
            ->where('fine_id', $multa->id)
            ->where('fine_document_id', $acta->id)
            ->update(['revoked_at' => now(), 'revocation_reason_code' => 'wrong_document']);

        $this->rechaza('tiene el vinculo con la multa anulado', fn () => DB::table('fine_debt_entries')->insert([
            'fine_id' => $multa->id, 'kind' => 'increase', 'amount' => '5.00', 'fine_document_id' => $acta->id,
            'effective_on' => '2026-06-01', 'effective_precision' => 'day',
        ]));
    }

    public function test_un_documento_nuevo_se_vincula_con_su_archivo(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), [
                'role' => 'ratification',
                'document' => ['kind' => 'resolution', 'text' => 'N°0800/26', 'issuedOn' => '2026-07'],
                'file' => UploadedFile::fake()->create('ratificacion.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $documento = FineDocument::query()->where('original_text', 'N°0800/26')->sole();
        $adjunto = Attachment::query()->sole();

        expect($adjunto->subject_type)->toBe(AttachmentSubject::FineDocument)
            ->and($adjunto->subject_id)->toBe($documento->id)
            ->and($multa->documents()->whereKey($documento->id)->wherePivot('role', 'ratification')->exists())->toBeTrue();
    }

    private function anular(Fine $multa, FineDocument $documento, string $papel): string
    {
        return route('multas.documentos.anulacion.store', ['fine' => $multa->id, 'fineDocument' => $documento->id, 'role' => $papel]);
    }

    private function rechaza(string $mensaje, callable $consulta): void
    {
        try {
            DB::transaction(fn () => $consulta());
            $this->fail("La base aceptó lo que debía rechazar: {$mensaje}");
        } catch (QueryException $exception) {
            expect($exception->getMessage())->toContain($mensaje);
        }
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

    private function acta(Fine $multa): FineDocument
    {
        return $multa->documents()->where('kind', 'act')->sole();
    }

    private function resolucionDeOrigen(Fine $multa): FineDocument
    {
        return $multa->documents()->where('kind', 'resolution')->sole();
    }

    private function documento(Fine $multa, string $tipo, string $texto): FineDocument
    {
        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.documentos.store', $multa->fineCase), [
                'document' => ['kind' => $tipo, 'actType' => null, 'text' => $texto, 'issuedOn' => '2026-06-01'],
            ])
            ->assertSessionHasNoErrors();

        return FineDocument::query()->where('original_text', $texto)->sole();
    }
}
