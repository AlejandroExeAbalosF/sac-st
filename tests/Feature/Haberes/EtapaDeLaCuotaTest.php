<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\InstallmentStages;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * En qué punto del circuito está cada cuota.
 *
 * Es el dato que faltaba: `workflow_status` tiene tres respuestas para todo
 * el recorrido, así que una cuota con la Orden emitida figuraba como
 * «Pendiente», igual que una que todavía no había cobrado un peso.
 *
 * Lo que se prueba acá es que la etapa **sale de los hechos** —el dinero
 * imputado, el recibo, el traslado, la Orden, el egreso— y no de una
 * columna que alguien escribe.
 */
class EtapaDeLaCuotaTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA_ORGANISMO = '23456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 09:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** Sin plata adentro, la cuota está sin financiar. */
    public function test_una_cuota_recien_cargada_no_esta_financiada(): void
    {
        $cuota = $this->cuota();

        $this->assertSame(InstallmentStage::Unfunded, $this->etapaDe($cuota));
    }

    /**
     * Cobrada por mostrador, el efectivo está en el cajón.
     *
     * `CollectAndIssueReceipt` cobra y emite el recibo en un solo acto, así
     * que la etapa se saltea `AwaitingReceipt`: en el mostrador los dos
     * hechos ocurren juntos.
     */
    public function test_cobrada_en_efectivo_queda_en_caja(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->assertSame(InstallmentStage::InCashBox, $this->etapaDe($cuota));
    }

    /** Depositada y sin acreditar: ni en el cajón ni en la cuenta (§2.4.7). */
    public function test_depositada_sin_acreditar_queda_en_transito(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $this->assertSame(
            InstallmentStage::DepositInTransit,
            $this->etapaDe($cuota->refresh()),
        );
    }

    /**
     * Acreditada, el dinero está en la cuenta del organismo.
     *
     * Es el caso del expediente que motivó todo esto: una cuota cobrada en
     * efectivo que el beneficiario no retiró y terminó depositada. Decía
     * «Efectivo» a secas y nadie podía saber que ya no se pagaba en mano.
     */
    public function test_acreditada_queda_en_el_banco(): void
    {
        $cuota = $this->cuotaAcreditada();

        $this->assertSame(InstallmentStage::AtBank, $this->etapaDe($cuota));
    }

    /** Anulada manda sobre todo lo demás. */
    public function test_una_cuota_anulada_lo_dice(): void
    {
        $cuota = $this->cuotaCobrada();
        $cuota->forceFill(['workflow_status' => 'cancelled'])->save();

        $this->assertSame(
            InstallmentStage::Cancelled,
            $this->etapaDe($cuota->refresh()),
        );
    }

    /**
     * Una etiqueta que bloquea no mueve el dinero de lugar.
     *
     * La etapa dice **dónde está**, no si se puede operar. Esa otra
     * pregunta la contesta `DisbursementEligibility`, y mezclarlas haría
     * que la etiqueta del listado contradiga al botón de la tarjeta.
     */
    public function test_el_bloqueo_no_borra_el_recorrido_ya_hecho(): void
    {
        $cuota = $this->cuotaAcreditada();
        // Bloquear exige decir por qué: lo impone un CHECK de la tabla.
        $cuota->forceFill([
            'workflow_status' => 'blocked',
            'block_reason' => 'Retenida por el área.',
        ])->save();

        $this->assertSame(
            InstallmentStage::Blocked,
            $this->etapaDe($cuota->refresh()),
        );
    }

    /** Resolver en lote da lo mismo que mirar cada cuota por separado. */
    public function test_el_lote_coincide_con_el_caso_por_caso(): void
    {
        $enCaja = $this->cuotaCobrada();
        $acreditada = $this->cuotaAcreditada($this->otraCuota());

        $juntas = app(InstallmentStages::class)->forMany(
            BeneficiaryInstallment::query()
                ->whereIn('id', [$enCaja->id, $acreditada->id])
                ->get(),
        );

        $this->assertSame(InstallmentStage::InCashBox, $juntas[$enCaja->id]);
        $this->assertSame(InstallmentStage::AtBank, $juntas[$acreditada->id]);
        $this->assertSame($this->etapaDe($enCaja), $juntas[$enCaja->id]);
        $this->assertSame($this->etapaDe($acreditada), $juntas[$acreditada->id]);
    }

    /**
     * El detalle del expediente trae lo que la cuota necesita para no mentir.
     *
     * Hasta acá no lo pasaba: `cashTransfer` llegaba siempre en `null` y el
     * listado mostraba «Efectivo» para una cuota ya depositada, sin que
     * nada fallara. Sin este caso, el arreglo se puede deshacer en silencio.
     */
    public function test_el_detalle_del_expediente_trae_el_traslado_y_la_etapa(): void
    {
        $cuota = $this->cuotaAcreditada();
        $expediente = $cuota->haber->expediente;

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    'expediente.haberes.0.installments.0.stage',
                    InstallmentStage::AtBank->value,
                )
                ->whereNot('expediente.haberes.0.installments.0.cashTransfer', null)
                ->whereNot('expediente.haberes.0.installments.0.incomeReceipt', null),
            );
    }

    private function etapaDe(BeneficiaryInstallment $cuota): InstallmentStage
    {
        /** @var Collection<int, BeneficiaryInstallment> $sola */
        $sola = BeneficiaryInstallment::query()->whereKey($cuota->id)->get();

        return app(InstallmentStages::class)->forMany($sola)[$cuota->id];
    }

    private function cuota(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        $cuota = $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        return $cuota->refresh();
    }

    /** Otra cuota distinta, para los casos que necesitan dos a la vez. */
    private function otraCuota(): BeneficiaryInstallment
    {
        $cuota = BeneficiaryInstallment::query()
            ->whereKeyNot($this->cuota()->id)
            ->orderBy('id')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        return $cuota->refresh();
    }

    private function cuotaCobrada(?BeneficiaryInstallment $cuota = null): BeneficiaryInstallment
    {
        $cuota ??= $this->cuota();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
            receivedDate: CarbonImmutable::parse('2026-05-20'),
        );

        return $cuota->refresh();
    }

    /** El circuito completo del efectivo que nadie retiró (§2.4.7). */
    private function cuotaAcreditada(?BeneficiaryInstallment $cuota = null): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobrada($cuota);
        $traslado = $this->depositar($cuota);

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->credito($traslado->amount),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function depositar(BeneficiaryInstallment $cuota): CashToBankTransfer
    {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $this->cuentaDelOrganismo()->id,
                'depositDate' => CarbonImmutable::parse('2026-05-28'),
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

    private function credito(string $importe): BankTransaction
    {
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-05-28',
            'direction' => TransactionDirection::Credit,
            'amount' => $importe,
            'description' => 'DEPOSITO EFECTIVO',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function cuentaDelOrganismo(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => self::CUENTA_ORGANISMO],
            [
                'label' => 'Cta. Cte. 2693 — Haberes en consignación',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function importacion(BankAccount $cuenta): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-05-01',
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
}
