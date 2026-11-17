<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Models\PersonBankAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Una cuota de Haberes que sale por transferencia, con su Orden emitida.
 *
 * El escenario de `HaberesDemoSeeder`: la cuota entra en efectivo, se
 * deposita y el banco acredita el depósito (§2.4.7), con lo que su pago pasa
 * al circuito bancario. Lo usan la prueba del egreso por transferencia y la
 * de concurrencia de la Orden.
 */
trait BuildsTransferInstallment
{
    private const CUENTA_ORGANISMO = '23456789';

    private const CBU_VALIDO = '2850000300000000000017';

    /** La moneda del haber y de la cuenta del organismo en el escenario. */
    protected string $moneda = 'ARS';

    /** Una cuota con su Orden emitida, lista para que el organismo pague. */
    protected function cuotaConOrden(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConEfectivoAcreditado();

        $cuota->loadMissing('haber.beneficiary');
        $cuota->haber->beneficiary->forceFill([
            'document' => '38.357.040',
            'address' => 'B° San Jorge Mza 54',
            'phone' => '387-6004216',
        ])->save();

        PersonBankAccount::query()->create([
            'person_id' => $cuota->haber->beneficiary->id,
            'cbu' => self::CBU_VALIDO,
            'bank_name' => 'Banco Macro',
            'verification_status' => 'verified',
            'verified_by' => $this->operador()->id,
            'verified_at' => now(),
            'is_active' => true,
        ]);

        app(IssuePaymentOrder::class)->handle(
            $cuota->refresh(),
            new IssuePaymentOrderData(
                beneficiaryBankAccountId: null,
                cbuFolio: '19',
                incomeReceiptNumberSource: ReceiptNumberSource::System,
                paseDestination: IssuePaymentOrderData::DEFAULT_DESTINATION,
                treasurerId: null,
            ),
            $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /** El circuito completo del efectivo que nadie retiró (§2.4.7). */
    protected function cuotaConEfectivoAcreditado(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->movimientoDe(
                $traslado->amount,
                TransactionDirection::Credit,
                '2026-05-28',
            ),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    protected function cuotaCobrada(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        $cuota = $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();
        $cuota->haber->forceFill(['currency' => $this->moneda])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
            /*
             * Sin fecha explícita el cobro queda con la de hoy, y el resto
             * del escenario transcurre en mayo y junio: el depósito del
             * 28/05 salía de un cajón cuyo ingreso todavía no había
             * ocurrido, y el saldo de efectivo daba negativo.
             */
            receivedDate: CarbonImmutable::parse('2026-05-20'),
        );

        return $cuota->refresh();
    }

    protected function depositar(BeneficiaryInstallment $cuota): CashToBankTransfer
    {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $this->cuentaDelOrganismo()->id,
                'depositDate' => now()->parse('2026-05-28'),
                'depositTime' => '10:35:00',
                'operationNumber' => '995979830',
                'terminal' => 'T-014',
                'notes' => null,
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    /** El débito con que el organismo pagó, por el importe de la Orden. */
    protected function debito(BeneficiaryInstallment $cuota): BankTransaction
    {
        return $this->movimiento($cuota, TransactionDirection::Debit);
    }

    protected function movimiento(
        BeneficiaryInstallment $cuota,
        TransactionDirection $direccion,
    ): BankTransaction {
        $orden = PaymentOrder::query()
            ->where('beneficiary_installment_id', $cuota->id)
            ->firstOrFail();

        /*
         * El día siguiente a la Orden. El organismo no transfiere antes de
         * que se le pida, y el Action lo comprueba: una fecha fija se
         * volvería anterior en cuanto la Orden se emita con `now()`.
         */
        return $this->movimientoDe(
            $orden->amount,
            $direccion,
            $orden->order_date->copy()->addDay()->toDateString(),
        );
    }

    protected function movimientoDe(
        string $importe,
        TransactionDirection $direccion,
        string $fecha,
    ): BankTransaction {
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => $fecha,
            'amount' => $importe,
            'direction' => $direccion,
            'operation_id' => '83690105',
            'description' => $direccion === TransactionDirection::Debit
                ? 'Transferencia a terceros'
                : 'Deposito en efectivo',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }

    protected function cuentaDelOrganismo(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => self::CUENTA_ORGANISMO.$this->moneda],
            [
                'label' => 'Cta. Cte. 2693 — Haberes en consignación',
                'bank_name' => 'Banco Macro',
                'currency' => $this->moneda,
                'is_active' => true,
            ],
        );
    }

    protected function importacion(BankAccount $cuenta): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-05-28',
            'period_to' => '2026-06-30',
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);
    }

    protected function emitirOrden(BeneficiaryInstallment $cuota): PaymentOrder
    {
        return app(IssuePaymentOrder::class)->handle(
            $cuota->refresh(),
            new IssuePaymentOrderData(
                beneficiaryBankAccountId: null,
                cbuFolio: '19',
                incomeReceiptNumberSource: ReceiptNumberSource::System,
                paseDestination: IssuePaymentOrderData::DEFAULT_DESTINATION,
                treasurerId: null,
            ),
            $this->operador()->id,
        );
    }
}
