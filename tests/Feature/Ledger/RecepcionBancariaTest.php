<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * De crédito bancario a dinero asentado.
 *
 * El recorrido que esta tanda cierra: el extracto se importa, el operador
 * reconoce un crédito y lo registra como recepción. A partir de ahí el
 * dinero existe en los libros —está en la cuenta y todavía no tiene
 * dueño—, que es la mitad del circuito. La otra mitad, ponerle dueño, es
 * la asignación.
 *
 * Se usa el extracto real del área: el crédito de $473.191,20 del 5 de
 * agosto de 2026.
 */
class RecepcionBancariaTest extends TestCase
{
    use RefreshDatabase;

    private const CREDITO = '473191.20';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_registrar_una_recepcion_asienta_el_dinero_en_los_libros(): void
    {
        $movimiento = $this->creditoImportado();

        $recepcion = $this->registrar($movimiento, self::CREDITO);

        $this->assertSame(PaymentMedium::Bank, $recepcion->medium);
        $this->assertSame(self::CREDITO, $recepcion->amount);
        $this->assertSame(
            $movimiento->transaction_date?->toDateString(),
            $recepcion->received_date->toDateString(),
            'La recepción toma la fecha del movimiento, no la del día en que se cargó.',
        );

        $lineas = JournalLine::query()
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->get();

        $this->assertCount(2, $lineas);

        $debito = $lineas->firstOrFail(fn (JournalLine $l): bool => $l->isDebit());
        $credito = $lineas->firstOrFail(fn (JournalLine $l): bool => ! $l->isDebit());

        // El dinero está en la cuenta…
        $this->assertSame(LedgerAccount::BankAccount, $debito->account_code);
        $this->assertSame($movimiento->bank_account_id, $debito->bank_account_id);
        // …y todavía no se sabe de quién es.
        $this->assertSame(LedgerAccount::UnassignedFunds, $credito->account_code);
        $this->assertSame(self::CREDITO, $credito->credit);
    }

    public function test_el_movimiento_queda_conciliado(): void
    {
        $movimiento = $this->creditoImportado();

        $this->registrar($movimiento, self::CREDITO);

        $this->assertSame(ReconciliationStatus::Reconciled, $movimiento->refresh()->reconciliation_status);
        $this->assertSame('0.00', app(AllocatableAmount::class)->for($movimiento));
    }

    /**
     * Un crédito puede ser de dos expedientes depositados juntos.
     *
     * Cada uno es su recepción, y el movimiento queda parcialmente
     * imputado hasta que se reparta entero.
     */
    public function test_un_credito_se_puede_repartir_entre_varias_recepciones(): void
    {
        $movimiento = $this->creditoImportado();

        $this->registrar($movimiento, '200000.00', 'primera');

        $this->assertSame(ReconciliationStatus::Partial, $movimiento->refresh()->reconciliation_status);
        $this->assertSame('273191.20', app(AllocatableAmount::class)->for($movimiento));

        $this->registrar($movimiento, '273191.20', 'segunda');

        $this->assertSame(ReconciliationStatus::Reconciled, $movimiento->refresh()->reconciliation_status);
        $this->assertSame(2, FundReceipt::query()->count());
        $this->assertSame(2, BankTransactionAllocation::query()->count());
    }

    /** El invariante de §9.3: no se reparte más de lo que el banco informó. */
    public function test_no_se_puede_imputar_mas_de_lo_que_entro(): void
    {
        $movimiento = $this->creditoImportado();

        $this->registrar($movimiento, '400000.00', 'primera');

        try {
            $this->registrar($movimiento, '100000.00', 'segunda');
            $this->fail('Se imputó más plata de la que el movimiento tiene.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sin imputar', $e->getMessage());
        }

        $this->assertSame(1, FundReceipt::query()->count());
    }

    /**
     * Y la base lo impide aunque nadie pase por el Action.
     *
     * El Action da la explicación; el trigger es el que garantiza que no
     * ocurra, venga la escritura de donde venga.
     */
    public function test_la_base_rechaza_la_sobreimputacion_sin_pasar_por_el_action(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, '400000.00');

        $this->expectException(QueryException::class);

        DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $movimiento->id,
            'financial_event_id' => $recepcion->financial_event_id,
            'allocation_role' => 'funds_received',
            'amount' => '100000.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_un_debito_no_es_una_recepcion_de_fondos(): void
    {
        $this->importar();

        $debito = BankTransaction::query()
            ->where('direction', TransactionDirection::Debit)
            ->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->registrar($debito, '121.00');
    }

    public function test_un_movimiento_ignorado_no_admite_recepcion(): void
    {
        $movimiento = $this->creditoImportado();

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $movimiento), [
                'reason' => 'Se cargó por error.',
            ])
            ->assertSessionHasNoErrors();

        $this->expectException(ValidationException::class);

        $this->registrar($movimiento->refresh(), self::CREDITO);
    }

    /** El doble clic en «Registrar recepción» no duplica el crédito. */
    public function test_el_segundo_envio_del_mismo_formulario_no_duplica_la_recepcion(): void
    {
        $movimiento = $this->creditoImportado();

        $primera = $this->registrar($movimiento, self::CREDITO, 'formulario-abierto-una-vez');
        $segunda = $this->registrar($movimiento, self::CREDITO, 'formulario-abierto-una-vez');

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, FundReceipt::query()->count());
        $this->assertSame(1, BankTransactionAllocation::query()->count());
        $this->assertSame(ReconciliationStatus::Reconciled, $movimiento->refresh()->reconciliation_status);
    }

    public function test_una_recepcion_no_se_borra_ni_se_edita(): void
    {
        $movimiento = $this->creditoImportado();
        $recepcion = $this->registrar($movimiento, self::CREDITO);

        try {
            DB::table('fund_receipts')->where('id', $recepcion->id)->update(['amount' => '1.00']);
            $this->fail('El importe de una recepción se pudo editar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no se editan', $e->getMessage());
        }

        $this->expectException(QueryException::class);

        DB::table('fund_receipts')->where('id', $recepcion->id)->delete();
    }

    /**
     * Un extracto que respalda dinero asentado ya no se puede revertir.
     *
     * Antes de esta tanda, revertir una importación borraba movimientos y
     * era una salida sensata para un archivo mal cargado. Ahora un
     * movimiento puede ser la prueba de una recepción, y borrarlo dejaría
     * un asiento sin respaldo bancario.
     */
    public function test_no_se_revierte_un_extracto_que_respalda_una_recepcion(): void
    {
        $movimiento = $this->creditoImportado();
        $this->registrar($movimiento, self::CREDITO);

        $import = BankStatementImport::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->delete(route('banco.extractos.destroy', $import));

        $this->assertSame(1, BankStatementImport::query()->count());
        $this->assertSame(1, FundReceipt::query()->count());
        $this->assertNotNull(BankTransaction::query()->find($movimiento->id));
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function registrar(
        BankTransaction $movimiento,
        string $importe,
        ?string $clave = null,
    ): FundReceipt {
        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: $clave ?? 'recepcion-'.$movimiento->id.'-'.$importe,
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
        );
    }

    private function creditoImportado(): BankTransaction
    {
        $this->importar();

        return BankTransaction::query()
            ->where('direction', TransactionDirection::Credit)
            ->firstOrFail();
    }

    private function importar(): void
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile(
                    base_path('tests/Fixtures/Banking/macro-online.csv'),
                    'macro-online.csv',
                    null,
                    null,
                    true,
                ),
            ])
            ->assertSessionHasNoErrors();
    }
}
