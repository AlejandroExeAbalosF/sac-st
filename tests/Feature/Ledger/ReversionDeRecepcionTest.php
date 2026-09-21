<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\ReverseFundReceipt;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Shared\Models\CashBox;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Deshacer una recepción que no ocurrió.
 *
 * El trigger de `fund_receipts` viene diciendo «una recepcion de fondos no
 * se borra: se revierte» desde que la tabla existe, y esa reversión no
 * estaba construida: la base mandaba a una puerta que no existía.
 *
 * El caso que la hizo falta: el crédito de nuestro propio depósito de
 * efectivo llega al extracto como cualquier otro, y registrarlo como
 * recepción en vez de acreditarlo contra el traslado deja la misma plata
 * contada dos veces, con el movimiento sin saldo libre para imputarla bien.
 */
class ReversionDeRecepcionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_el_dinero_sale_de_los_libros(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');

        $revertida = app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'Era la acreditación de nuestro propio depósito, no plata nueva.',
            idempotencyKey: 'reversion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->assertNotNull($revertida->reversal_event_id);
        $this->assertSame(
            'Era la acreditación de nuestro propio depósito, no plata nueva.',
            $revertida->reversal_reason,
        );

        // El asiento inverso, línea por línea: lo que se debitó se acredita.
        $lineas = JournalLine::query()
            ->where('financial_event_id', $revertida->reversal_event_id)
            ->get();

        $this->assertCount(2, $lineas);
        $this->assertTrue(
            $lineas->firstWhere('account_code', LedgerAccount::BankAccount)
                ->credit > 0,
            'La cuenta bancaria tiene que quedar acreditada, no debitada.',
        );
        $this->assertTrue(
            $lineas->firstWhere('account_code', LedgerAccount::UnassignedFunds)
                ->debit > 0,
            'Los fondos sin identificar tienen que quedar debitados.',
        );

        $evento = FinancialEvent::query()->findOrFail($revertida->reversal_event_id);

        $this->assertSame(FinancialEventType::Reversal, $evento->event_type);
        $this->assertSame($recepcion->financial_event_id, $evento->reversal_of_id);
    }

    /**
     * Lo que destraba el caso real: el crédito vuelve a estar disponible.
     *
     * Sin esto la reversión limpiaría los libros y dejaría el movimiento
     * del extracto imputado para siempre, sin forma de acreditarlo contra
     * el traslado que de verdad le corresponde.
     */
    public function test_el_movimiento_del_extracto_vuelve_a_quedar_libre(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');

        $this->assertSame(
            '0.00',
            app(AllocatableAmount::class)->for($movimiento->refresh()),
        );
        $this->assertSame(ReconciliationStatus::Reconciled, $movimiento->reconciliation_status);

        app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'Se imputó al lugar equivocado y hay que volver a empezar.',
            idempotencyKey: 'reversion-'.Str::random(8),
        );

        $movimiento->refresh();

        $this->assertSame('240000.00', app(AllocatableAmount::class)->for($movimiento));
        $this->assertSame(ReconciliationStatus::Pending, $movimiento->reconciliation_status);
    }

    /**
     * Sacarle el dinero a una cuota es otra decisión, con su permiso y su
     * motivo: encadenarla acá la dejaría ocurrir sin que nadie la pida.
     */
    public function test_no_se_revierte_lo_que_ya_financia_una_cuota(): void
    {
        $this->seed(HaberesDemoSeeder::class);

        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $this->cuota(),
            amount: '1000.00',
            idempotencyKey: 'asignacion-'.Str::random(8),
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Primero hay que desasignar esos fondos.');

        app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion->refresh(),
            reason: 'Quiero deshacerla con la plata todavía repartida.',
            idempotencyKey: 'reversion-'.Str::random(8),
        );
    }

    /** La garantía no es el Action: la impone la base. */
    public function test_la_base_rechaza_imputar_desde_una_recepcion_revertida(): void
    {
        $this->seed(HaberesDemoSeeder::class);

        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');

        app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'No correspondía registrarla como recepción.',
            idempotencyKey: 'reversion-'.Str::random(8),
        );

        $cuota = $this->cuota();

        $this->expectException(QueryException::class);

        FundingAllocation::query()->create([
            'allocation_event_id' => $recepcion->financial_event_id,
            'fund_receipt_id' => $recepcion->id,
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'amount' => '1000.00',
            'allocated_at' => now(),
        ]);
    }

    /** Dos clics del mismo botón no revierten dos veces. */
    public function test_el_segundo_envio_no_duplica_la_reversion(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');
        $clave = 'reversion-'.Str::random(8);

        $primera = app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'Cargada contra el movimiento equivocado.',
            idempotencyKey: $clave,
        );
        $segunda = app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion->refresh(),
            reason: 'Cargada contra el movimiento equivocado.',
            idempotencyKey: $clave,
        );

        $this->assertSame($primera->reversal_event_id, $segunda->reversal_event_id);
        $this->assertSame(
            1,
            FinancialEvent::query()
                ->where('event_type', FinancialEventType::Reversal)
                ->count(),
        );
    }

    /** Una reversión no se deshace editando la fila. */
    public function test_la_reversion_no_se_edita(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '240000.00');

        app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'No correspondía registrarla como recepción.',
            idempotencyKey: 'reversion-'.Str::random(8),
        );

        $this->expectException(QueryException::class);

        $recepcion->refresh()->forceFill(['reversal_event_id' => null])->save();
    }

    private function cuota(): BeneficiaryInstallment
    {
        return BeneficiaryInstallment::query()->orderBy('id')->firstOrFail();
    }

    private function registrar(BankTransaction $movimiento, string $importe): FundReceipt
    {
        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(8),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
        );
    }

    private function creditoImportado(): BankTransaction
    {
        $cuenta = BankAccount::query()->firstOrCreate(
            ['account_number' => '23456789'],
            [
                'label' => 'Cta. Cte. 2693 — Haberes',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
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
            'transaction_date' => '2026-09-16',
            'amount' => '240000.00',
            'direction' => TransactionDirection::Credit,
            'reconciliation_status' => ReconciliationStatus::Pending,
            'description' => 'DEPOSITO EFECTIVO 995979830',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }
}
