<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\ReverseFundReceipt;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El código del motivo tiene su columna, para poder filtrar y contar.
 *
 * Se prueba de los dos lados: lo que guardan los Actions y lo que rechaza la
 * base cuando alguien escribe esas filas sin pasar por ellos.
 */
class CodigosDeMotivoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_anular_el_cobro_guarda_el_codigo_en_el_recibo_y_en_la_imputacion(): void
    {
        $cuota = $this->cuotaCobrada();

        app(VoidCashCollection::class)->handle(
            installment: $cuota,
            motivo: new Reason(HaberesReasonCode::WrongAmount, 'Un cero de más.'),
            idempotencyKey: 'anular-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $recibo = Receipt::query()->firstOrFail();

        $this->assertSame('wrong_amount', $recibo->void_reason_code);
        $this->assertSame('Importe equivocado: Un cero de más.', $recibo->void_reason);
        $this->assertSame(
            'wrong_amount',
            FundingAllocation::query()->where('allocation_kind', AllocationKind::Reversal)->firstOrFail()->reversal_reason_code,
        );
    }

    public function test_revertir_guarda_el_codigo_en_la_recepcion(): void
    {
        $cuota = $this->cuotaCobrada();
        $imputacion = $this->liberar($cuota);

        $recepcion = app(ReverseFundReceipt::class)->handle(
            receipt: $imputacion->fundReceipt,
            motivo: new Reason(HaberesReasonCode::Duplicate),
            idempotencyKey: 'revertir-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->assertSame('duplicate', $recepcion->reversal_reason_code);
        $this->assertSame('Cargado dos veces', $recepcion->reversal_reason);
    }

    public function test_la_base_rechaza_anular_un_recibo_sin_codigo(): void
    {
        $this->cuotaCobrada();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/receipts_void_reason_code_check/');

        DB::table('receipts')->update([
            'status' => 'voided',
            'void_reason' => 'Sin código.',
            'voided_by' => $this->operador()->id,
            'voided_at' => now(),
        ]);
    }

    public function test_la_base_rechaza_un_codigo_en_un_recibo_vigente(): void
    {
        $this->cuotaCobrada();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/receipts_void_reason_code_check/');

        DB::table('receipts')->update(['void_reason_code' => 'other']);
    }

    public function test_la_base_rechaza_revertir_una_recepcion_sin_codigo(): void
    {
        $cuota = $this->cuotaCobrada();
        $recepcion = $this->liberar($cuota)->fundReceipt;

        // El evento da igual: lo que se prueba es el CHECK del código.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/fund_receipts_reversal_reason_code_check/');

        DB::table('fund_receipts')->where('id', $recepcion->id)->update([
            'reversal_event_id' => $recepcion->financial_event_id,
            'reversed_at' => now(),
            'reversal_reason' => 'Sin código.',
        ]);
    }

    public function test_la_base_rechaza_una_liberacion_sin_codigo_o_de_otra_lista(): void
    {
        $original = FundingAllocation::query()->findOrFail($this->liberar($this->cuotaCobrada())->reversal_of_id);
        $fila = fn (?string $codigo): array => [
            'allocation_event_id' => $original->allocation_event_id,
            'fund_receipt_id' => $original->fund_receipt_id,
            'haber_id' => $original->haber_id,
            'beneficiary_installment_id' => $original->beneficiary_installment_id,
            'allocation_kind' => 'reversal',
            'reversal_of_id' => $original->id,
            'amount' => '1.00',
            'allocated_at' => now(),
            'reversal_reason_code' => $codigo,
        ];

        foreach ([null, 'deposit_not_made'] as $codigo) {
            try {
                DB::transaction(fn () => DB::table('funding_allocations')->insert($fila($codigo)));
                $this->fail('La base aceptó una liberación con código '.var_export($codigo, true));
            } catch (QueryException $e) {
                $this->assertStringContainsString('funding_allocations_reversal_reason_code_check', $e->getMessage());
            }
        }
    }

    /** Lo anulado o revertido antes de la columna queda con «Otro», y su texto no cambia. */
    public function test_la_migracion_completa_lo_ya_anulado_y_revertido(): void
    {
        $imputacion = $this->liberar($this->cuotaCobrada());
        $recepcion = app(ReverseFundReceipt::class)->handle(
            receipt: $imputacion->fundReceipt,
            motivo: new Reason(HaberesReasonCode::Duplicate),
            idempotencyKey: 'revertir-'.Str::random(8),
            actorId: $this->operador()->id,
        );
        app(VoidCashCollection::class)->handle(
            installment: $imputacion->installment,
            motivo: new Reason(HaberesReasonCode::WrongAmount, 'Un cero de más.'),
            idempotencyKey: 'anular-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        // PostgreSQL no altera una tabla con triggers diferidos pendientes.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $migracion = require database_path('migrations/2026_11_06_040000_add_reason_codes_to_voids_and_reversals.php');
        $migracion->down();
        $migracion->up();

        $recibo = Receipt::query()->firstOrFail();

        $this->assertSame('other', $recibo->void_reason_code);
        $this->assertSame('Importe equivocado: Un cero de más.', $recibo->void_reason);
        $this->assertSame('other', FundReceipt::query()->findOrFail($recepcion->id)->reversal_reason_code);
        $this->assertSame('other', FundingAllocation::query()->findOrFail($imputacion->id)->reversal_reason_code);
    }

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->orderBy('installment_number')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /** La fila `reversal` que queda al liberar toda la imputación. */
    private function liberar(BeneficiaryInstallment $cuota): FundingAllocation
    {
        $imputacion = FundingAllocation::query()->live()->where('beneficiary_installment_id', $cuota->id)->firstOrFail();

        return app(UnallocateFunds::class)->handle(
            allocation: $imputacion,
            amount: $imputacion->amount,
            idempotencyKey: 'liberar-'.Str::random(8),
            motivo: new Reason(HaberesReasonCode::WrongInstallment),
            actorId: $this->operador()->id,
        );
    }
}
