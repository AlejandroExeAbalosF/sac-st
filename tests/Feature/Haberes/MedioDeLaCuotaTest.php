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
use App\Modules\Haberes\Actions\RegisterDepositTicket;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El medio previsto es una expectativa; el real es un hecho.
 *
 * `expected_medium` dice por dónde el expediente **espera** que entre la
 * plata, y se edita libremente —hasta el pase de pago todo se corrige—.
 * El medio real lo fija la primera recepción y es el que va impreso en el
 * recibo. Los dos pueden dejar de coincidir, y por dos caminos: imputando
 * una recepción bancaria a una cuota declarada «efectivo», o editando el
 * medio de una cuota que ya tiene plata.
 *
 * **Que se separen no es el problema; narrar la expectativa como si fuera
 * el hecho, sí.** El libro nunca corrió riesgo —el invariante 4 rechaza
 * mezclar medios reales— pero la pantalla ofrecía cobrar por mostrador
 * dinero que ya estaba en el banco, y el botón moría al apretarlo.
 */
class MedioDeLaCuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed(HaberesDemoSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | El ticket corrige la expectativa
    |--------------------------------------------------------------------------
    */

    /**
     * Cargar el comprobante bancario pasa la cuota a «transferencia».
     *
     * Es la primera noticia concreta de por dónde llega el dinero, y con
     * ella la pantalla deja de ofrecer el mostrador antes de que alguien
     * lo intente.
     */
    public function test_el_ticket_bancario_alinea_el_medio_previsto(): void
    {
        $cuota = $this->cuota(ExpectedMedium::Cash);

        $this->cargarTicket($cuota);

        $this->assertSame(ExpectedMedium::Bank, $cuota->refresh()->expected_medium);
    }

    /** Y queda escrito de dónde venía, con su motivo. */
    public function test_el_cambio_automatico_queda_en_el_historial(): void
    {
        $cuota = $this->cuota(ExpectedMedium::Cash);

        $this->cargarTicket($cuota);

        $evento = AuditEvent::query()
            ->where('subject_type', 'BeneficiaryInstallment')
            ->where('subject_id', $cuota->id)
            ->where('action', 'cuota.medio-alineado')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('cash', $evento->old_values['expected_medium'] ?? null);
        $this->assertSame('bank', $evento->new_values['expected_medium'] ?? null);
        $this->assertStringContainsString('comprobante bancario', $evento->metadata['motivo'] ?? '');
    }

    /** Una cuota que ya esperaba transferencia no genera ruido. */
    public function test_una_cuota_bancaria_no_registra_ningun_cambio(): void
    {
        $cuota = $this->cuota(ExpectedMedium::Bank);

        $this->cargarTicket($cuota);

        $this->assertSame(ExpectedMedium::Bank, $cuota->refresh()->expected_medium);
        $this->assertSame(0, AuditEvent::query()->where('action', 'cuota.medio-alineado')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Con plata adentro manda el hecho
    |--------------------------------------------------------------------------
    */

    /** Sin plata, el medio efectivo es el previsto: es lo único que hay. */
    public function test_sin_plata_el_medio_efectivo_es_el_previsto(): void
    {
        $cuota = $this->cuota(ExpectedMedium::Cheque);

        $this->assertSame(
            PaymentMedium::Cheque,
            app(InstallmentFunding::class)->effectiveMedium($cuota),
        );
    }

    /** Con plata, manda el real aunque alguien edite la columna. */
    public function test_con_plata_manda_el_real_aunque_se_edite_lo_previsto(): void
    {
        $cuota = $this->cuotaFinanciadaPorBanco();

        $cuota->forceFill(['expected_medium' => ExpectedMedium::Cash])->save();

        $this->assertSame(
            PaymentMedium::Bank,
            app(InstallmentFunding::class)->effectiveMedium($cuota->refresh()),
        );
    }

    /**
     * Y por eso la pantalla deja de ofrecer el cobro por mostrador.
     *
     * Era el sintoma real: una cuota financiada por transferencia y
     * editada a «efectivo» mostraba el boton de cobrar, y el cobro moria
     * contra el invariante 4 despues de apretarlo.
     */
    public function test_no_se_ofrece_cobrar_por_mostrador_plata_que_esta_en_el_banco(): void
    {
        $cuota = $this->cuotaFinanciadaPorBanco();

        // El importe sube: la cuota deja de estar completa, que es la otra
        // condicion para que el boton aparezca.
        $cuota->forceFill([
            'expected_medium' => ExpectedMedium::Cash,
            'expected_amount' => bcadd($cuota->expected_amount, '50000.00', 2),
        ])->save();

        $this->assertFalse(
            app(CollectAndIssueReceipt::class)->cobraAlEmitir($cuota->refresh()),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuota(ExpectedMedium $medio): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->where('display_number', '125957/2026')
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

    private function cuotaFinanciadaPorBanco(): BeneficiaryInstallment
    {
        $cuota = $this->cuota(ExpectedMedium::Bank);

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $this->recepcionBancaria($cuota->importeEsperado()),
            installment: $cuota,
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'medio-'.Str::random(8),
        );

        return $cuota->refresh();
    }

    private function cargarTicket(BeneficiaryInstallment $cuota): void
    {
        app(RegisterDepositTicket::class)->handle(
            datos: [
                'expedienteId' => $cuota->haber->expediente_id,
                'haberId' => $cuota->haber_id,
                'installmentId' => $cuota->id,
                'bankAccountId' => $this->cuenta()->id,
                'depositedAt' => '2026-08-12',
                'amount' => $cuota->importeEsperado(),
                'depositKind' => DepositKind::Transfer,
            ],
            foto: null,
            userId: $this->operador()->id,
        );
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOr(fn (): BankAccount => BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]));
    }

    private function recepcionBancaria(string $importe)
    {
        $cuenta = $this->cuenta();

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-31',
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);

        $movimiento = BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $importacion->id,
            'transaction_date' => '2026-08-05',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);

        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(8),
            cashBoxId: (int) CashBox::query()->where('code', 'haberes')->value('id'),
        );
    }
}
