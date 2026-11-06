<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CancelExpediente;
use App\Modules\Haberes\Actions\CancelHaber;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Las guardas que faltaban, encontradas recorriendo el circuito entero.
 *
 * Todas responden a la misma forma de error: **el sistema afirmando dos
 * cosas incompatibles**. Que un traslado esté en camino y su imputación
 * revertida; que un haber esté anulado y sus cuotas tengan plata de
 * terceros adentro; que un recibo esté vigente y no haya forma de darlo
 * de baja.
 */
class GuardasDelCircuitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();

        $this->seed(HaberesDemoSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancelar el traslado
    |--------------------------------------------------------------------------
    */

    /** El efectivo vuelve a la caja: lo que se deshace es que salió. */
    public function test_cancelar_el_traslado_devuelve_el_efectivo_a_la_caja(): void
    {
        $cuota = $this->cuotaCobrada();
        $importe = $cuota->expected_amount;
        $traslado = $this->depositar($cuota);

        $this->assertSame('0.00', $this->saldo('CASH_ON_HAND'));
        $this->assertSame($importe, $this->saldo('CASH_IN_TRANSIT'));

        $cancelado = app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'El depósito se cargó sobre la cuota equivocada.',
            idempotencyKey: 'cancelar-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->assertSame(CashTransferStatus::Cancelled, $cancelado->status);
        $this->assertSame($importe, $this->saldo('CASH_ON_HAND'));
        $this->assertSame('0.00', $this->saldo('CASH_IN_TRANSIT'));
    }

    /** Y con eso el cobro vuelve a poder anularse. */
    public function test_cancelado_el_traslado_el_cobro_se_puede_anular(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        $this->assertThrows(
            fn () => $this->anular($cuota),
            ValidationException::class,
        );

        $this->cancelarTraslado($traslado);
        $this->anular($cuota->refresh());

        $this->assertSame(ReceiptStatus::Voided, Receipt::query()->firstOrFail()->status);
        $this->assertSame('0.00', $this->saldo('CASH_ON_HAND'));
    }

    /** Un traslado acreditado no se cancela: ese dinero está en la cuenta. */
    public function test_no_se_cancela_un_traslado_acreditado(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        DB::table('cash_to_bank_transfers')
            ->where('id', $traslado->id)
            ->update(['status' => CashTransferStatus::BankConfirmed->value, 'credit_event_id' => $traslado->deposit_event_id]);

        $this->expectExceptionMessageMatches('/ya confirmó ese depósito/u');
        $this->cancelarTraslado($traslado->refresh());
    }

    /*
    |--------------------------------------------------------------------------
    | Liberar con el efectivo en tránsito
    |--------------------------------------------------------------------------
    */

    /** No se libera plata que ya salió camino al banco. */
    public function test_no_se_libera_efectivo_ya_depositado(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $this->expectExceptionMessageMatches('/ya se depositó en el banco/u');

        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()->live()->firstOrFail(),
            amount: '100.00',
            idempotencyKey: 'liberar-en-transito',
            motivo: new Reason(HaberesReasonCode::Other, 'Prueba de la guarda.'),
            actorId: $this->operador()->id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Anular con dinero adentro
    |--------------------------------------------------------------------------
    */

    /** No se anula un haber cuyas cuotas tienen plata imputada. */
    public function test_no_se_anula_un_haber_con_dinero_imputado(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->expectExceptionMessageMatches('/dinero imputado/u');

        app(CancelHaber::class)->handle($cuota->haber, new Reason(HaberesReasonCode::DataEntryError));
    }

    /** Ni un expediente. */
    public function test_no_se_anula_un_expediente_con_dinero_imputado(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->expectExceptionMessageMatches('/dinero imputado/u');

        app(CancelExpediente::class)->handle($cuota->haber->expediente, new Reason(HaberesReasonCode::DataEntryError));
    }

    /** Liberada la plata, el haber se anula sin problema. */
    public function test_liberada_la_plata_el_haber_se_anula(): void
    {
        $cuota = $this->cuotaCobrada();

        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()->live()->firstOrFail(),
            amount: $cuota->expected_amount,
            idempotencyKey: 'liberar-para-anular',
            motivo: new Reason(HaberesReasonCode::Other, 'Prueba de la guarda.'),
            actorId: $this->operador()->id,
        );

        $this->anular($cuota);

        $anulado = app(CancelHaber::class)->handle($cuota->haber, new Reason(HaberesReasonCode::DataEntryError));

        $this->assertSame('cancelled', $anulado->workflow_status->value);
    }

    /** Liberar no da de baja el recibo: anular el haber lo pide primero. */
    public function test_no_se_anula_un_haber_con_el_recibo_vigente(): void
    {
        $cuota = $this->cuotaLiberada();

        $this->expectExceptionMessageMatches('/recibo de ingreso vigente/u');

        app(CancelHaber::class)->handle($cuota->haber, new Reason(HaberesReasonCode::DataEntryError));
    }

    /** Ni un expediente. */
    public function test_no_se_anula_un_expediente_con_el_recibo_vigente(): void
    {
        $cuota = $this->cuotaLiberada();

        $this->expectExceptionMessageMatches('/recibo de ingreso vigente/u');

        app(CancelExpediente::class)->handle($cuota->haber->expediente, new Reason(HaberesReasonCode::DataEntryError));
    }

    /** La base tampoco deja anular la cuota con el recibo vigente. */
    public function test_la_base_rechaza_anular_una_cuota_con_el_recibo_vigente(): void
    {
        $cuota = $this->cuotaLiberada();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/recibo de ingreso vigente/u');

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['workflow_status' => 'cancelled']);
    }

    /*
    |--------------------------------------------------------------------------
    | La cuota ya entregada
    |--------------------------------------------------------------------------
    */

    /** El mostrador no lleva Orden: la plata entregada tampoco se libera. */
    public function test_no_se_libera_la_plata_de_una_cuota_entregada(): void
    {
        $cuota = $this->cuotaEntregada();

        try {
            $this->liberarTodo($cuota);
            $this->fail('Liberó dinero que ya se entregó.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('egreso registrado', $e->errors()['allocationId'][0]);
        }

        $this->assertSame('0.00', $this->saldo('BENEFICIARY_FUNDS'));
    }

    /** La base también lo rechaza, sin pasar por el Action. */
    public function test_la_base_rechaza_liberar_la_plata_entregada(): void
    {
        $cuota = $this->cuotaEntregada();
        $original = FundingAllocation::query()->live()->where('beneficiary_installment_id', $cuota->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/egreso registrado/u');

        FundingAllocation::query()->create([
            'allocation_event_id' => $original->allocation_event_id,
            'fund_receipt_id' => $original->fund_receipt_id,
            'haber_id' => $original->haber_id,
            'beneficiary_installment_id' => $original->beneficiary_installment_id,
            'allocation_kind' => AllocationKind::Reversal,
            'reversal_of_id' => $original->id,
            'amount' => $original->amount,
            'allocated_by' => $this->operador()->id,
            'allocated_at' => now(),
            'notes' => 'Prueba de la base.',
        ]);
    }

    /** Ni se anula su cobro: el dinero entró y salió. */
    public function test_no_se_anula_el_cobro_de_una_cuota_entregada(): void
    {
        $cuota = $this->cuotaEntregada();

        try {
            $this->anular($cuota);
            $this->fail('Anuló el cobro de una cuota ya entregada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('egreso registrado', $e->errors()['installmentId'][0]);
        }

        $this->assertSame(
            ReceiptStatus::Issued,
            Receipt::query()->where('receipt_type', ReceiptType::Income)->firstOrFail()->status,
        );
    }

    /** Lo que se entregó en mano ya no está en la caja para llevarlo al banco. */
    public function test_no_se_deposita_el_efectivo_de_una_cuota_entregada(): void
    {
        $cuota = $this->cuotaEntregada();

        try {
            $this->depositar($cuota);
            $this->fail('Depositó un efectivo que ya se entregó.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya se entregó', $e->errors()['installmentId'][0]);
        }

        $this->assertSame(0, CashToBankTransfer::query()->count());
    }

    /** La base tampoco deja sumarla a un traslado. */
    public function test_la_base_rechaza_trasladar_el_efectivo_entregado(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'El depósito se cargó sobre la cuota equivocada.',
            idempotencyKey: 'cancelar-'.Str::random(8),
            actorId: $this->operador()->id,
        );
        app(DeliverToBeneficiary::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'entrega-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $imputacion = FundingAllocation::query()->live()->where('beneficiary_installment_id', $cuota->id)->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/egreso registrado/u');

        DB::table('cash_to_bank_transfer_items')->insert([
            'cash_to_bank_transfer_id' => $traslado->id,
            'fund_receipt_id' => $imputacion->fund_receipt_id,
            'funding_allocation_id' => $imputacion->id,
            'amount' => $imputacion->amount,
        ]);
    }

    /** Y el haber sigue sin poder anularse. */
    public function test_no_se_anula_un_haber_con_una_cuota_entregada(): void
    {
        $cuota = $this->cuotaEntregada();

        $this->expectExceptionMessageMatches('/dinero imputado/u');

        app(CancelHaber::class)->handle($cuota->haber, new Reason(HaberesReasonCode::DataEntryError));
    }

    /*
    |--------------------------------------------------------------------------
    | El recibo sin cobro
    |--------------------------------------------------------------------------
    */

    /**
     * Liberada toda la plata, el recibo igual se puede anular.
     *
     * Antes era un callejón: «esta cuota no tiene ningún cobro que anular»
     * sobre un comprobante que sí existía y que nadie podía dar de baja.
     */
    public function test_el_recibo_se_anula_aunque_ya_no_quede_plata(): void
    {
        $cuota = $this->cuotaCobrada();

        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()->live()->firstOrFail(),
            amount: $cuota->expected_amount,
            idempotencyKey: 'liberar-todo-antes-de-anular',
            motivo: new Reason(HaberesReasonCode::Other, 'Prueba de la guarda.'),
            actorId: $this->operador()->id,
        );

        $this->assertSame('0.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));

        $this->anular($cuota);

        $this->assertSame(ReceiptStatus::Voided, Receipt::query()->firstOrFail()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | La recepción del cobro anulado
    |--------------------------------------------------------------------------
    */

    /** Anular el cobro saca la recepción de los libros: queda revertida, con su motivo. */
    public function test_anular_el_cobro_revierte_la_recepcion(): void
    {
        $this->anular($this->cuotaCobrada());

        $recepcion = FundReceipt::query()->firstOrFail();

        $this->assertNotNull($recepcion->reversal_event_id);
        $this->assertNotNull($recepcion->reversed_at);
        $this->assertSame('wrong_amount', $recepcion->reversal_reason_code);
        $this->assertSame('Importe equivocado: El importe se cargó con un cero de más.', $recepcion->reversal_reason);
    }

    /** Ese dinero nunca entró: no se puede volver a imputar. */
    public function test_la_recepcion_del_cobro_anulado_no_se_vuelve_a_imputar(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->anular($cuota);

        try {
            app(AllocateFundsToInstallment::class)->handle(
                FundReceipt::query()->firstOrFail(),
                $cuota->refresh(),
                $cuota->expected_amount,
                'reimputar-'.Str::random(8),
                $this->operador()->id,
            );
            $this->fail('Imputó una recepción cuyo dinero salió de los libros.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('revertida', $e->errors()['receiptId'][0]);
        }

        $this->assertSame('0.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));
    }

    /** Cobrar de nuevo crea otra recepción, y la cuota queda financiada con esa. */
    public function test_el_cobro_anulado_se_vuelve_a_cobrar_con_otra_recepcion(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->anular($cuota);

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->assertSame(2, FundReceipt::query()->count());
        $this->assertSame(1, FundReceipt::query()->whereNull('reversal_event_id')->count());
        $this->assertSame($cuota->expected_amount, app(InstallmentFunding::class)->allocated($cuota->refresh()));
    }

    /** La migración marca los cobros ya anulados, salvo el que se volvió a imputar. */
    public function test_la_migracion_repara_los_cobros_ya_anulados(): void
    {
        $cuota = $this->cuotaCobrada();
        $recepcion = FundReceipt::query()->firstOrFail();
        $this->liberarTodo($cuota);
        $this->revertirSinMarcar($recepcion);

        $migracion = require database_path('migrations/2026_11_06_050000_mark_voided_cash_collections_as_reversed.php');
        $migracion->up();

        $this->assertNotNull($recepcion->refresh()->reversal_event_id);
        $this->assertSame('other', $recepcion->reversal_reason_code);
        $this->assertSame('Anulado antes de marcar la recepción.', $recepcion->reversal_reason);
    }

    public function test_la_migracion_no_toca_la_recepcion_que_se_volvio_a_imputar(): void
    {
        $cuota = $this->cuotaCobrada();
        $recepcion = FundReceipt::query()->firstOrFail();
        $importe = $cuota->expected_amount;
        $this->liberarTodo($cuota);
        $this->revertirSinMarcar($recepcion);

        // Lo que permitía el error: sin marca, la recepción seguía imputable.
        app(AllocateFundsToInstallment::class)->handle($recepcion, $cuota->refresh(), $importe, 'reimputar-'.Str::random(8), $this->operador()->id);

        $migracion = require database_path('migrations/2026_11_06_050000_mark_voided_cash_collections_as_reversed.php');
        $migracion->up();

        $this->assertNull($recepcion->refresh()->reversal_event_id);
    }

    /** Sin plata ni recibo no hay nada que anular. */
    public function test_sin_plata_ni_recibo_no_hay_nada_que_anular(): void
    {
        $this->expectExceptionMessageMatches('/ningún cobro ni recibo/u');
        $this->anular($this->cuotaEnEfectivo());
    }

    /** Anulado otro día: el ingreso sigue en su fecha y la reversión aparece en la suya. */
    public function test_anular_otro_dia_deja_cada_dia_explicando_su_movimiento(): void
    {
        $hoy = BusinessDate::today();
        $ayer = $hoy->subDay();

        $cuota = $this->cuotaEnEfectivo();
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-de-ayer',
            actorId: $this->operador()->id,
            talonarioNumber: '72190',
            printsTalonarioNumber: true,
            receivedDate: $ayer,
        );
        $recibo = Receipt::query()->firstOrFail();

        $this->anular($cuota->refresh());

        $caja = (int) $recibo->cash_box_id;
        $libro = app(CashDayBook::class);
        $delDia = $libro->entries($caja, $hoy, $hoy)['income'];

        $this->assertSame([$recibo->id], array_column($libro->entries($caja, $ayer, $ayer)['income'], 'receiptId'));
        $this->assertCount(1, $delDia);
        $this->assertSame('Anulación del recibo '.($recibo->talonario_number ?? $recibo->formatted_number), $delDia[0]['number']);
        $this->assertSame('-'.$recibo->amount, $delDia[0]['cash']);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaEnEfectivo(): BeneficiaryInstallment
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

        return $cuota->refresh();
    }

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function cuotaLiberada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobrada();

        $this->liberarTodo($cuota);

        return $cuota->refresh();
    }

    private function cuotaEntregada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobrada();

        app(DeliverToBeneficiary::class)->handle(
            installment: $cuota,
            idempotencyKey: 'entrega-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /** El asiento inverso sin marcar la recepción: lo que hacía antes anular un cobro. */
    private function revertirSinMarcar(FundReceipt $recepcion): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: 'revertir-'.Str::random(8),
            lines: app(InverseEntry::class)->of($recepcion->financial_event_id),
            date: BusinessDate::today(),
            cashBoxId: $recepcion->cash_box_id,
            description: 'Anulado antes de marcar la recepción.',
            actorId: $this->operador()->id,
            reversalOfId: $recepcion->financial_event_id,
            reversalReason: 'Anulado antes de marcar la recepción.',
        );
    }

    private function liberarTodo(BeneficiaryInstallment $cuota): void
    {
        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()->live()->where('beneficiary_installment_id', $cuota->id)->firstOrFail(),
            amount: $cuota->expected_amount,
            idempotencyKey: 'liberar-'.Str::random(8),
            motivo: new Reason(HaberesReasonCode::Other, 'Prueba de la guarda.'),
            actorId: $this->operador()->id,
        );
    }

    private function depositar(BeneficiaryInstallment $cuota): CashToBankTransfer
    {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => BankAccount::query()->create([
                    'label' => 'Cta. Cte. — pruebas',
                    'bank_name' => 'Banco Macro',
                    'account_number' => '310000123456789',
                    'currency' => 'ARS',
                    'is_active' => true,
                ])->id,
                'depositDate' => now(),
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    private function cancelarTraslado(CashToBankTransfer $traslado): CashToBankTransfer
    {
        return app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'El depósito se cargó equivocado.',
            idempotencyKey: 'cancelar-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    private function anular(BeneficiaryInstallment $cuota): void
    {
        app(VoidCashCollection::class)->handle(
            installment: $cuota,
            motivo: new Reason(HaberesReasonCode::WrongAmount, 'El importe se cargó con un cero de más.'),
            idempotencyKey: 'anular-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    /** El saldo de una cuenta del libro: débitos menos créditos. */
    private function saldo(string $cuenta): string
    {
        $neto = DB::table('journal_lines')
            ->where('account_code', $cuenta)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as neto')
            ->value('neto');

        return Decimal::abs(Decimal::scale((string) $neto));
    }
}
