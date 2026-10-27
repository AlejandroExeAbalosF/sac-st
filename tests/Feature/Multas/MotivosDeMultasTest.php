<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El motivo se elige de la lista de cada acto y se guarda como código; el
 * detalle es opcional, salvo con «Otro». La base lo impone en cada tabla.
 */
class MotivosDeMultasTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_ficha_recibe_la_lista_de_cada_acto(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('motivos', FineReasonAct::options())
                ->where('motivos.reversal.0', ['value' => 'data_entry_error', 'label' => 'Error de carga']));
    }

    public function test_se_guarda_el_codigo_y_el_detalle_es_opcional(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->post(route('multas.ajustes.store', $multa), [
                'kind' => 'reduction', 'amount' => '400000.00', 'effectiveOn' => '2026-06-01',
                'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
                'reasonCode' => 'appeal_admitted',
            ])
            ->assertSessionHasNoErrors();

        $ajuste = FineDebtEntry::query()->where('kind', 'reduction')->sole();
        expect($ajuste->reason_code)->toBe(FineReasonCode::AppealAdmitted)
            ->and($ajuste->reason)->toBeNull();

        $this->post(route('multas.ajustes.reverse', $ajuste), ['reasonCode' => 'data_entry_error', 'reason' => 'El importe tenía un cero de más'])
            ->assertSessionHasNoErrors();

        $reversion = FineDebtEntry::query()->where('kind', 'reversal')->sole();
        expect($reversion->reason_code)->toBe(FineReasonCode::DataEntryError)
            ->and($reversion->reason)->toBe('El importe tenía un cero de más')
            ->and(AuditEvent::query()->where('action', 'multa.ajuste-revertido')->sole()->metadata)
            ->toMatchArray(['reason' => 'Error de carga: El importe tenía un cero de más', 'motivo_codigo' => 'data_entry_error']);

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('multa.debtEntries.1.reason', 'Recurso admitido')
                ->where('multa.debtEntries.2.reason', 'Error de carga: El importe tenía un cero de más'));
    }

    public function test_cada_acto_acepta_solo_su_lista_y_otro_pide_el_detalle(): void
    {
        $multa = $this->multa();
        $antecedente = FineHistoricalMovement::query()->create([
            'fine_id' => $multa->id, 'kind' => 'payment', 'amount' => '1.00', 'evidence_text' => 'Excel',
        ]);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.review', $antecedente), ['status' => 'rejected', 'reasonCode' => 'appeal_admitted'])
            ->assertSessionHasErrors('reasonCode');

        $this->post(route('multas.antecedentes.review', $antecedente), ['status' => 'validated', 'reasonCode' => 'no_evidence'])
            ->assertSessionHasErrors('reasonCode');

        $this->post(route('multas.antecedentes.review', $antecedente), ['status' => 'rejected', 'reasonCode' => 'no_evidence'])
            ->assertSessionHasNoErrors();

        expect($antecedente->refresh()->review_reason_code)->toBe(FineReasonCode::NoEvidence)
            ->and($antecedente->review_note)->toBeNull();
    }

    public function test_la_base_exige_el_codigo_de_la_lista_y_el_detalle_con_otro(): void
    {
        $multa = $this->multa();
        $constitucion = FineDebtEntry::query()->where('fine_id', $multa->id)->sole();
        $fila = fn (array $extra): array => [
            'fine_id' => $multa->id, 'kind' => 'reduction', 'amount' => '5.00',
            'effective_on' => '2026-06-01', 'effective_precision' => 'day', ...$extra,
        ];

        $this->rechaza('fine_debt_entries_reason_check', fn () => DB::table('fine_debt_entries')->insert($fila([])));
        $this->rechaza('fine_debt_entries_reason_check', fn () => DB::table('fine_debt_entries')->insert($fila(['reason_code' => 'data_entry_error'])));
        $this->rechaza('fine_debt_entries_reason_check', fn () => DB::table('fine_debt_entries')->insert($fila(['reason_code' => 'other'])));
        $this->rechaza('fine_debt_entries_reason_check', fn () => DB::table('fine_debt_entries')->insert($fila(['reason_code' => 'court_ruling', 'reason' => '   '])));

        $this->rechaza('fine_identifier_revisions_reason_check', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_debt_entry_id' => $constitucion->id, 'kind' => 'correction',
            'old_values' => json_encode(['effective_on' => '2026-05-07']), 'new_values' => json_encode(['effective_on' => '2026-05-08']),
        ]));
        $this->rechaza('fine_identifier_revisions_reason_check', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_debt_entry_id' => $constitucion->id, 'kind' => 'completion', 'reason_code' => 'misread_paper',
            'old_values' => json_encode(['effective_on' => null]), 'new_values' => json_encode(['effective_on' => '2026-05-08']),
        ]));

        DB::table('fine_debt_entries')->insert($fila(['reason_code' => 'court_ruling']));
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

        return Fine::query()->latest('id')->firstOrFail();
    }
}
