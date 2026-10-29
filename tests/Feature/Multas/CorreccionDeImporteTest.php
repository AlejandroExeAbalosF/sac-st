<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Shared\Models\AuditEvent;
use App\Support\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Un ajuste se pide como importe nuevo, por resolución o —solo el
 * administrador— como corrección de un importe mal cargado, sin resolución.
 *
 * Las dos vías dejan otra fila: el importe original no se toca. La base
 * distingue la corrección por la falta de documento y le exige el motivo de
 * su lista con detalle.
 */
class CorreccionDeImporteTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_administrador_corrige_el_importe_hacia_abajo_y_hacia_arriba(): void
    {
        $multa = $this->multa();
        $admin = $this->operador('administrador');

        $this->actingAs($admin)
            ->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('140000.00', '1400000.00'))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $reduccion = FineDebtEntry::query()->where('kind', 'reduction')->sole();
        expect($this->obligacion($multa))->toBe('140000.00')
            ->and($reduccion->amount)->toBe('1260000.00')
            ->and($reduccion->fine_document_id)->toBeNull()
            ->and($reduccion->isAmountCorrection())->toBeTrue()
            ->and($reduccion->reason_code)->toBe(FineReasonCode::DataEntryError)
            ->and($reduccion->effective_on?->toDateString())->toBe(BusinessDate::today()->toDateString())
            ->and(FineDebtEntry::query()->where('kind', 'constitution')->value('amount'))->toBe('1400000.00')
            ->and(AuditEvent::query()->where('action', 'multa.importe-corregido')->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'multa.ajuste-registrado')->exists())->toBeFalse();

        $this->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('150000.00', '140000.00'))
            ->assertSessionHasNoErrors();

        expect($this->obligacion($multa))->toBe('150000.00')
            ->and(FineDebtEntry::query()->where('kind', 'increase')->value('amount'))->toBe('10000.00');
    }

    public function test_la_ficha_rotula_la_correccion_y_ofrece_la_pestana_solo_al_administrador(): void
    {
        $multa = $this->multa();
        $admin = $this->operador('administrador');
        $this->actingAs($admin)->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('140000.00', '1400000.00'));

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.correctAmount', true)
                ->where('multa.debtEntries.1.isCorrection', true)
                ->where('multa.debtEntries.0.isCorrection', false));

        $this->actingAs($this->operador('contador'))
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.adjust', true)
                ->where('can.correctAmount', false));
    }

    public function test_el_contador_no_corrige_ni_revierte_una_correccion(): void
    {
        $multa = $this->multa();
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('140000.00', '1400000.00'))
            ->assertForbidden();

        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('140000.00', '1400000.00'));
        $correccion = FineDebtEntry::query()->where('kind', 'reduction')->sole();

        $this->actingAs($contador)
            ->post(route('multas.ajustes.reverse', $correccion), ['reasonCode' => 'data_entry_error', 'reason' => 'Era otro importe'])
            ->assertForbidden();

        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.ajustes.reverse', $correccion), ['reasonCode' => 'data_entry_error', 'reason' => 'Era otro importe'])
            ->assertSessionHasNoErrors();

        expect($this->obligacion($multa))->toBe('1400000.00');
    }

    public function test_el_detalle_es_obligatorio_y_el_motivo_es_el_de_su_lista(): void
    {
        $multa = $this->multa();
        $this->actingAs($this->operador('administrador'));

        $this->post(route('multas.correcciones-de-importe.store', $multa), [...$this->correccion('140000.00', '1400000.00'), 'reason' => '  '])
            ->assertSessionHasErrors(['reason' => 'Contá en el detalle qué estaba mal cargado.']);

        $this->post(route('multas.correcciones-de-importe.store', $multa), [...$this->correccion('140000.00', '1400000.00'), 'reasonCode' => 'appeal_admitted'])
            ->assertSessionHasErrors('reasonCode');

        expect(FineDebtEntry::query()->where('fine_id', $multa->id)->count())->toBe(1);
    }

    public function test_el_mismo_importe_y_un_importe_visto_viejo_se_rechazan(): void
    {
        $multa = $this->multa();
        $this->actingAs($this->operador('administrador'));

        $this->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('1400000.00', '1400000.00'))
            ->assertSessionHasErrors(['newAmount' => 'Es el mismo importe que la multa ya tiene.']);

        // Otro corrigió mientras tanto: la diferencia pensada sobre 1.400.000 ya no vale.
        $this->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('140000.00', '1400000.00'));
        $this->post(route('multas.correcciones-de-importe.store', $multa), $this->correccion('1300000.00', '1400000.00'))
            ->assertSessionHasErrors(['newAmount' => 'El importe de la multa cambió mientras cargabas: ahora es $ 140.000,00. Revisá el nuevo importe.']);

        expect($this->obligacion($multa))->toBe('140000.00');
    }

    public function test_el_ajuste_por_resolucion_deduce_aumento_o_reduccion_del_importe_nuevo(): void
    {
        $multa = $this->multa();
        $this->actingAs($this->operador('contador'));

        $this->post(route('multas.ajustes.store', $multa), [
            'newAmount' => '1500000.00', 'expectedAmount' => '1400000.00', 'effectiveOn' => '2026-06-01',
            'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
            'reasonCode' => 'court_ruling',
        ])->assertSessionHasNoErrors();

        $aumento = FineDebtEntry::query()->where('kind', 'increase')->sole();
        expect($aumento->amount)->toBe('100000.00')
            ->and($aumento->fine_document_id)->not->toBeNull()
            ->and($aumento->isAmountCorrection())->toBeFalse()
            ->and($this->obligacion($multa))->toBe('1500000.00');
    }

    public function test_la_base_exige_a_la_correccion_su_motivo_y_su_detalle(): void
    {
        $multa = $this->multa();
        $fila = [
            'fine_id' => $multa->id, 'kind' => FineDebtEntryKind::Reduction->value, 'amount' => '5.00',
            'effective_on' => '2026-06-01', 'effective_precision' => 'day', 'provenance' => 'manual',
        ];

        // Sin resolución y con un motivo de la lista del ajuste: no es ni una cosa ni la otra.
        $this->rechaza(fn () => DB::table('fine_debt_entries')->insert([...$fila, 'reason_code' => 'appeal_admitted', 'reason' => 'Recurso']));
        // Una corrección sin detalle.
        $this->rechaza(fn () => DB::table('fine_debt_entries')->insert([...$fila, 'reason_code' => 'data_entry_error']));

        DB::table('fine_debt_entries')->insert([...$fila, 'reason_code' => 'data_entry_error', 'reason' => 'Un cero de más']);
        expect(FineDebtEntry::query()->where('kind', 'reduction')->count())->toBe(1);
    }

    private function rechaza(callable $insertar): void
    {
        DB::beginTransaction();

        try {
            $insertar();
            $this->fail('La base aceptó una corrección sin su motivo.');
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain('fine_debt_entries_reason_check');
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, string> */
    private function correccion(string $nuevo, string $visto): array
    {
        return [
            'newAmount' => $nuevo,
            'expectedAmount' => $visto,
            'reasonCode' => 'data_entry_error',
            'reason' => 'Se cargó con un cero de más',
        ];
    }

    private function obligacion(Fine $multa): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value('obligation');
    }

    private function multa(): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ]);

        return Fine::query()->latest('id')->firstOrFail();
    }
}
