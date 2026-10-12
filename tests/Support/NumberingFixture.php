<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\DeliverAndIssueExpenseReceipt;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Documentos reales, sin duplicar filas emitidas ni desactivar restricciones. */
final class NumberingFixture
{
    public function __construct(public readonly int $actorId) {}

    public function prepare(DocumentType $type): BeneficiaryInstallment
    {
        $unique = (string) random_int(100000000, 999999999);
        $employer = Person::query()->create([
            'type' => 'company', 'legal_name' => 'Empresa de numeración '.$unique,
            'document' => '30'.$unique, 'address' => 'Calle Uno 10', 'phone' => '3874000000', 'is_active' => true,
        ]);
        $person = Person::query()->create([
            'type' => 'individual', 'first_name' => 'Ana', 'last_name' => 'Prueba '.$unique,
            'document' => $unique, 'address' => 'Calle Dos 20', 'phone' => '3874000001', 'is_active' => true,
        ]);
        foreach ([[$employer, 'employer', 'company'], [$person, 'beneficiary', 'individual']] as [$owner, $role, $kind]) {
            DB::table('person_roles')->insert(['person_id' => $owner->id, 'role' => $role, 'person_type' => $kind, 'created_at' => now()]);
        }
        $expediente = Expediente::query()->create([
            'source_system' => 'SiCE v3.0', 'canonical_number' => $unique.'2026', 'display_number' => $unique.'/2026',
            'year' => 2026, 'received_date' => BusinessDate::today()->toDateString(), 'employer_id' => $employer->id,
            'employer_role' => 'employer', 'subject' => 'Validación de numeración', 'status' => 'active',
        ]);
        $haber = $expediente->haberes()->create([
            'concept' => 'Haberes', 'beneficiary_id' => $person->id, 'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '1000.00', 'expected_installment_count' => 1, 'payment_terms' => 'single', 'workflow_status' => 'active',
        ]);
        $installment = $haber->installments()->create([
            'installment_number' => 1, 'expected_amount' => '1000.00',
            'expected_medium' => $type === DocumentType::PaymentOrder ? 'bank' : 'cash', 'workflow_status' => 'active',
        ]);
        $box = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        if ($type === DocumentType::PaymentOrder) {
            PersonBankAccount::query()->create([
                'person_id' => $person->id, 'cbu' => '2850000300000000000017', 'bank_name' => 'Banco Macro',
                'verification_status' => 'verified', 'verified_by' => $this->actorId, 'verified_at' => now(), 'is_active' => true,
            ]);
            $account = BankAccount::query()->firstOrCreate(['account_number' => 'numeracion-test'], [
                'label' => 'Cuenta de prueba', 'bank_name' => 'Banco Macro', 'currency' => 'ARS', 'is_active' => true,
            ]);
            $import = BankStatementImport::query()->create([
                'bank_account_id' => $account->id, 'imported_by' => $this->actorId, 'original_filename' => 'extracto.csv',
                'file_size' => 100, 'file_sha256' => hash('sha256', (string) Str::uuid()),
                'source_format' => 'macro_online_csv', 'parser_version' => 'macro-csv-1',
                'period_from' => BusinessDate::today()->toDateString(), 'period_to' => BusinessDate::today()->toDateString(), 'status' => 'completed',
                'rows_total' => 1, 'rows_valid' => 1, 'rows_new' => 1, 'rows_rejected' => 0, 'rows_duplicate' => 0, 'imported_at' => now(),
            ]);
            $transaction = BankTransaction::query()->create([
                'bank_account_id' => $account->id, 'first_seen_import_id' => $import->id,
                'transaction_date' => BusinessDate::today()->toDateString(), 'amount' => '1000.00', 'direction' => 'credit',
                'operation_id' => $unique, 'description' => 'Crédito de prueba', 'fingerprint' => hash('sha256', (string) Str::uuid()),
            ]);
            $fund = app(RegisterBankFundReceipt::class)->handle($transaction, '1000.00', (string) Str::uuid(), $box, actorId: $this->actorId);
        } else {
            $fund = app(RegisterCashFundReceipt::class)->handle('1000.00', (string) Str::uuid(), $box, BusinessDate::today(), actorId: $this->actorId);
        }
        app(AllocateFundsToInstallment::class)->handle($fund, $installment, '1000.00', (string) Str::uuid(), actorId: $this->actorId);
        if ($type !== DocumentType::ReceiptIncome) {
            app(IssueIncomeReceipt::class)->handle($installment->refresh(), actorId: $this->actorId);
        }

        return $installment->refresh();
    }

    public function emit(DocumentType $type, BeneficiaryInstallment $installment): Receipt|PaymentOrder
    {
        return match ($type) {
            DocumentType::ReceiptIncome => app(IssueIncomeReceipt::class)->handle($installment, actorId: $this->actorId),
            DocumentType::ReceiptExpense => app(DeliverAndIssueExpenseReceipt::class)->handle($installment, (string) Str::uuid(), $this->actorId, paymentDate: BusinessDate::today()),
            DocumentType::PaymentOrder => app(IssuePaymentOrder::class)->handle($installment,
                new IssuePaymentOrderData(null, '10', ReceiptNumberSource::System, IssuePaymentOrderData::DEFAULT_DESTINATION, null), $this->actorId),
        };
    }
}
