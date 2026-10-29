<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineIdentifierRevision;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AdjustsFines;
use Tests\TestCase;

/**
 * La resolución de origen se reemplaza cuando se cargó la equivocada: el
 * importe original pasa a citar la nueva, la vieja queda desvinculada como
 * origen y el importe no cambia.
 */
class ReemplazoDeOrigenTest extends TestCase
{
    use AdjustsFines;
    use RefreshDatabase;

    public function test_reemplazar_por_otra_resolucion_del_expediente(): void
    {
        $multa = $this->multa();
        $vieja = $this->resolucionDeOrigen($multa);
        $nueva = $this->documento($multa, 'resolution', 'N°0700/26', '2026-06-01');

        $this->actingAs($this->operador('administrador'))
            ->post($this->url($multa), ['documentId' => $nueva->id, 'reasonCode' => 'wrong_document', 'reason' => 'Era la de otra empresa'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.show', $multa->showParameters()));

        $importe = FineDebtEntry::query()->where('fine_id', $multa->id)->where('kind', 'constitution')->sole();
        expect($importe->fine_document_id)->toBe($nueva->id)
            ->and($importe->amount)->toBe('1400000.00')
            ->and($importe->effective_on?->toDateString())->toBe('2026-06-01');

        $revision = FineIdentifierRevision::query()->where('fine_debt_entry_id', $importe->id)->sole();
        expect($revision->old_values)->toMatchArray(['fine_document_id' => $vieja->id, 'effective_on' => '2026-05-07'])
            ->and($revision->new_values)->toMatchArray(['fine_document_id' => $nueva->id, 'effective_on' => '2026-06-01'])
            ->and($revision->reason_code?->value)->toBe('wrong_document');

        expect($multa->documents()->where('kind', 'resolution')->wherePivot('role', 'origin')->pluck('fine_documents.id')->all())->toBe([$nueva->id])
            ->and($multa->revokedDocuments()->whereKey($vieja->id)->wherePivot('role', 'origin')->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'multa.resolucion-de-origen-reemplazada')->sole()->metadata)
            ->toMatchArray(['anterior' => 'Resolución 649/2026', 'nueva' => 'Resolución 700/2026', 'motivo_codigo' => 'wrong_document']);
    }

    public function test_reemplazar_por_una_resolucion_nueva_con_su_archivo(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador('administrador'))
            ->post($this->url($multa), [
                'document' => ['text' => 'N°0701/26', 'issuedOn' => '2026-06-02'],
                'file' => UploadedFile::fake()->create('resolucion.pdf', 20, 'application/pdf'),
                'reasonCode' => 'data_entry_error',
            ])
            ->assertSessionHasNoErrors();

        $nueva = FineDocument::query()->where('original_text', 'N°0701/26')->sole();

        expect(FineDebtEntry::query()->where('fine_id', $multa->id)->where('kind', 'constitution')->sole()->fine_document_id)->toBe($nueva->id)
            ->and(Attachment::query()->where('subject_type', AttachmentSubject::FineDocument)->sole()->subject_id)->toBe($nueva->id);
    }

    public function test_la_resolucion_vieja_conserva_sus_otros_papeles(): void
    {
        $multa = $this->multa();
        $vieja = $this->resolucionDeOrigen($multa);
        $nueva = $this->documento($multa, 'resolution', 'N°0700/26', '2026-06-01');

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), ['documentId' => $vieja->id, 'role' => 'ratification'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador('administrador'))
            ->post($this->url($multa), ['documentId' => $nueva->id, 'reasonCode' => 'wrong_document'])
            ->assertSessionHasNoErrors();

        expect($multa->documents()->whereKey($vieja->id)->pluck('fine_document_links.role')->all())->toBe(['ratification']);
    }

    public function test_pide_su_permiso_y_lo_ofrece_solo_a_quien_lo_tiene(): void
    {
        $multa = $this->multa();
        $nueva = $this->documento($multa, 'resolution', 'N°0700/26', '2026-06-01');

        $this->actingAs($this->operador('contador'))
            ->post($this->url($multa), ['documentId' => $nueva->id, 'reasonCode' => 'wrong_document'])
            ->assertForbidden();

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page->where('can.replaceOrigin', false));

        $this->actingAs($this->operador('administrador'))
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.replaceOrigin', true)
                ->has('motivos.origin_replacement', 3));
    }

    public function test_una_multa_cargada_a_mano_pide_la_fecha_exacta_de_la_nueva(): void
    {
        $multa = $this->multa();
        $nueva = $this->documento($multa, 'resolution', 'N°0700/26', '2026-06');
        $admin = $this->operador('administrador');

        $this->actingAs($admin)
            ->post($this->url($multa), ['documentId' => $nueva->id, 'reasonCode' => 'wrong_document'])
            ->assertSessionHasErrors(['documentId' => 'La resolución nueva necesita su fecha exacta: es la fecha del importe original.']);

        $this->post($this->url($multa), ['documentId' => $this->resolucionDeOrigen($multa)->id, 'reasonCode' => 'wrong_document'])
            ->assertSessionHasErrors(['documentId' => 'Elegí una resolución distinta de la de origen actual.']);

        $acta = $multa->documents()->where('kind', 'act')->firstOrFail();
        $this->post($this->url($multa), ['documentId' => $acta->id, 'reasonCode' => 'wrong_document'])
            ->assertSessionHasErrors(['documentId' => 'La multa se origina en una resolución, no en un acta.']);
    }

    /** El documento de un movimiento revisado tiene que quedar vinculado con su papel al cerrar la transacción. */
    public function test_la_base_exige_el_vinculo_del_documento_nuevo(): void
    {
        $multa = $this->multa();
        $vieja = $this->resolucionDeOrigen($multa);
        $nueva = $this->documento($multa, 'resolution', 'N°0700/26', '2026-05-07');
        $importe = FineDebtEntry::query()->where('fine_id', $multa->id)->where('kind', 'constitution')->sole();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sin vincularlo a la multa con el papel origin');

        DB::transaction(function () use ($importe, $vieja, $nueva): void {
            FineIdentifierRevision::query()->create([
                'fine_debt_entry_id' => $importe->id, 'kind' => 'correction',
                'old_values' => ['fine_document_id' => $vieja->id], 'new_values' => ['fine_document_id' => $nueva->id],
                'reason_code' => 'wrong_document',
            ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_solo_el_importe_original_revisa_su_documento(): void
    {
        $multa = $this->multa();
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.ajustes.store', $multa), [
                ...$this->importeAjustado($multa, 'reduction', '400000.00'), 'effectiveOn' => '2026-06-01',
                'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
                'reasonCode' => 'appeal_admitted',
            ])
            ->assertSessionHasNoErrors();

        $ajuste = FineDebtEntry::query()->where('fine_id', $multa->id)->where('kind', 'reduction')->sole();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se revisan');

        FineIdentifierRevision::query()->create([
            'fine_debt_entry_id' => $ajuste->id, 'kind' => 'correction',
            'old_values' => ['fine_document_id' => $ajuste->fine_document_id],
            'new_values' => ['fine_document_id' => $this->resolucionDeOrigen($multa)->id],
            'reason_code' => 'wrong_document',
        ]);
    }

    private function url(Fine $multa): string
    {
        return route('multas.resolucion-de-origen.store', $multa->id);
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

    private function resolucionDeOrigen(Fine $multa): FineDocument
    {
        return $multa->documents()->where('kind', 'resolution')->wherePivot('role', 'origin')->sole();
    }

    private function documento(Fine $multa, string $tipo, string $texto, string $fecha): FineDocument
    {
        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.documentos.store', $multa->fineCase), [
                'document' => ['kind' => $tipo, 'actType' => null, 'text' => $texto, 'issuedOn' => $fecha],
            ])
            ->assertSessionHasNoErrors();

        return FineDocument::query()->where('original_text', $texto)->sole();
    }
}
