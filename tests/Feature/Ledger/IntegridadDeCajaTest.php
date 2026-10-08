<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\BankMovementAfterOpening;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\AdjustCashDifference;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegenerateCashSheet;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Excel\CashSheetBuilder;
use App\Modules\Ledger\Excel\CashSheetEvidence;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class IntegridadDeCajaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        try {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            parent::tearDown();
        }
    }

    private function validarRestricciones(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function cuotaConCheque(): BeneficiaryInstallment
    {
        $empresa = Person::query()->create([
            'type' => 'company', 'legal_name' => 'COBERTURA DE SALUD SA',
            'document' => '30687654321', 'is_active' => true,
        ]);
        $trabajador = Person::query()->create([
            'type' => 'individual', 'first_name' => 'Héctor', 'last_name' => 'Tintilay Tolaba',
            'document' => '18455233', 'is_active' => true,
        ]);

        foreach ([[$empresa, 'employer', 'company'], [$trabajador, 'beneficiary', 'individual']] as [$p, $rol, $tipo]) {
            DB::table('person_roles')->insertOrIgnore([
                'person_id' => $p->id, 'role' => $rol, 'person_type' => $tipo, 'created_at' => now(),
            ]);
        }

        $expediente = Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => '1310102023',
            'display_number' => '131010/2023',
            'year' => 2023,
            'received_date' => '2023-06-01',
            'employer_id' => $empresa->id,
            'employer_role' => 'employer',
            'subject' => 'Haberes en consignación',
            'status' => ExpedienteStatus::Active,
        ]);

        $haber = $expediente->haberes()->create([
            'concept' => 'Indemnización',
            'beneficiary_id' => $trabajador->id,
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '50000.00',
            'expected_installment_count' => 1,
            'payment_terms' => PaymentTerms::Single,
            'workflow_status' => HaberWorkflowStatus::Active,
        ]);

        return $haber->installments()->create([
            'installment_number' => 1,
            'expected_amount' => '50000.00',
            'expected_medium' => ExpectedMedium::Cheque,
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);
    }

    private function cobrarCheque(Currency $currency = Currency::Ars): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConCheque();
        $cuota->haber->forceFill(['currency' => $currency->value])->save();
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'auditoria:cheque',
            talonarioNumber: '64685',
            receivedDate: CarbonImmutable::parse('2026-06-02'),
            cheque: ['number' => '61197673', 'bank' => 'CREDICOOP', 'issueDate' => '2026-06-01'],
        );

        return $cuota;
    }

    public function test_la_planilla_usd_no_incluye_recibos_ars(): void
    {
        $this->abrirLibrosSinSaldo(Currency::Ars, Currency::Usd);
        $this->cobrarCheque();
        $actor = $this->quienAbre();
        $count = app(RecordCashCount::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), [], Currency::Usd, $actor);
        app(ReviewCashCount::class)->handle($count, $actor);
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), currency: Currency::Usd);
        $sheet = app(CashSheetBuilder::class)->build($closing);
        $this->assertSame('0.00', $closing->received_cheques);
        $this->assertCount(0, $sheet->income);
    }

    public function test_el_inventario_congelado_conserva_el_cheque_depositado_despues(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuota = $this->cobrarCheque();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'));
        $this->assertCount(1, app(CashSheetBuilder::class)->build($closing)->cheques);
        $bank = BankAccount::query()->create(['label' => 'Auditoría', 'bank_name' => 'Macro', 'account_number' => 'AUD-1', 'currency' => 'ARS', 'is_active' => true]);
        app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: ['bankAccountId' => $bank->id, 'depositDate' => CarbonImmutable::parse('2026-06-03')],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'auditoria:deposito',
        );
        $this->assertSame('50000.00', $closing->closing_cheques);
        $this->assertCount(1, app(CashSheetBuilder::class)->build($closing)->cheques);
        $first = app(ExportCashSheet::class)->handle($closing);
        $second = app(RegenerateCashSheet::class)->handle($closing, $this->quienAbre(), 'Verificar que el inventario histórico se conserva.');
        $this->assertNotSame($first->id, $second->id);
        foreach ([$first, $second] as $file) {
            $excel = IOFactory::load(Storage::disk('local')->path($file->object_key));
            $values = array_merge(...$excel->getSheetByName('REVERSO 020626')->toArray());
            $this->assertContains('61197673', $values);
            $excel->disconnectWorksheets();
        }
    }

    public function test_un_arqueo_superado_no_permite_imputar_de_nuevo_el_faltante(): void
    {
        $this->abrirLibrosSinSaldo();
        $date = CarbonImmutable::parse('2026-06-02');
        app(RegisterCashFundReceipt::class)->handle(amount: '1000.00', idempotencyKey: 'auditoria:efectivo', cashBoxId: $this->caja(), receivedDate: $date);
        $actor = $this->quienAbre();
        $counts = [];
        for ($i = 0; $i < 2; $i++) {
            $count = app(RecordCashCount::class)->handle(cashBoxId: $this->caja(), countedOn: $date, denominations: [100 => 9], actorId: $actor, explanation: 'Faltan cien pesos al contar el mismo cajón.');
            $counts[] = app(ReviewCashCount::class)->handle($count, $actor);
        }
        app(AdjustCashDifference::class)->handle($counts[1], $actor);
        $this->assertSame('900.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja()));
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('turno posterior');
        app(AdjustCashDifference::class)->handle($counts[0], $actor);
    }

    public function test_un_credito_anterior_a_la_apertura_no_se_registra_como_ingreso(): void
    {
        $bank = BankAccount::query()->create(['label' => 'Auditoría', 'bank_name' => 'Macro', 'account_number' => 'AUD-2', 'currency' => 'ARS', 'is_active' => true]);
        $this->actingAs($this->operador())->post(route('banco.extractos.store'), [
            'bankAccountId' => $bank->id,
            'file' => new UploadedFile(base_path('tests/Fixtures/Banking/macro-online.csv'), 'macro-online.csv', null, null, true),
        ])->assertSessionHasNoErrors();
        $credit = BankTransaction::query()->where('direction', TransactionDirection::Credit)->firstOrFail();
        $date = CarbonImmutable::parse($credit->transaction_date)->addDay();
        app(RegisterOpeningBalance::class)->handle(cashBoxId: $this->caja(), balances: [LedgerAccount::BankAccount->value => '473191.20'], date: $date, actorId: $this->quienAbre(), bankAccountId: $bank->id);
        $this->expectException(ValidationException::class);
        // Dice por qué y cuál es la salida: dejarlo fuera del circuito.
        $this->expectExceptionMessage('dejalo fuera del circuito con el motivo «incluido en la apertura»');
        app(RegisterBankFundReceipt::class)->handle(transaction: $credit, amount: '473191.20', idempotencyKey: 'auditoria:credito-viejo', cashBoxId: $this->caja());
    }

    public function test_la_planilla_usd_conserva_sus_recibos_y_su_formato(): void
    {
        $this->abrirLibrosSinSaldo(Currency::Ars, Currency::Usd);
        $this->cobrarCheque(Currency::Usd);
        $actor = $this->quienAbre();
        $count = app(RecordCashCount::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), [], Currency::Usd, $actor);
        app(ReviewCashCount::class)->handle($count, $actor);
        $this->validarRestricciones();
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), currency: Currency::Usd);
        $sheet = app(CashSheetBuilder::class)->build($closing);
        $this->assertCount(1, $sheet->income);
        $this->assertSame('50000.00', $sheet->income[0]['cheques']);
        $attachment = app(ExportCashSheet::class)->handle($closing);
        $excel = IOFactory::load(Storage::disk('local')->path($attachment->object_key));
        $this->assertStringContainsString('USD', $excel->getSheet(0)->getCell('A1')->getValue());
        $this->assertStringContainsString('US$', $excel->getSheet(0)->getStyle('C5')->getNumberFormat()->getFormatCode());
        $excel->disconnectWorksheets();
    }

    private function arqueoConFaltante(): CashCount
    {
        $this->abrirLibrosSinSaldo();
        $date = CarbonImmutable::parse('2026-06-02');
        app(RegisterCashFundReceipt::class)->handle(amount: '1000.00', idempotencyKey: 'efectivo', cashBoxId: $this->caja(), receivedDate: $date);
        $count = app(RecordCashCount::class)->handle(cashBoxId: $this->caja(), countedOn: $date, denominations: [100 => 9], actorId: $this->quienAbre(), explanation: 'Faltan cien pesos.');

        return app(ReviewCashCount::class)->handle($count, $this->quienAbre());
    }

    public function test_un_turno_posterior_en_borrador_impide_ajustar_el_anterior(): void
    {
        $count = $this->arqueoConFaltante();
        app(RecordCashCount::class)->handle(cashBoxId: $this->caja(), countedOn: $count->counted_on, denominations: [100 => 9], explanation: 'Nuevo recuento del faltante.');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('turno posterior');
        app(AdjustCashDifference::class)->handle($count, $this->quienAbre());
    }

    public function test_un_ingreso_posterior_al_conteo_exige_recontar_antes_de_ajustar(): void
    {
        $count = $this->arqueoConFaltante();
        app(RegisterCashFundReceipt::class)->handle(amount: '100.00', idempotencyKey: 'nuevo-ingreso', cashBoxId: $this->caja(), receivedDate: $count->counted_on);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('saldo del libro cambió');
        app(AdjustCashDifference::class)->handle($count, $this->quienAbre());
    }

    public function test_la_base_rechaza_imputar_un_turno_superado(): void
    {
        $count = $this->arqueoConFaltante();
        app(RecordCashCount::class)->handle(cashBoxId: $this->caja(), countedOn: $count->counted_on, denominations: [100 => 9], explanation: 'Nuevo recuento del faltante.');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('turno posterior');
        DB::transaction(function () use ($count): void {
            $event = app(PostJournalEntry::class)->handle(FinancialEventType::CashAdjustment, 'ajuste-directo', [
                EntryLine::debit(LedgerAccount::CashDifference, '100.00')->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, '100.00')->onCashBox($this->caja()),
            ], $count->counted_on, $this->caja());
            DB::table('cash_counts')->where('id', $count->id)->update(['status' => 'adjusted', 'adjustment_event_id' => $event->id]);
        });
    }

    private function banco(): BankAccount
    {
        return BankAccount::query()->create(['label' => 'Auditoría', 'bank_name' => 'Macro', 'account_number' => 'AUD-3', 'currency' => 'ARS', 'is_active' => true]);
    }

    private function movimiento(BankAccount $bank, TransactionDirection $direction, string $date): BankTransaction
    {
        $import = BankStatementImport::query()->create([
            'bank_account_id' => $bank->id, 'imported_by' => $this->quienAbre(), 'original_filename' => 'extracto.csv',
            'file_size' => 100, 'file_sha256' => hash('sha256', uniqid()), 'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1', 'period_from' => $date, 'period_to' => $date, 'status' => 'completed',
            'rows_total' => 1, 'rows_valid' => 1, 'rows_rejected' => 0, 'rows_new' => 1, 'rows_duplicate' => 0, 'imported_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'bank_account_id' => $bank->id, 'first_seen_import_id' => $import->id, 'transaction_date' => $date,
            'amount' => '100.00', 'direction' => $direction, 'operation_id' => 'AUD', 'description' => 'Movimiento de prueba', 'fingerprint' => hash('sha256', uniqid()),
        ]);
    }

    public function test_la_base_rechaza_creditos_y_debitos_previos_al_corte(): void
    {
        $bank = $this->banco();
        app(RegisterOpeningBalance::class)->handle($this->caja(), [], CarbonImmutable::parse('2026-06-02'), $this->quienAbre(), declaredEmpty: true);
        foreach ([TransactionDirection::Credit, TransactionDirection::Debit] as $direction) {
            $movement = $this->movimiento($bank, $direction, '2026-06-01');
            try {
                DB::transaction(function () use ($movement, $direction): void {
                    $event = app(PostJournalEntry::class)->handle(FinancialEventType::FundsReceived, 'movimiento-'.$movement->id, [
                        EntryLine::debit(LedgerAccount::BankAccount, '100.00')->onCashBox($this->caja())->onBankAccount($movement->bank_account_id),
                        EntryLine::credit(LedgerAccount::UnassignedFunds, '100.00')->onCashBox($this->caja()),
                    ], CarbonImmutable::parse('2026-06-02'), $this->caja());
                    BankTransactionAllocation::query()->create(['bank_transaction_id' => $movement->id, 'financial_event_id' => $event->id,
                        'allocation_role' => $direction === TransactionDirection::Credit ? 'funds_received' : 'payment_confirmation', 'amount' => '100.00']);
                });
                $this->fail('La base admitió un movimiento anterior a la apertura.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('anterior a la apertura', $e->getMessage());
            }
        }
    }

    public function test_el_dia_de_apertura_admite_el_credito_y_el_corte_es_por_moneda(): void
    {
        $bank = $this->banco();
        app(RegisterOpeningBalance::class)->handle($this->caja(), [], CarbonImmutable::parse('2026-06-02'), $this->quienAbre(), declaredEmpty: true);
        app(RegisterOpeningBalance::class)->handle($this->caja(), [], CarbonImmutable::parse('2026-06-05'), $this->quienAbre(), currency: Currency::Usd, declaredEmpty: true);
        $movement = $this->movimiento($bank, TransactionDirection::Credit, '2026-06-02');
        $receipt = app(RegisterBankFundReceipt::class)->handle($movement, '100.00', 'credito-apertura', $this->caja());
        $this->assertSame('100.00', $receipt->amount);
        $this->expectException(ValidationException::class);
        app(BankMovementAfterOpening::class)->assertAllows($movement, $this->caja(), Currency::Usd);
    }

    public function test_un_cierre_tardio_reconstruye_los_cheques_antes_del_deposito(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuota = $this->cobrarCheque();
        $bank = $this->banco();
        app(DepositCashToBank::class)->handle($cuota, ['bankAccountId' => $bank->id, 'depositDate' => CarbonImmutable::parse('2026-06-03')], UploadedFile::fake()->image('ticket.jpg'), 'traslado-tardio');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $this->validarRestricciones();
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'));
        $this->assertCount(1, app(CashSheetBuilder::class)->build($closing)->cheques);
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-03');
        $after = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-03'));
        $this->assertCount(0, app(CashSheetBuilder::class)->build($after)->cheques);
        $month = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-30'), PeriodType::Monthly);
        $sheets = app(CashSheetEvidence::class)->workbook($month);
        $this->assertCount(3, $sheets);
        $this->assertCount(1, $sheets[0]->cheques);
        $this->assertCount(0, $sheets[1]->cheques);
        $this->assertCount(0, $sheets[2]->cheques);
    }

    public function test_reabrir_y_cerrar_conserva_ambas_versiones_del_detalle(): void
    {
        $this->abrirLibrosSinSaldo();
        $this->cobrarCheque();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $this->validarRestricciones();
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'));
        $original = DB::table('period_closing_evidence')->where('period_closing_id', $closing->id)->first();
        app(ReopenPeriod::class)->handle($closing, $this->quienAbre(), 'Verificación del detalle del cierre.');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'));
        $this->assertSame(2, $closing->evidence_version);
        $this->assertSame(2, DB::table('period_closing_evidence')->where('period_closing_id', $closing->id)->count());
        $this->assertSame($original->payload, DB::table('period_closing_evidence')->where('id', $original->id)->value('payload'));
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('period_closing_evidence')->where('id', $original->id)->update(['payload' => '{}']));
    }

    public function test_la_base_tambien_rechaza_el_ajuste_de_un_saldo_desactualizado(): void
    {
        $count = $this->arqueoConFaltante();
        app(RegisterCashFundReceipt::class)->handle(amount: '100.00', idempotencyKey: 'nuevo', cashBoxId: $this->caja(), receivedDate: $count->counted_on);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('saldo del libro cambio');
        DB::transaction(function () use ($count): void {
            $event = app(PostJournalEntry::class)->handle(FinancialEventType::CashAdjustment, 'ajuste-directo', [
                EntryLine::debit(LedgerAccount::CashDifference, '100.00')->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, '100.00')->onCashBox($this->caja()),
            ], $count->counted_on, $this->caja());
            DB::table('cash_counts')->where('id', $count->id)->update(['status' => 'adjusted', 'adjustment_event_id' => $event->id]);
        });
    }

    public function test_un_cierre_anterior_sin_evidencia_no_se_reconstruye_desde_datos_actuales(): void
    {
        $this->abrirLibrosSinSaldo();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        // Simula una fila creada por una versión anterior a la migración.
        $old = PeriodClosing::query()->create([
            'cash_box_id' => $this->caja(), 'currency' => Currency::Ars, 'period_type' => PeriodType::Daily,
            'period_from' => '2026-06-02', 'period_to' => '2026-06-02', 'status' => 'closed',
            'closed_by' => $this->quienAbre(), 'closed_at' => now(), 'evidence_version' => 0,
        ]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no tiene detalle histórico congelado');
        app(ExportCashSheet::class)->handle($old);
    }

    public function test_un_cheque_entregado_despues_sigue_en_el_cierre_tardio_anterior(): void
    {
        $this->abrirLibrosSinSaldo();
        $installment = $this->cobrarCheque();
        app(DeliverToBeneficiary::class)->handle($installment, 'entrega', actorId: $this->quienAbre(), paymentDate: CarbonImmutable::parse('2026-06-03'));
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $this->validarRestricciones();
        $before = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'));
        $this->assertCount(1, app(CashSheetBuilder::class)->build($before)->cheques);
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-03');
        $after = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-03'));
        $this->assertCount(0, app(CashSheetBuilder::class)->build($after)->cheques);
    }

    public function test_el_rotulo_bancario_del_cierre_usd_no_nombra_la_cuenta_ars(): void
    {
        $ars = $this->banco();
        $usd = BankAccount::query()->create(['label' => 'Dólares', 'bank_name' => 'Banco USD', 'account_number' => 'USD-1', 'currency' => 'USD', 'is_active' => true]);
        foreach ([[Currency::Ars, $ars], [Currency::Usd, $usd]] as [$currency, $bank]) {
            app(RegisterOpeningBalance::class)->handle($this->caja(), [LedgerAccount::BankAccount->value => '100.00'], CarbonImmutable::parse('2026-06-02'), $this->quienAbre(), currency: $currency, bankAccountId: $bank->id);
        }
        $count = app(RecordCashCount::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), [], Currency::Usd, $this->quienAbre());
        app(ReviewCashCount::class)->handle($count, $this->quienAbre());
        $this->validarRestricciones();
        $closing = app(ClosePeriod::class)->handle($this->caja(), CarbonImmutable::parse('2026-06-02'), currency: Currency::Usd);
        $this->assertSame('DEPOSITOS BANCO USD CTA. USD-1', app(CashSheetBuilder::class)->build($closing)->bankDepositsLabel);
    }

    public function test_un_cierre_antiguo_con_archivo_conserva_su_descarga_pero_no_se_regenera(): void
    {
        $this->abrirLibrosSinSaldo();
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');
        $old = PeriodClosing::query()->create([
            'cash_box_id' => $this->caja(), 'currency' => Currency::Ars, 'period_type' => PeriodType::Daily,
            'period_from' => '2026-06-02', 'period_to' => '2026-06-02', 'status' => 'closed',
            'closed_by' => $this->quienAbre(), 'closed_at' => now(), 'evidence_version' => 0,
        ]);
        $attachment = app(StoreAttachment::class)->handle(
            file: UploadedFile::fake()->create('planilla.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            subject: AttachmentSubject::PeriodClosing,
            subjectId: $old->id, documentType: 'planilla_de_caja',
            userId: $this->quienAbre(),
        );
        $old->forceFill(['sheet_attachment_id' => $attachment->id])->save();
        $this->assertSame($attachment->id, app(ExportCashSheet::class)->handle($old)->id);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no tiene detalle histórico congelado');
        app(RegenerateCashSheet::class)->handle($old, $this->quienAbre(), 'Intento de regeneración del archivo antiguo.');
    }
}
