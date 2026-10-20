<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La cuenta corriente de multas, de punta a punta por las pantallas.
 *
 * Lo que la base impide se prueba en `GuardasDelNucleoDeMultasTest`; acá,
 * que el operador reciba el mensaje legible y que cada rol pueda lo que le
 * corresponde.
 */
class CuentaCorrienteDeMultasTest extends TestCase
{
    use RefreshDatabase;

    // ── Alta ────────────────────────────────────────────────────────────

    public function test_a_fine_is_registered_with_its_case_resolution_and_acts(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), $this->alta())
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $multa = Fine::query()->sole();
        $expediente = FineCase::query()->sole();

        expect($expediente->original_text)->toBe('169607/24-0')
            ->and($expediente->number)->toBe(169607)
            ->and($expediente->year)->toBe(2024)
            ->and($expediente->suffix)->toBe('0')
            ->and($multa->caption)->toBe('CRISTOMAX S.A.S')
            ->and($multa->person_id)->toBeNull()
            ->and(FineDocument::query()->count())->toBe(2);

        $constitucion = FineDebtEntry::query()->sole();
        expect($constitucion->amount)->toBe('1400000.00')
            ->and($constitucion->effective_on?->toDateString())->toBe('2026-05-07');

        expect(AuditEvent::query()->where('action', 'multa.registrada')->exists())->toBeTrue();
        $this->assertToast('Multa registrada.');
    }

    public function test_a_second_fine_reuses_the_case_and_its_shared_act(): void
    {
        $operador = $this->operador();
        $this->actingAs($operador)->post(route('multas.store'), $this->alta());
        $acta = FineDocument::query()->where('kind', 'act')->sole();

        $this->actingAs($operador)
            ->post(route('multas.store'), [
                ...$this->alta(),
                'caseNumber' => '169607/2024-0',
                'resolution' => ['text' => 'N°0906/26', 'issuedOn' => '2026-05-07'],
                'acts' => [],
                'actIds' => [$acta->id],
                'caption' => 'DISTRIBUIDORA VIDT SRL',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect(FineCase::query()->count())->toBe(1)
            ->and(Fine::query()->count())->toBe(2)
            ->and(FineDocument::query()->where('kind', 'act')->count())->toBe(1);
    }

    public function test_the_case_number_is_written_as_on_paper(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), [...$this->alta(), 'caseNumber' => 'EXP-12'])
            ->assertSessionHasErrors('caseNumber');
    }

    public function test_a_manual_resolution_says_its_year(): void
    {
        $this->actingAs($this->operador())
            ->post(route('multas.store'), [
                ...$this->alta(),
                'resolution' => ['text' => 'N°0699826', 'issuedOn' => '2026-05-07'],
            ])
            ->assertSessionHasErrors(['resolution.text' => 'A la resolución le falta el año: N°0649/26.']);

        expect(Fine::query()->count())->toBe(0)
            ->and(FineCase::query()->count())->toBe(0);
    }

    public function test_the_form_warns_about_a_case_with_another_suffix(): void
    {
        $operador = $this->operador();
        $this->actingAs($operador)->post(route('multas.store'), $this->alta());

        $this->actingAs($operador)
            ->get(route('multas.create', ['expediente' => '169607/24']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/create')
                ->where('existente', null)
                ->has('similares', 1));

        $this->actingAs($operador)
            ->get(route('multas.create', ['expediente' => '169607/24-0']))
            ->assertInertia(fn (Assert $page) => $page->where('existente.label', '169607/2024-0'));
    }

    // ── Consulta ────────────────────────────────────────────────────────

    public function test_the_read_only_role_sees_the_account_but_does_not_register(): void
    {
        $multa = $this->multa();
        $consulta = $this->operador('consulta');

        $this->actingAs($consulta)->get(route('multas.index'))->assertOk();
        $this->actingAs($consulta)->get(route('multas.show', $multa))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/show')
                ->where('multa.balance.debt', '1400000.00')
                ->where('multa.balance.status', 'pending')
                ->where('can.register', false));

        $this->actingAs($consulta)->get(route('multas.create'))->assertForbidden();
        $this->actingAs($consulta)->post(route('multas.store'), $this->alta())->assertForbidden();
    }

    public function test_the_account_is_searched_by_case_resolution_act_or_caption(): void
    {
        $this->multa();
        $operador = $this->operador('consulta');

        foreach (['169607/24', 'N°0649/26', '1089657', 'cristomax', '169607'] as $termino) {
            $this->actingAs($operador)
                ->get(route('multas.index', ['q' => $termino]))
                ->assertInertia(fn (Assert $page) => $page->has('multas', 1));
        }

        $this->actingAs($operador)
            ->get(route('multas.index', ['q' => 'otra empresa']))
            ->assertInertia(fn (Assert $page) => $page->has('multas', 0));

        $this->actingAs($operador)
            ->get(route('multas.index', ['estado' => 'sin-deuda']))
            ->assertInertia(fn (Assert $page) => $page->has('multas', 0));
    }

    // ── Ajustes ─────────────────────────────────────────────────────────

    public function test_a_resolution_lowers_the_obligation_and_keeps_the_original(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.ajustes.store', $multa), $this->ajuste('reduction', '400000.00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->saldo($multa, 'obligation'))->toBe('1000000.00')
            ->and(FineDebtEntry::query()->where('kind', 'constitution')->value('amount'))->toBe('1400000.00')
            ->and(AuditEvent::query()->where('action', 'multa.ajuste-registrado')->exists())->toBeTrue();
    }

    public function test_a_reduction_can_not_leave_the_obligation_negative(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.ajustes.store', $multa), $this->ajuste('reduction', '1400000.01'))
            ->assertSessionHasErrors('amount');

        expect($this->saldo($multa, 'obligation'))->toBe('1400000.00');
    }

    public function test_whoever_loads_does_not_adjust(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('multas.ajustes.store', $multa), $this->ajuste('reduction', '1.00'))
            ->assertForbidden();
    }

    public function test_an_adjustment_is_reversed_whole_and_once(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');
        $this->actingAs($contador)->post(route('multas.ajustes.store', $multa), $this->ajuste('reduction', '400000.00'));
        $reduccion = FineDebtEntry::query()->where('kind', 'reduction')->sole();

        $this->actingAs($contador)
            ->post(route('multas.ajustes.reverse', $reduccion), ['reason' => 'Se cargó en la multa equivocada'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->saldo($multa, 'obligation'))->toBe('1400000.00');

        $this->actingAs($contador)
            ->post(route('multas.ajustes.reverse', $reduccion), ['reason' => 'Otra vez'])
            ->assertSessionHasErrors(['reason' => 'Ese ajuste ya se revirtió.']);
    }

    public function test_the_original_amount_is_not_reversed(): void
    {
        $multa = $this->multa();
        $constitucion = FineDebtEntry::query()->where('fine_id', $multa->id)->sole();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.ajustes.reverse', $constitucion), ['reason' => 'Anularla'])
            ->assertSessionHasErrors('reason');
    }

    public function test_a_ratification_is_linked_without_moving_the_obligation(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.store', $multa), [
                'role' => 'ratification',
                'document' => ['kind' => 'resolution', 'text' => 'N° 1711/25', 'issuedOn' => '2025-07-17'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->saldo($multa, 'obligation'))->toBe('1400000.00')
            ->and(DB::table('fine_document_links')->where('role', 'ratification')->count())->toBe(1);
    }

    // ── Antecedentes ────────────────────────────────────────────────────

    public function test_a_previous_payment_counts_only_once_validated(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.store', $multa), [
                'kind' => 'payment', 'amount' => '280000.00', 'occurredOn' => '2026-05', 'evidence' => 'Excel 2026, columna mayo',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $antecedente = FineHistoricalMovement::query()->sole();
        expect($antecedente->occurred_on?->toDateString())->toBe('2026-05-01')
            ->and($antecedente->occurred_precision->value)->toBe('month')
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.review', $antecedente), ['status' => 'validated'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->saldo($multa, 'debt'))->toBe('1120000.00')
            ->and(DB::table('financial_events')->count())->toBe(0);
    }

    public function test_discarding_a_previous_payment_says_why(): void
    {
        $multa = $this->multa();
        $antecedente = FineHistoricalMovement::query()->create([
            'fine_id' => $multa->id, 'kind' => 'payment', 'amount' => '1.00', 'evidence_text' => 'Excel',
        ]);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.review', $antecedente), ['status' => 'rejected'])
            ->assertSessionHasErrors('note');
    }

    // ── Revisiones ──────────────────────────────────────────────────────

    public function test_completing_a_blank_does_not_need_a_reason(): void
    {
        $this->multa();
        $expediente = FineCase::query()->sole();

        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.revisiones.store', $expediente), [
                'number' => 169607, 'year' => 2024, 'suffix' => '1',
                'expected' => ['number' => 169607, 'year' => 2024, 'suffix' => '0'],
            ])
            ->assertForbidden();

        $sinSufijo = FineCase::query()->create(['original_text' => '120276/25', 'number' => 120276, 'year' => 2025]);

        $this->actingAs($this->operador())
            ->post(route('multas.expedientes.revisiones.store', $sinSufijo), [
                'number' => 120276, 'year' => 2025, 'suffix' => '0',
                'expected' => ['number' => 120276, 'year' => 2025, 'suffix' => null],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($sinSufijo->refresh()->suffix)->toBe('0')
            ->and($sinSufijo->original_text)->toBe('120276/25');
    }

    public function test_correcting_a_loaded_value_needs_a_reason_and_the_adjust_permission(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX SAS', 'registeredOn' => '2026-05-07',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($contador)
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX SAS', 'registeredOn' => '2026-05-07', 'reason' => 'Razón social según AFIP',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $multa->refresh();
        expect($multa->caption)->toBe('CRISTOMAX SAS')
            ->and($multa->caption_original)->toBe('CRISTOMAX S.A.S')
            ->and(AuditEvent::query()->where('action', 'multa.datos-corregidos')->exists())->toBeTrue();
    }

    public function test_a_manual_fine_keeps_its_full_registration_date(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX S.A.S', 'registeredOn' => '2026-05', 'reason' => 'Solo se sabe el mes',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasErrors('registeredOn');
    }

    public function test_a_case_is_not_merged_by_correcting_its_number(): void
    {
        $this->multa();
        $otro = FineCase::query()->create(['original_text' => '1/24', 'number' => 1, 'year' => 2024]);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.expedientes.revisiones.store', $otro), [
                'number' => 169607, 'year' => 2024, 'suffix' => '0', 'reason' => 'Era el mismo',
                'expected' => ['number' => 1, 'year' => 2024, 'suffix' => null],
            ])
            ->assertSessionHasErrors('number');
    }

    // ── Revisión del 2026-10-20 ─────────────────────────────────────────

    /**
     * R3: el formulario dice qué vio. Un campo que el operador no tocó no
     * pisa lo que otro cambió mientras tanto, y uno que sí tocó se rechaza
     * si ya no es lo que vio.
     */
    public function test_a_stale_form_does_not_overwrite_a_newer_correction(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');
        $persona = DB::table('people')->insertGetId([
            'type' => 'company', 'legal_name' => 'CRISTOMAX SAS',
        ]);

        // A corrige la carátula.
        $this->actingAs($contador)
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX SAS', 'registeredOn' => '2026-05-07', 'reason' => 'Razón social según AFIP',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        // B abrió antes: manda la carátula vieja sin tocarla y vincula la persona.
        $this->actingAs($this->operador())
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX S.A.S', 'personId' => $persona, 'registeredOn' => '2026-05-07',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $multa->refresh();
        expect($multa->caption)->toBe('CRISTOMAX SAS')
            ->and($multa->person_id)->toBe($persona);

        // C también abrió antes y sí quiere cambiar la carátula: se rechaza.
        $this->actingAs($contador)
            ->post(route('multas.revisiones.store', $multa), [
                'caption' => 'CRISTOMAX', 'personId' => null, 'registeredOn' => '2026-05-07', 'reason' => 'Otra lectura',
                'expected' => ['caption' => 'CRISTOMAX S.A.S', 'personId' => null, 'registeredOn' => '2026-05-07'],
            ])
            ->assertSessionHasErrors('reason');

        expect($multa->refresh()->caption)->toBe('CRISTOMAX SAS');
    }

    /**
     * R2: dejar menos precisa una fecha es corregir, aunque el día no cambie.
     */
    public function test_making_a_date_less_precise_is_a_correction(): void
    {
        $this->multa();
        $resolucion = FineDocument::query()->where('kind', 'resolution')->sole();

        $this->actingAs($this->operador())
            ->post(route('multas.documentos.revisiones.store', $resolucion), [
                'actType' => null, 'number' => 649, 'year' => 2026, 'issuedOn' => '2026-05',
                'expected' => ['actType' => null, 'number' => 649, 'year' => 2026, 'issuedOn' => '2026-05-07'],
            ])
            ->assertForbidden();

        expect($resolucion->refresh()->issued_precision->value)->toBe('day');
    }

    /**
     * R1: una devolución que no cubre ningún pago validado queda pendiente.
     */
    public function test_a_refund_without_a_validated_payment_stays_pending(): void
    {
        $multa = $this->multa();
        $operador = $this->operador();
        $contador = $this->operador('contador');

        $this->actingAs($operador)->post(route('multas.antecedentes.store', $multa), [
            'kind' => 'refund', 'amount' => '300.00', 'occurredOn' => '2025-08', 'evidence' => 'Nota de devolución',
        ]);
        $devolucion = FineHistoricalMovement::query()->where('kind', 'refund')->sole();

        $this->actingAs($contador)
            ->post(route('multas.antecedentes.review', $devolucion), ['status' => 'validated'])
            ->assertSessionHasErrors('note');

        expect($devolucion->refresh()->status->value)->toBe('pending_review')
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00');

        $this->actingAs($operador)->post(route('multas.antecedentes.store', $multa), [
            'kind' => 'payment', 'amount' => '500.00', 'occurredOn' => '2025-07', 'evidence' => 'Excel 2025, julio',
        ]);
        $pago = FineHistoricalMovement::query()->where('kind', 'payment')->sole();
        $this->actingAs($contador)->post(route('multas.antecedentes.review', $pago), ['status' => 'validated']);

        $this->actingAs($contador)
            ->post(route('multas.antecedentes.review', $devolucion), ['status' => 'validated'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($this->saldo($multa, 'debt'))->toBe('1399800.00');
    }

    /**
     * R4: la fecha desconocida de un movimiento importado se completa sin
     * tocar su importe ni su efecto.
     */
    public function test_an_unknown_entry_date_is_completed_without_touching_its_amount(): void
    {
        $expediente = FineCase::query()->create(['original_text' => 'S/N', 'provenance' => 'import']);
        $multa = DB::transaction(function () use ($expediente): Fine {
            $multa = Fine::query()->create([
                'fine_case_id' => $expediente->id, 'caption_original' => 'IMPORTADA SRL', 'caption' => 'IMPORTADA SRL',
                'provenance' => 'import',
            ]);
            FineDebtEntry::query()->create([
                'fine_id' => $multa->id, 'kind' => 'constitution', 'amount' => '1000.00', 'provenance' => 'import',
            ]);

            return $multa;
        });
        $constitucion = FineDebtEntry::query()->where('fine_id', $multa->id)->sole();

        $this->actingAs($this->operador())
            ->post(route('multas.movimientos.revisiones.store', $constitucion), [
                'date' => '2024-03', 'expected' => ['date' => ''],
            ])
            ->assertRedirect(route('multas.show', $multa))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $constitucion->refresh();
        expect($constitucion->effective_on?->toDateString())->toBe('2024-03-01')
            ->and($constitucion->effective_precision->value)->toBe('month')
            ->and($constitucion->amount)->toBe('1000.00')
            ->and($this->saldo($multa, 'obligation'))->toBe('1000.00');
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function alta(): array
    {
        return [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ];
    }

    private function multa(?User $operador = null): Fine
    {
        $this->actingAs($operador ?? $this->operador())->post(route('multas.store'), $this->alta());

        return Fine::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function ajuste(string $tipo, string $importe): array
    {
        return [
            'kind' => $tipo,
            'amount' => $importe,
            'effectiveOn' => '2026-06-01',
            'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
            'reason' => 'Recurso jerárquico parcialmente admitido',
        ];
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }
}
