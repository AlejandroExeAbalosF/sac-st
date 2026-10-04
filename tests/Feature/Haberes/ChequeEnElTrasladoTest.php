<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\RegisterCashPayment;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * El cheque viaja con el traslado: su estado dice dónde está el papel.
 *
 * Depositar un cheque lo sacaba de `CHEQUES_IN_CUSTODY` en el libro pero lo
 * dejaba `in_custody` en su recepción, así que la planilla de caja lo
 * seguía listando. Y cancelar el traslado lo devolvía a la caja de
 * efectivo, no a custodia.
 */
class ChequeEnElTrasladoTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
        $this->operador = $this->operador();
    }

    public function test_depositar_saca_el_cheque_de_custodia(): void
    {
        $cuota = $this->cuotaCobradaConUnCheque();

        $traslado = $this->depositar($cuota);

        $this->assertSame(ChequeStatus::Deposited, $this->cheques()->first()?->cheque_status);
        $this->assertSame(
            ['CASH_IN_TRANSIT', 'CHEQUES_IN_CUSTODY'],
            $this->cuentasDe($traslado->deposit_event_id),
        );
    }

    /**
     * Una cuota cubierta con dos cheques los lleva a los dos.
     *
     * Antes se tomaba la primera asignación: el traslado salía por la
     * mitad de la cuota y el segundo cheque quedaba en custodia.
     */
    public function test_una_cuota_con_dos_cheques_los_deposita_a_los_dos(): void
    {
        $cuota = $this->cuotaCobradaConDosCheques();

        $traslado = $this->depositar($cuota);

        $this->assertSame($cuota->importeEsperado(), $traslado->amount);
        $this->assertSame(2, CashToBankTransferItem::query()->where('cash_to_bank_transfer_id', $traslado->id)->count());
        $this->assertSame(
            [ChequeStatus::Deposited, ChequeStatus::Deposited],
            $this->cheques()->pluck('cheque_status')->all(),
        );
        $this->assertTrue(Decimal::equals(
            $this->saldo(LedgerAccount::ChequesInCustody, $traslado->cash_box_id),
            '0',
        ));
    }

    /**
     * Cancelar devuelve el cheque a custodia, no a la caja de efectivo.
     *
     * El inverso estaba escrito a mano contra `CASH_ON_HAND`: el cheque
     * volvía como si fuera billetes, y el arqueo de efectivo sobraba
     * exactamente lo que faltaba en custodia.
     */
    public function test_cancelar_devuelve_los_cheques_a_custodia(): void
    {
        $cuota = $this->cuotaCobradaConDosCheques();
        $caja = (int) $this->cheques()->value('cash_box_id');
        $efectivoAntes = $this->saldo(LedgerAccount::CashOnHand, $caja);

        $traslado = $this->depositar($cuota);
        $this->cancelar($traslado);

        $this->assertSame(
            [ChequeStatus::InCustody, ChequeStatus::InCustody],
            $this->cheques()->pluck('cheque_status')->all(),
        );
        $this->assertSame($cuota->importeEsperado(), $this->saldo(LedgerAccount::ChequesInCustody, $caja));
        $this->assertSame($efectivoAntes, $this->saldo(LedgerAccount::CashOnHand, $caja));

        // Y se puede volver a depositar: el cheque está otra vez en la caja.
        $this->depositar($cuota->refresh());
        $this->assertSame(
            [ChequeStatus::Deposited, ChequeStatus::Deposited],
            $this->cheques()->pluck('cheque_status')->all(),
        );
    }

    /** El efectivo sigue volviendo a la caja de efectivo. */
    public function test_cancelar_un_traslado_de_efectivo_lo_devuelve_a_la_caja(): void
    {
        $cuota = $this->cuota('cash');
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador->id,
        );

        $traslado = $this->depositar($cuota->refresh());
        $evento = $this->cancelar($traslado);

        $this->assertSame(
            ['CASH_IN_TRANSIT', 'CASH_ON_HAND'],
            $this->cuentasDe((int) $evento),
        );
    }

    /**
     * La acreditación deja los cheques acreditados y el dinero en el banco
     * de la caja.
     *
     * El débito a `BANK_ACCOUNT` iba sin caja: el depósito acreditado no
     * sumaba en la columna «banco» de nadie, y una transferencia que
     * después lo pagara dejaba esa columna en negativo.
     */
    public function test_acreditar_deja_los_cheques_acreditados_y_el_banco_en_la_caja(): void
    {
        $cuota = $this->cuotaCobradaConDosCheques();
        $traslado = $this->depositar($cuota);

        $confirmado = app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->credito($traslado->amount, '2026-08-26'),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador->id,
        );

        $this->assertSame(
            [ChequeStatus::Cleared, ChequeStatus::Cleared],
            $this->cheques()->pluck('cheque_status')->all(),
        );
        $this->assertSame(
            0,
            DB::table('journal_lines')
                ->where('financial_event_id', $confirmado->credit_event_id)
                ->whereNull('cash_box_id')
                ->count(),
        );
        $this->assertSame($cuota->importeEsperado(), $this->saldo(LedgerAccount::BankAccount, $traslado->cash_box_id));
    }

    /**
     * Una asignación liberada del todo no es «la» de la cuota.
     *
     * Se tomaba la primera asignación sin mirar si seguía en pie: con un
     * cobro liberado y otro nuevo, el traslado apuntaba al viejo.
     */
    public function test_una_asignacion_liberada_no_se_deposita(): void
    {
        $cuota = $this->cuota('cash');
        $primera = $this->cobrar($cuota, $cuota->importeEsperado(), PaymentMedium::Cash);

        app(UnallocateFunds::class)->handle(
            allocation: $primera,
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'liberar-'.Str::random(8),
            notes: 'Se cargó en la cuota equivocada.',
            actorId: $this->operador->id,
        );

        $segunda = $this->cobrar($cuota->refresh(), $cuota->importeEsperado(), PaymentMedium::Cash);
        $this->emitirRecibo($cuota);

        $traslado = $this->depositar($cuota->refresh());

        $this->assertSame(
            [$segunda->id],
            CashToBankTransferItem::query()
                ->where('cash_to_bank_transfer_id', $traslado->id)
                ->pluck('funding_allocation_id')
                ->all(),
        );
    }

    /** Un cheque se deposita entero: la cuota tiene que tenerlo completo. */
    public function test_un_cheque_que_la_cuota_tiene_en_parte_no_se_deposita(): void
    {
        $cuota = $this->cuotaConUnaParteDelCheque();

        try {
            $this->depositar($cuota);
            $this->fail('Depositó una parte de un cheque.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('un cheque se deposita entero', $e->errors()['installmentId'][0]);
        }

        $this->assertSame(ChequeStatus::InCustody, $this->cheques()->first()?->cheque_status);
    }

    /*
    |--------------------------------------------------------------------------
    | La entrega por mostrador
    |--------------------------------------------------------------------------
    */

    /** Los dos cheques de la cuota cambian de manos. */
    public function test_entregar_una_cuota_con_dos_cheques_entrega_los_dos(): void
    {
        $cuota = $this->cuotaCobradaConDosCheques();

        $this->entregar($cuota);

        $this->assertSame(
            [ChequeStatus::Delivered, ChequeStatus::Delivered],
            $this->cheques()->pluck('cheque_status')->all(),
        );
    }

    /**
     * Un cheque se entrega entero, igual que se deposita.
     *
     * Entregarlo con solo una parte en la cuota le daba al beneficiario
     * el papel completo y dejaba el resto en `CHEQUES_IN_CUSTODY` sin
     * ningún cheque que lo respalde.
     */
    public function test_un_cheque_que_la_cuota_tiene_en_parte_no_se_entrega(): void
    {
        $cuota = $this->cuotaConUnaParteDelCheque();

        // La tarjeta lo dice antes de que alguien lo intente.
        $estado = app(DisbursementEligibility::class)->for($cuota);
        $this->assertStringContainsString('un cheque se entrega entero', (string) $estado->blockedReason);

        try {
            $this->entregar($cuota);
            $this->fail('Entregó una parte de un cheque.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('un cheque se entrega entero', $e->errors()['installmentId'][0]);
        }

        $this->assertSame(ChequeStatus::InCustody, $this->cheques()->first()?->cheque_status);
        $this->assertSame(0, Disbursement::query()->count());
    }

    /** La regla es del cheque: el efectivo se entrega aunque se haya liberado una parte. */
    public function test_el_efectivo_con_una_parte_liberada_se_entrega(): void
    {
        $cuota = $this->cuota('cash');
        $this->cobrar($cuota, $cuota->importeEsperado(), PaymentMedium::Cash);
        $this->emitirRecibo($cuota);
        $this->liberarElExcedente($cuota->refresh());

        $this->entregar($cuota->refresh());

        $this->assertSame(1, Disbursement::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La base
    |--------------------------------------------------------------------------
    */

    public function test_la_base_rechaza_un_cheque_depositado_sin_traslado(): void
    {
        $this->cuotaCobradaConUnCheque();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sin ningún traslado vigente');

        $this->cheques()->firstOrFail()->forceFill(['cheque_status' => ChequeStatus::Deposited])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_rechaza_un_cheque_en_custodia_con_traslado_vigente(): void
    {
        $this->depositar($this->cuotaCobradaConUnCheque());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('viajó en un traslado vigente');

        $this->cheques()->firstOrFail()->forceFill(['cheque_status' => ChequeStatus::InCustody])->save();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_base_rechaza_cancelar_el_traslado_sin_devolver_el_cheque(): void
    {
        $traslado = $this->depositar($this->cuotaCobradaConUnCheque());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('sin ningún traslado vigente');

        DB::table('cash_to_bank_transfers')->where('id', $traslado->id)->update(['status' => 'cancelled']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuota(string $medio = 'cheque'): BeneficiaryInstallment
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

        $cuota->forceFill(['expected_medium' => $medio])->save();

        return $cuota->refresh();
    }

    private function cuotaCobradaConUnCheque(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $this->cobrar($cuota, $cuota->importeEsperado(), PaymentMedium::Cheque, '00012345');
        $this->emitirRecibo($cuota);

        return $cuota->refresh();
    }

    private function cuotaCobradaConDosCheques(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $primero = Decimal::scale(bcdiv($importe, '2', 2));

        $this->cobrar($cuota, $primero, PaymentMedium::Cheque, '00012345');
        $this->cobrar($cuota->refresh(), Decimal::sub($importe, $primero), PaymentMedium::Cheque, '00012346');
        $this->emitirRecibo($cuota);

        return $cuota->refresh();
    }

    /** La cuota baja y el excedente se libera: le queda una parte del cheque. */
    private function cuotaConUnaParteDelCheque(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobradaConUnCheque();
        $this->liberarElExcedente($cuota);

        return $cuota->refresh();
    }

    private function liberarElExcedente(BeneficiaryInstallment $cuota): void
    {
        $cuota->forceFill(['expected_amount' => Decimal::sub($cuota->importeEsperado(), '1000')])->save();

        app(UnallocateFunds::class)->handle(
            allocation: FundingAllocation::query()
                ->live()
                ->where('beneficiary_installment_id', $cuota->id)
                ->firstOrFail(),
            amount: '1000.00',
            idempotencyKey: 'excedente-'.Str::random(8),
            notes: 'La cuota se corrigió a la baja.',
            actorId: $this->operador->id,
        );
    }

    private function entregar(BeneficiaryInstallment $cuota): void
    {
        app(DeliverToBeneficiary::class)->handle(
            installment: $cuota,
            idempotencyKey: 'entrega-'.Str::random(8),
            actorId: $this->operador->id,
        );
    }

    private function cobrar(
        BeneficiaryInstallment $cuota,
        string $importe,
        PaymentMedium $medio,
        ?string $cheque = null,
    ): FundingAllocation {
        return app(RegisterCashPayment::class)->handle(
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'cobro-'.Str::random(8),
            cashBoxId: $this->cajaDeHaberes(),
            receivedDate: now()->parse('2026-08-20'),
            medium: $medio,
            actorId: $this->operador->id,
            cheque: $cheque === null ? null : ['number' => $cheque, 'bank' => 'Banco Macro', 'issueDate' => null],
        );
    }

    private function emitirRecibo(BeneficiaryInstallment $cuota): void
    {
        app(IssueIncomeReceipt::class)->handle(
            installment: $cuota->refresh(),
            actorId: $this->operador->id,
        );
    }

    private function depositar(BeneficiaryInstallment $cuota): CashToBankTransfer
    {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => now()->parse('2026-08-25'),
                'notes' => null,
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-'.Str::random(8),
            actorId: $this->operador->id,
        );
    }

    /** @return int|null El asiento de la cancelación. */
    private function cancelar(CashToBankTransfer $traslado): ?int
    {
        app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'El ticket era de otro depósito.',
            idempotencyKey: 'cancelacion-'.Str::random(8),
            actorId: $this->operador->id,
        );

        return DB::table('financial_events')
            ->where('reversal_of_id', $traslado->deposit_event_id)
            ->value('id');
    }

    /** @return Builder<FundReceipt> */
    private function cheques(): Builder
    {
        return FundReceipt::query()->where('medium', PaymentMedium::Cheque->value)->orderBy('id');
    }

    private function saldo(LedgerAccount $cuenta, int $caja): string
    {
        return app(CashBalance::class)->of($cuenta, $caja);
    }

    private function cajaDeHaberes(): int
    {
        return (int) DB::table('cash_boxes')->where('code', 'haberes')->value('id');
    }

    /** @return list<string> */
    private function cuentasDe(int $eventoId): array
    {
        /** @var list<string> $cuentas */
        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $eventoId)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        return $cuentas;
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '310000123456789'],
            [
                'label' => 'Cta. Cte. 2693 — Haberes',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function credito(string $importe, string $fecha): BankTransaction
    {
        $cuenta = $this->cuenta();

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => $fecha,
            'period_to' => $fecha,
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $importacion->id,
            'transaction_date' => $fecha,
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Deposito de cheques',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }
}
