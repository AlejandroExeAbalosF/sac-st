<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cada línea va en la moneda de lo que mueve.
 *
 * Varias operaciones escribían sus líneas sin moneda, y `EntryLine` cae en
 * pesos. El asiento cuadraba igual —cuadra por moneda—, así que un haber
 * en dólares terminaba con `BENEFICIARY_FUNDS` en pesos sin que nada
 * protestara. Ahora la moneda sale de la recepción, del haber o de la
 * cuenta bancaria, y la base exige que coincida.
 */
class MonedaDelAsientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El cobro y la entrega por mostrador de un haber en dólares. */
    public function test_cobrar_y_entregar_en_dolares_asienta_en_dolares(): void
    {
        $cuota = $this->cuotaEnDolares('cash');
        $desde = (int) DB::table('journal_lines')->max('id');

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );
        app(DeliverToBeneficiary::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'entrega-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->assertSame(['USD'], $this->monedasDesde($desde));
        $this->assertSame('USD', FundReceipt::query()->firstOrFail()->currency);
    }

    /** La recepción bancaria toma la moneda de la cuenta, y la asignación la suya. */
    public function test_una_recepcion_en_una_cuenta_en_dolares_es_en_dolares(): void
    {
        $cuota = $this->cuotaEnDolares('bank');
        $desde = (int) DB::table('journal_lines')->max('id');

        $recepcion = app(RegisterBankFundReceipt::class)->handle(
            transaction: $this->creditoEnDolares($cuota->importeEsperado()),
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'recepcion-'.Str::random(8),
            cashBoxId: $this->cajaDeHaberes(),
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'asignacion-'.Str::random(8),
        );

        $this->assertSame('USD', $recepcion->refresh()->currency);
        $this->assertSame(['USD'], $this->monedasDesde($desde));
    }

    /*
    |--------------------------------------------------------------------------
    | La base
    |--------------------------------------------------------------------------
    */

    public function test_la_base_rechaza_una_linea_de_cuota_en_otra_moneda(): void
    {
        $cuota = $this->cuotaEnDolares('cash');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('es en USD');

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsAllocated,
            idempotencyKey: 'en-pesos-'.Str::random(8),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, '100.00')->onCashBox($this->cajaDeHaberes()),
                EntryLine::credit(LedgerAccount::BeneficiaryFunds, '100.00')
                    ->forInstallment($cuota->haber_id, $cuota->id)
                    ->onCashBox($this->cajaDeHaberes()),
            ],
            date: BusinessDate::today(),
            cashBoxId: $this->cajaDeHaberes(),
        );
    }

    public function test_la_base_rechaza_una_recepcion_en_otra_moneda_que_su_asiento(): void
    {
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'en-dolares-'.Str::random(8),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '100.00')->in(Currency::Usd)->onCashBox($this->cajaDeHaberes()),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '100.00')->in(Currency::Usd)->onCashBox($this->cajaDeHaberes()),
            ],
            date: BusinessDate::today(),
            cashBoxId: $this->cajaDeHaberes(),
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('su asiento tiene líneas en USD');

        DB::table('fund_receipts')->insert([
            'financial_event_id' => $evento->id,
            'cash_box_id' => $this->cajaDeHaberes(),
            'medium' => 'cash',
            'origin' => 'received',
            'amount' => '100.00',
            'currency' => 'ARS',
            'received_date' => BusinessDate::today()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_la_moneda_de_una_recepcion_no_se_edita(): void
    {
        $cuota = $this->cuotaEnDolares('cash');
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se editan');

        DB::table('fund_receipts')->update(['currency' => 'ARS']);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaEnDolares(string $medio): BeneficiaryInstallment
    {
        $haber = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail();

        $haber->forceFill(['currency' => 'USD'])->save();

        $cuota = $haber->installments()->orderBy('installment_number')->firstOrFail();
        $cuota->forceFill(['expected_medium' => $medio])->save();

        return $cuota->refresh();
    }

    /** @return list<string> */
    private function monedasDesde(int $lineaId): array
    {
        /** @var list<string> $monedas */
        $monedas = DB::table('journal_lines')
            ->where('id', '>', $lineaId)
            ->distinct()
            ->pluck('currency')
            ->all();

        return $monedas;
    }

    private function cajaDeHaberes(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function creditoEnDolares(string $importe): BankTransaction
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Caja de ahorro en dólares',
            'bank_name' => 'Banco Macro',
            'account_number' => '4100009876543',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $fecha = BusinessDate::today()->toDateString();

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
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
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }
}
