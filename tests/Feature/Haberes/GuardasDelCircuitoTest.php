<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CancelExpediente;
use App\Modules\Haberes\Actions\CancelHaber;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
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
            notes: 'Prueba de la guarda.',
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

        app(CancelHaber::class)->handle($cuota->haber, 'Se cargó por error.');
    }

    /** Ni un expediente. */
    public function test_no_se_anula_un_expediente_con_dinero_imputado(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->expectExceptionMessageMatches('/dinero imputado/u');

        app(CancelExpediente::class)->handle($cuota->haber->expediente, 'Se cargó por error.');
    }

    /** Liberada la plata, el haber se anula sin problema. */
    public function test_liberada_la_plata_el_haber_se_anula(): void
    {
        $cuota = $this->cuotaCobrada();

        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()->live()->firstOrFail(),
            amount: $cuota->expected_amount,
            idempotencyKey: 'liberar-para-anular',
            notes: 'Prueba de la guarda.',
            actorId: $this->operador()->id,
        );

        $anulado = app(CancelHaber::class)->handle($cuota->haber, 'Se cargó por error.');

        $this->assertSame('cancelled', $anulado->workflow_status->value);
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
            notes: 'Prueba de la guarda.',
            actorId: $this->operador()->id,
        );

        $this->assertSame('0.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));

        $this->anular($cuota);

        $this->assertSame(ReceiptStatus::Voided, Receipt::query()->firstOrFail()->status);
    }

    /** Sin plata ni recibo no hay nada que anular. */
    public function test_sin_plata_ni_recibo_no_hay_nada_que_anular(): void
    {
        $this->expectExceptionMessageMatches('/ningún cobro ni recibo/u');
        $this->anular($this->cuotaEnEfectivo());
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
            reason: 'El importe se cargó con un cero de más.',
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
