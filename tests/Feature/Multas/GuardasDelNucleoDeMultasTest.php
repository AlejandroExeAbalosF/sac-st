<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lo que la base impide en el núcleo de Multas, sin pasar por PHP.
 *
 * Cada caso escribe directo en las tablas: si un Action se olvidara de una
 * regla, la base tiene que frenarlo igual (regla 1 del proyecto). Los
 * mensajes legibles de los Actions se prueban en los tests de pantalla.
 */
class GuardasDelNucleoDeMultasTest extends TestCase
{
    use RefreshDatabase;

    // ── Expediente ──────────────────────────────────────────────────────

    public function test_a_manual_case_knows_its_number_and_year(): void
    {
        $this->rechaza('fine_cases_manual_identity_check', fn () => DB::table('fine_cases')->insert([
            'original_text' => '169607', 'number' => 169607, 'year' => null, 'provenance' => 'manual',
        ]));
    }

    public function test_an_imported_case_may_arrive_without_number(): void
    {
        DB::table('fine_cases')->insert(['original_text' => 'S/N', 'provenance' => 'import']);

        expect(DB::table('fine_cases')->count())->toBe(1);
    }

    public function test_the_same_identity_can_not_be_loaded_twice(): void
    {
        $this->expediente('169607/24-0', 169607, 2024, '0');

        $this->rechaza('fine_cases_identity_unique', fn () => $this->expediente('169607/2024-0', 169607, 2024, '0'));
    }

    public function test_a_missing_suffix_is_not_the_same_as_zero(): void
    {
        $this->expediente('120276/25', 120276, 2025, null);
        $this->expediente('120276/25-0', 120276, 2025, '0');

        $this->rechaza('fine_cases_identity_unique', fn () => $this->expediente('120276/2025', 120276, 2025, null));
    }

    // ── Documentos y vínculos ───────────────────────────────────────────

    public function test_a_manual_act_says_its_type_but_an_imported_one_may_not(): void
    {
        $this->rechaza('fine_documents_manual_act_type_check', fn () => DB::table('fine_documents')->insert([
            'kind' => 'act', 'number' => 1, 'original_text' => 'N°1', 'provenance' => 'manual',
        ]));

        DB::table('fine_documents')->insert([
            'kind' => 'act', 'number' => 1, 'original_text' => 'N°1', 'provenance' => 'import',
        ]);

        expect(DB::table('fine_documents')->count())->toBe(1);
    }

    public function test_only_an_act_has_an_act_type(): void
    {
        $this->rechaza('fine_documents_act_type_kind_check', fn () => DB::table('fine_documents')->insert([
            'kind' => 'resolution', 'act_type' => 'LAB', 'number' => 649, 'year' => 2026, 'original_text' => 'N°0649/26',
        ]));
    }

    public function test_a_fine_can_not_cite_a_document_of_another_case(): void
    {
        $caso = $this->expediente('1/24', 1, 2024, null);
        $otro = $this->expediente('2/24', 2, 2024, null);
        $resolucionAjena = $this->resolucion($otro, 99, 2026);

        $multa = $this->multaCompleta($caso, '100000.00');

        $this->rechaza('fine_document_links_case_document_fk', fn () => DB::table('fine_document_links')->insert([
            'fine_id' => $multa, 'fine_case_id' => $caso, 'fine_document_id' => $resolucionAjena, 'role' => 'support',
        ]));
    }

    // ── Multa y obligación ──────────────────────────────────────────────

    public function test_a_fine_confirms_only_with_its_original_amount(): void
    {
        $caso = $this->expediente('3/24', 3, 2024, null);

        $this->rechaza('no tiene importe original', function () use ($caso): void {
            DB::table('fines')->insert($this->datosDeMulta($caso));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
    }

    public function test_a_manual_fine_is_registered_on_a_known_day(): void
    {
        $caso = $this->expediente('4/24', 4, 2024, null);

        $this->rechaza('fines_manual_registered_check', fn () => DB::table('fines')->insert([
            ...$this->datosDeMulta($caso), 'registered_on' => '2026-05-01', 'registered_precision' => 'month',
        ]));
    }

    public function test_a_month_is_stored_as_its_first_day(): void
    {
        $caso = $this->expediente('5/24', 5, 2024, null);

        $this->rechaza('fines_registered_on_month_check', fn () => DB::table('fines')->insert([
            ...$this->datosDeMulta($caso),
            'provenance' => 'import', 'registered_on' => '2026-05-14', 'registered_precision' => 'month',
        ]));
    }

    public function test_a_manual_constitution_cites_its_resolution(): void
    {
        $caso = $this->expediente('6/24', 6, 2024, null);

        $this->rechaza('nace citando la resolucion', function () use ($caso): void {
            $multa = DB::table('fines')->insertGetId($this->datosDeMulta($caso));
            DB::table('fine_debt_entries')->insert([
                'fine_id' => $multa, 'kind' => 'constitution', 'amount' => '1000.00',
                'effective_on' => '2026-05-07', 'effective_precision' => 'day',
            ]);
        });
    }

    public function test_one_constitution_per_fine(): void
    {
        $caso = $this->expediente('7/24', 7, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $resolucion = (int) DB::table('fine_document_links')->where('fine_id', $multa)->value('fine_document_id');

        $this->rechaza('fine_debt_entries_one_constitution', fn () => DB::table('fine_debt_entries')->insert([
            'fine_id' => $multa, 'kind' => 'constitution', 'amount' => '5.00', 'fine_document_id' => $resolucion,
            'effective_on' => '2026-05-07', 'effective_precision' => 'day',
        ]));
    }

    public function test_the_obligation_never_goes_negative(): void
    {
        $caso = $this->expediente('8/24', 8, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');

        $this->rechaza('no puede ser negativa', fn () => $this->movimiento($multa, 'reduction', '1000.01', 'Recurso'));

        $this->movimiento($multa, 'reduction', '1000.00', 'Recurso que la deja sin efecto');

        expect((string) DB::table('fine_balances')->where('fine_id', $multa)->value('obligation'))->toBe('0.00');
    }

    public function test_a_reversal_undoes_the_whole_entry(): void
    {
        $caso = $this->expediente('9/24', 9, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $reduccion = $this->movimiento($multa, 'reduction', '400.00', 'Recurso');

        $this->rechaza('deshace el movimiento entero', fn () => $this->movimiento($multa, 'reversal', '100.00', 'Error', $reduccion));

        $this->movimiento($multa, 'reversal', '400.00', 'Se cargó en la multa equivocada', $reduccion);

        expect((string) DB::table('fine_balances')->where('fine_id', $multa)->value('obligation'))->toBe('1000.00');
    }

    public function test_the_constitution_and_reversals_are_not_reversed(): void
    {
        $caso = $this->expediente('10/24', 10, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $constitucion = (int) DB::table('fine_debt_entries')->where('fine_id', $multa)->value('id');

        $this->rechaza('no se revierte', fn () => $this->movimiento($multa, 'reversal', '1000.00', 'Anular', $constitucion));

        $aumento = $this->movimiento($multa, 'increase', '50.00', 'Nueva resolución');
        $reversion = $this->movimiento($multa, 'reversal', '50.00', 'Error', $aumento);

        $this->rechaza('no se revierte', fn () => $this->movimiento($multa, 'reversal', '50.00', 'Otra vez', $reversion));
    }

    public function test_a_reversal_belongs_to_the_same_fine(): void
    {
        $caso = $this->expediente('11/24', 11, 2024, null);
        $una = $this->multaCompleta($caso, '1000.00');
        $otra = $this->multaCompleta($caso, '2000.00');
        $aumento = $this->movimiento($una, 'increase', '10.00', 'Nueva resolución');

        $this->rechaza('de la misma multa', fn () => $this->movimiento($otra, 'reversal', '10.00', 'Error', $aumento));
    }

    public function test_debt_entries_are_append_only(): void
    {
        $caso = $this->expediente('12/24', 12, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');

        $this->rechaza('no se edita', fn () => DB::table('fine_debt_entries')->where('fine_id', $multa)->update(['amount' => '1.00']));
    }

    // ── Revisiones ──────────────────────────────────────────────────────

    public function test_a_normalized_field_is_not_edited_directly(): void
    {
        $caso = $this->expediente('13/24', 13, 2024, null);

        $this->rechaza('se completa o corrige con una revision', fn () => DB::table('fine_cases')->where('id', $caso)->update(['number' => 14]));
    }

    public function test_the_original_text_never_changes(): void
    {
        $caso = $this->expediente('15/24', 15, 2024, null);

        $this->rechaza('no se modifica', fn () => DB::table('fine_cases')->where('id', $caso)->update(['original_text' => '16/24']));
    }

    public function test_a_correction_applies_itself_and_keeps_the_original(): void
    {
        $caso = $this->expediente('17/24', 17, 2024, null);

        DB::table('fine_identifier_revisions')->insert([
            'fine_case_id' => $caso, 'kind' => 'correction',
            'old_values' => json_encode(['number' => 17]), 'new_values' => json_encode(['number' => 71]),
            'reason_code' => 'data_entry_error', 'reason' => 'El número estaba invertido',
        ]);

        $fila = DB::table('fine_cases')->find($caso);
        expect($fila->number)->toBe(71)
            ->and($fila->original_text)->toBe('17/24');
    }

    public function test_a_stale_revision_does_not_overwrite_a_newer_one(): void
    {
        $caso = $this->expediente('18/24', 18, 2024, null);

        $this->rechaza('cambio desde que se abrio el formulario', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_case_id' => $caso, 'kind' => 'correction',
            'old_values' => json_encode(['number' => 99]), 'new_values' => json_encode(['number' => 81]),
            'reason_code' => 'other', 'reason' => 'Otro operador',
        ]));
    }

    public function test_completing_only_fills_blanks(): void
    {
        $caso = $this->expediente('19/24', 19, 2024, null);

        $this->rechaza('ya tenia valor', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_case_id' => $caso, 'kind' => 'completion',
            'old_values' => json_encode(['number' => 19]), 'new_values' => json_encode(['number' => 91]),
        ]));

        DB::table('fine_identifier_revisions')->insert([
            'fine_case_id' => $caso, 'kind' => 'completion',
            'old_values' => json_encode(['suffix' => null]), 'new_values' => json_encode(['suffix' => '0']),
        ]);

        expect(DB::table('fine_cases')->where('id', $caso)->value('suffix'))->toBe('0');
    }

    public function test_a_correction_says_why(): void
    {
        $caso = $this->expediente('20/24', 20, 2024, null);

        $this->rechaza('fine_identifier_revisions_reason_check', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_case_id' => $caso, 'kind' => 'correction',
            'old_values' => json_encode(['number' => 20]), 'new_values' => json_encode(['number' => 21]),
        ]));
    }

    public function test_completing_a_date_refines_it_within_the_same_month(): void
    {
        $caso = $this->expediente('21/24', 21, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00', importada: true);

        DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'completion',
            'old_values' => json_encode(['registered_on' => '2026-05-01', 'registered_precision' => 'month']),
            'new_values' => json_encode(['registered_on' => '2026-05-07', 'registered_precision' => 'day']),
        ]);

        expect(DB::table('fines')->where('id', $multa)->value('registered_on'))->toBe('2026-05-07');

        $this->rechaza('Mover o volver menos precisa', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'completion',
            'old_values' => json_encode(['registered_on' => '2026-05-07', 'registered_precision' => 'day']),
            'new_values' => json_encode(['registered_on' => '2026-06-01', 'registered_precision' => 'month']),
        ]));
    }

    public function test_a_date_is_revised_together_with_its_precision(): void
    {
        $caso = $this->expediente('22/24', 22, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00', importada: true);

        $this->rechaza('junto con su precision', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'correction', 'reason_code' => 'other', 'reason' => 'x',
            'old_values' => json_encode(['registered_on' => '2026-05-01']),
            'new_values' => json_encode(['registered_on' => '2026-06-01']),
        ]));
    }

    // ── Antecedentes ────────────────────────────────────────────────────

    public function test_a_validated_antecedent_lowers_the_debt_without_touching_the_books(): void
    {
        $caso = $this->expediente('23/24', 23, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $asientos = DB::table('financial_events')->count();

        $id = DB::table('fine_historical_movements')->insertGetId([
            'fine_id' => $multa, 'kind' => 'payment', 'amount' => '300.00',
            'occurred_on' => '2025-07-01', 'occurred_precision' => 'month', 'evidence_text' => 'Excel 2025, julio',
        ]);

        expect((string) DB::table('fine_balances')->where('fine_id', $multa)->value('debt'))->toBe('1000.00');

        DB::table('fine_historical_movements')->where('id', $id)->update(['status' => 'validated', 'reviewed_at' => now()]);

        $saldo = DB::table('fine_balances')->where('fine_id', $multa)->first();
        expect((string) $saldo->debt)->toBe('700.00')
            ->and((string) $saldo->historical_paid)->toBe('300.00')
            ->and(DB::table('financial_events')->count())->toBe($asientos);
    }

    public function test_an_antecedent_is_reviewed_once_and_keeps_its_data(): void
    {
        $caso = $this->expediente('24/24', 24, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $id = DB::table('fine_historical_movements')->insertGetId([
            'fine_id' => $multa, 'kind' => 'payment', 'amount' => '300.00', 'evidence_text' => 'Recibo de papel 64685',
        ]);

        $this->rechaza('no modifica sus datos', fn () => DB::table('fine_historical_movements')->where('id', $id)
            ->update(['status' => 'validated', 'reviewed_at' => now(), 'amount' => '3000.00']));

        DB::table('fine_historical_movements')->where('id', $id)
            ->update(['status' => 'rejected', 'reviewed_at' => now(), 'review_reason_code' => 'wrong_fine', 'review_note' => 'Es de otra multa']);

        $this->rechaza('ya se reviso', fn () => DB::table('fine_historical_movements')->where('id', $id)
            ->update(['status' => 'validated']));
    }

    public function test_the_historical_excess_is_not_operational(): void
    {
        $caso = $this->expediente('25/24', 25, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        DB::table('fine_historical_movements')->insert([
            'fine_id' => $multa, 'kind' => 'payment', 'amount' => '800.00', 'evidence_text' => 'Excel',
            'status' => 'validated', 'reviewed_at' => now(),
        ]);
        $this->movimiento($multa, 'reduction', '400.00', 'Recurso');

        $saldo = DB::table('fine_balances')->where('fine_id', $multa)->first();
        expect((string) $saldo->debt)->toBe('0.00')
            ->and((string) $saldo->excess)->toBe('200.00')
            ->and((string) $saldo->operational_excess)->toBe('0.00');
    }

    // ── Revisión del 2026-10-20 ─────────────────────────────────────────

    public function test_a_refund_is_validated_only_if_covered_by_validated_payments(): void
    {
        $caso = $this->expediente('26/24', 26, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');

        $this->rechaza('supera lo pagado antes del sistema', fn () => DB::table('fine_historical_movements')->insert([
            'fine_id' => $multa, 'kind' => 'refund', 'amount' => '300.00', 'evidence_text' => 'Nota',
            'status' => 'validated', 'reviewed_at' => now(),
        ]));

        $devolucion = DB::table('fine_historical_movements')->insertGetId([
            'fine_id' => $multa, 'kind' => 'refund', 'amount' => '300.00', 'evidence_text' => 'Nota',
        ]);

        $this->rechaza('supera lo pagado antes del sistema', fn () => DB::table('fine_historical_movements')
            ->where('id', $devolucion)->update(['status' => 'validated', 'reviewed_at' => now()]));
    }

    public function test_a_day_turned_into_its_month_is_not_a_completion(): void
    {
        $caso = $this->expediente('27/24', 27, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00', importada: true);
        DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'completion',
            'old_values' => json_encode(['registered_on' => '2026-05-01', 'registered_precision' => 'month']),
            'new_values' => json_encode(['registered_on' => '2026-05-01', 'registered_precision' => 'day']),
        ]);

        $this->rechaza('Mover o volver menos precisa', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'completion',
            'old_values' => json_encode(['registered_on' => '2026-05-01', 'registered_precision' => 'day']),
            'new_values' => json_encode(['registered_on' => '2026-05-01', 'registered_precision' => 'month']),
        ]));
    }

    public function test_a_caption_is_not_mistaken_for_a_date(): void
    {
        $caso = $this->expediente('28/24', 28, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');

        $this->rechaza('ya tenia valor', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'completion',
            'old_values' => json_encode(['caption' => 'CRISTOMAX S.A.S']),
            'new_values' => json_encode(['caption' => 'OTRA']),
        ]));
    }

    public function test_only_the_date_of_a_debt_entry_is_revised(): void
    {
        $caso = $this->expediente('29/24', 29, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $constitucion = (int) DB::table('fine_debt_entries')->where('fine_id', $multa)->value('id');

        $this->rechaza('no se revisan', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_debt_entry_id' => $constitucion, 'kind' => 'correction', 'reason_code' => 'other', 'reason' => 'x',
            'old_values' => json_encode(['amount' => 1000]), 'new_values' => json_encode(['amount' => 1]),
        ]));

        $this->rechaza('solo su fecha se completa o corrige', fn () => DB::table('fine_debt_entries')
            ->where('id', $constitucion)->update(['effective_on' => '2026-05-08']));

        DB::table('fine_identifier_revisions')->insert([
            'fine_debt_entry_id' => $constitucion, 'kind' => 'correction', 'reason_code' => 'misread_paper', 'reason' => 'Fecha mal leída',
            'old_values' => json_encode(['effective_on' => '2026-05-07', 'effective_precision' => 'day']),
            'new_values' => json_encode(['effective_on' => '2026-05-08', 'effective_precision' => 'day']),
        ]);

        expect(DB::table('fine_debt_entries')->where('id', $constitucion)->value('effective_on'))->toBe('2026-05-08');
    }

    public function test_the_date_of_a_validated_antecedent_can_still_be_completed(): void
    {
        $caso = $this->expediente('30/24', 30, 2024, null);
        $multa = $this->multaCompleta($caso, '1000.00');
        $id = DB::table('fine_historical_movements')->insertGetId([
            'fine_id' => $multa, 'kind' => 'payment', 'amount' => '100.00', 'evidence_text' => 'Excel',
            'status' => 'validated', 'reviewed_at' => now(),
        ]);

        DB::table('fine_identifier_revisions')->insert([
            'fine_historical_movement_id' => $id, 'kind' => 'completion',
            'old_values' => json_encode(['occurred_on' => null, 'occurred_precision' => 'unknown']),
            'new_values' => json_encode(['occurred_on' => '2025-07-01', 'occurred_precision' => 'month']),
        ]);

        $fila = DB::table('fine_historical_movements')->find($id);
        expect($fila->occurred_on)->toBe('2025-07-01')
            ->and($fila->status)->toBe('validated');
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    private function rechaza(string $fragmento, callable $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain($fragmento);

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }

    private function expediente(string $texto, ?int $numero, ?int $anio, ?string $sufijo): int
    {
        return DB::table('fine_cases')->insertGetId([
            'original_text' => $texto, 'number' => $numero, 'year' => $anio, 'suffix' => $sufijo,
        ]);
    }

    private function resolucion(int $caso, int $numero, int $anio): int
    {
        $id = DB::table('fine_documents')->insertGetId([
            'kind' => 'resolution', 'number' => $numero, 'year' => $anio,
            'original_text' => "N°{$numero}/{$anio}", 'issued_on' => '2026-05-07', 'issued_precision' => 'day',
        ]);
        DB::table('fine_case_documents')->insert(['fine_case_id' => $caso, 'fine_document_id' => $id]);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDeMulta(int $caso): array
    {
        return [
            'fine_case_id' => $caso,
            'caption_original' => 'CRISTOMAX S.A.S',
            'caption' => 'CRISTOMAX S.A.S',
            'registered_on' => '2026-05-07',
            'registered_precision' => 'day',
        ];
    }

    private function multaCompleta(int $caso, string $importe, bool $importada = false): int
    {
        $resolucion = $this->resolucion($caso, random_int(1, 999999), 2026);

        $multa = DB::table('fines')->insertGetId($importada
            ? [...$this->datosDeMulta($caso), 'provenance' => 'import', 'registered_on' => '2026-05-01', 'registered_precision' => 'month']
            : $this->datosDeMulta($caso));

        DB::table('fine_document_links')->insert([
            'fine_id' => $multa, 'fine_case_id' => $caso, 'fine_document_id' => $resolucion, 'role' => 'origin',
        ]);
        DB::table('fine_debt_entries')->insert([
            'fine_id' => $multa, 'kind' => 'constitution', 'amount' => $importe, 'fine_document_id' => $resolucion,
            'effective_on' => '2026-05-07', 'effective_precision' => 'day',
        ]);

        return $multa;
    }

    private function movimiento(int $multa, string $tipo, string $importe, ?string $motivo = null, ?int $revierte = null): int
    {
        return DB::table('fine_debt_entries')->insertGetId([
            'fine_id' => $multa, 'kind' => $tipo, 'amount' => $importe, 'reason' => $motivo,
            'reason_code' => $motivo === null ? null : 'other',
            'reversal_of_id' => $revierte, 'effective_on' => '2026-06-01', 'effective_precision' => 'day',
        ]);
    }
}
