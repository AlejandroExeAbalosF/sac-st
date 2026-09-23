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
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El egreso que sale por transferencia del organismo.
 *
 * **Tres actos y no uno** (§2.3), y ésa es toda la diferencia con el
 * mostrador: el SAF avisa que transfirió, el débito aparece en el
 * extracto, y recién cuando el contador coteja los dos contra la Orden el
 * pago es un hecho. Los invariantes 12 y 13 lo dicen sin rodeos —«un
 * informe sin débito no genera egreso»; «un débito sin informe, tampoco»—
 * y el §12.3 y el §12.4 describen los dos órdenes en que pueden llegar.
 *
 * La cuota de estos casos entró en efectivo y terminó depositada: es el
 * camino del §2.4.7, que la convierte en una cuota de transferencia sin
 * que nadie cambie nada.
 */
class EgresoPorTransferenciaTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA_ORGANISMO = '23456789';

    private const CBU_VALIDO = '2850000300000000000017';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);

        /*
         * El escenario ocurre en junio y no «hoy».
         *
         * El débito se fecha el día después de la Orden, y la Orden se
         * emite con `now()`: con el reloj real, el extracto terminaba
         * trayendo un movimiento de **mañana**. Nunca fue realista —un
         * banco no informa lo que todavía no pasó— y además ataba las
         * pruebas al día en que se corren.
         */
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 09:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** El depósito acreditado saca a la cuota del mostrador (§2.4.7). */
    public function test_la_cuota_depositada_deja_de_pagarse_en_mano(): void
    {
        $estado = app(DisbursementEligibility::class)->for($this->cuotaConOrden());

        $this->assertSame(PaymentChannel::Transfer, $estado->channel);
        $this->assertFalse($estado->isCounter());
        $this->assertFalse($estado->canPay());
        $this->assertSame(DisbursementMethod::BankTransfer, $estado->method);
        // Y el circuito bancario sí está abierto.
        $this->assertTrue($estado->canReportTransfer());
        $this->assertTrue($estado->canLinkDebit());
    }

    /** Invariante 12: un informe sin débito no genera egreso. */
    public function test_el_informe_solo_no_habilita_la_validacion(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertSame(DisbursementStatus::ReportReceived, $egreso->status);
        $this->assertNull($egreso->financial_event_id);

        $this->validar($cuota)->assertSessionHasErrors('installmentId');

        $this->assertSame(
            DisbursementStatus::ReportReceived,
            $egreso->refresh()->status,
        );
    }

    /**
     * Y al revés: un débito sin informe tampoco (§12.4).
     *
     * El egreso nace igual —el débito es un hecho que hay que poder
     * anotar— pero queda esperando el aviso del organismo.
     */
    public function test_el_debito_solo_no_habilita_la_validacion(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertSame(DisbursementStatus::BankDebitObserved, $egreso->status);

        $this->validar($cuota)->assertSessionHasErrors('installmentId');
    }

    /** Con los dos, el contador puede cotejar y el pago queda hecho. */
    public function test_informe_mas_debito_mas_validacion_confirman_el_egreso(): void
    {
        $cuota = $this->cuotaConOrden();
        $movimiento = $this->debito($cuota);

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincularDebito($cuota, $movimiento)->assertSessionHasNoErrors();

        $this->assertSame(
            DisbursementStatus::ReadyForValidation,
            Disbursement::query()->firstOrFail()->status,
        );

        $this->validar($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertSame(DisbursementStatus::Confirmed, $egreso->status);
        $this->assertNotNull($egreso->financial_event_id);
        $this->assertNotNull($egreso->validated_by);
        // La fecha del pago es la del débito, no la de hoy.
        $this->assertSame(
            $movimiento->transaction_date?->toDateString(),
            $egreso->payment_date?->toDateString(),
        );

        // La cuota queda pagada y la Orden sale de circulación.
        $this->assertSame(InstallmentWorkflowStatus::Paid, $cuota->refresh()->workflow_status);
        $this->assertSame(
            PaymentOrderStatus::Completed,
            PaymentOrder::query()->firstOrFail()->status,
        );
    }

    /** El §10: el dinero deja de estar asignado y sale de la cuenta. */
    public function test_el_asiento_del_egreso_bancario_acredita_la_cuenta(): void
    {
        $cuota = $this->cuotaValidada();
        $egreso = Disbursement::query()->firstOrFail();

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['BANK_ACCOUNT', 'BENEFICIARY_FUNDS'], $cuentas);
    }

    /**
     * El egreso bancario lleva caja, aunque no pase por el cajón.
     *
     * «DEPOSITOS DIRECTOS» es la tercera columna de la planilla y se
     * calcula sobre `BANK_ACCOUNT` filtrando por caja. Sin caja el asiento
     * no entra en ningún cierre: la columna sumaría los depósitos de las
     * empresas y no restaría nunca las transferencias al beneficiario, y
     * el saldo solo sabría subir.
     *
     * Se comprueban las dos patas y el evento. El evento además es lo que
     * mira la guarda de período cerrado, que no puede frenar lo que no
     * sabe a qué caja pertenece.
     */
    public function test_el_egreso_bancario_pertenece_a_la_caja_de_la_cuota(): void
    {
        $this->cuotaValidada();

        $egreso = Disbursement::query()->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');

        $this->assertSame($caja, (int) DB::table('financial_events')
            ->where('id', $egreso->financial_event_id)
            ->value('cash_box_id'));

        $cajas = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->pluck('cash_box_id')
            ->all();

        $this->assertSame([$caja, $caja], array_map('intval', $cajas));
    }

    /** Y por eso el cierre del día lo resta de la columna. */
    public function test_el_cierre_resta_la_transferencia_de_los_depositos_directos(): void
    {
        $this->cuotaValidada();

        $egreso = Disbursement::query()->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');

        // Un período se cierra cuando termina, así que el día tiene que pasar.
        CarbonImmutable::setTestNow($egreso->payment_date->addDay());
        $this->arqueoListoParaCerrar($caja, $egreso->payment_date->toDateString());

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($egreso->payment_date->toDateString()),
        );

        $this->assertSame($egreso->amount, $cierre->disbursed_bank_deposits);
    }

    /**
     * La imputación bancaria se escribe al validar, no antes.
     *
     * Antes no puede: `bank_transaction_allocations` exige un evento
     * financiero, y el evento del egreso nace con la validación (§2.3.5).
     * Con ella el débito queda sin saldo libre y deja de ofrecerse para
     * otra Orden.
     */
    public function test_la_imputacion_del_debito_nace_con_la_validacion(): void
    {
        $cuota = $this->cuotaConOrden();
        $movimiento = $this->debito($cuota);

        $this->informar($cuota);
        $this->vincularDebito($cuota, $movimiento);

        /*
         * La acreditación del depósito ya dejó la suya, así que se cuenta
         * la del pago: es la que no puede existir antes de validar.
         */
        $this->assertSame(0, $this->imputacionesDelPago()->count());

        $this->validar($cuota)->assertSessionHasNoErrors();

        $imputacion = $this->imputacionesDelPago()->firstOrFail();

        $this->assertSame($movimiento->id, (int) $imputacion->bank_transaction_id);
        $this->assertSame('payment_confirmation', $imputacion->allocation_role);
        $this->assertSame(
            'reconciled',
            BankTransaction::query()->findOrFail($movimiento->id)->reconciliation_status->value,
        );
    }

    /** Invariante 13: el recibo exige el pago confirmado. */
    public function test_no_se_emite_el_recibo_antes_de_validar(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota);
        $this->vincularDebito($cuota, $this->debito($cuota));

        $this->emitirRecibo($cuota)->assertSessionHasErrors('installmentId');

        $this->assertSame(0, $this->recibosDeEgreso()->count());
    }

    /** Validado el pago, el papel sale y completa el pie de la Orden. */
    public function test_el_recibo_se_emite_despues_de_validar_y_completa_la_orden(): void
    {
        $cuota = $this->cuotaValidada();

        $this->emitirRecibo($cuota)->assertSessionHasNoErrors();

        $recibo = $this->recibosDeEgreso()->firstOrFail();

        $this->assertStringStartsWith('0020/', $recibo->formatted_number);
        // La casilla que marca el papel describe la salida, no la entrada.
        $this->assertSame('bank', $recibo->medium_snapshot);
        $this->assertSame($cuota->haber->beneficiary_id, $recibo->person_id);

        /* Invariante 24: el pie de la Orden referencia el recibo. */
        $this->assertSame(
            $recibo->id,
            PaymentOrder::query()->firstOrFail()->expense_receipt_id,
        );
    }

    /** Un débito mal atribuido se corrige mientras no esté validado. */
    public function test_el_debito_se_desvincula_antes_de_validar(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota);
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), [
                'reason' => 'Era el pago de otra Orden.',
            ])
            ->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertNull($egreso->bank_transaction_id);
        $this->assertSame(DisbursementStatus::ReportReceived, $egreso->status);
    }

    /** Validado ya no: hay un asiento y una imputación que lo referencian. */
    public function test_el_debito_de_un_egreso_validado_no_se_desvincula(): void
    {
        $cuota = $this->cuotaValidada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), [])
            ->assertSessionHasErrors('installmentId');

        $this->assertNotNull(Disbursement::query()->firstOrFail()->bank_transaction_id);
    }

    /** El pago sale de la cuenta: un crédito no puede ser su débito. */
    public function test_un_credito_no_puede_ser_la_transferencia(): void
    {
        $cuota = $this->cuotaConOrden();
        $credito = $this->movimiento($cuota, TransactionDirection::Credit);

        $this->vincularDebito($cuota, $credito)->assertSessionHasErrors('bankTransactionId');

        $this->assertSame(0, Disbursement::query()->whereNotNull('bank_transaction_id')->count());
    }

    /** El buscador propone el débito de la cuenta del organismo. */
    public function test_el_buscador_encuentra_el_debito_por_importe_y_cuenta(): void
    {
        $cuota = $this->cuotaConOrden();
        $movimiento = $this->debito($cuota);

        $respuesta = $this->actingAs($this->operador())
            ->getJson(route('haberes.installments.disbursement.debits', $cuota))
            ->assertOk()
            ->json('candidates');

        $this->assertIsArray($respuesta);
        $this->assertCount(1, $respuesta);
        $this->assertSame($movimiento->id, $respuesta[0]['id']);
        $this->assertArrayHasKey('importe', $respuesta[0]['signals']);
    }

    /** Validar es del contador: no lo alcanza quien solo carga datos. */
    public function test_el_administrativo_no_valida_el_pago(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota);
        $this->vincularDebito($cuota, $this->debito($cuota));

        $this->actingAs($this->operador('administrativo'))
            ->post(route('haberes.installments.disbursement.validate', $cuota), [])
            ->assertForbidden();

        $this->assertSame(
            DisbursementStatus::ReadyForValidation,
            Disbursement::query()->firstOrFail()->status,
        );
    }

    /**
     * El egreso a medio camino aparece en la cola, y el validado se va.
     *
     * Es la única forma honesta de probar esa pantalla contra el circuito
     * real: un egreso por transferencia exige su Orden —lo impone
     * `disbursements_transfer_needs_order_check`— y el escenario de este
     * test es el que la tiene. La cola muestra exactamente lo que existe en
     * el banco y todavía no en el libro.
     */
    public function test_la_cola_de_egresos_muestra_la_transferencia_hasta_que_se_valida(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'transferencias']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/planillas')
                ->has('transfers', 1)
                ->where('transfers.0.installmentId', $cuota->id)
                ->where('transfers.0.status', DisbursementStatus::ReportReceived->value)
                // La Orden es la que el organismo tiene en la mano.
                ->whereNot('transfers.0.paymentOrderNumber', null),
            );

        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'transferencias']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('transfers', 0));
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /** Una cuota con su Orden emitida, lista para que el organismo pague. */
    private function cuotaConOrden(): BeneficiaryInstallment
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

    /** La misma, ya con el pago validado por el contador. */
    private function cuotaValidada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        return $cuota->refresh();
    }

    /** El circuito completo del efectivo que nadie retiró (§2.4.7). */
    private function cuotaConEfectivoAcreditado(): BeneficiaryInstallment
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

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        $cuota = $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

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

    private function depositar(BeneficiaryInstallment $cuota): CashToBankTransfer
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
    private function debito(BeneficiaryInstallment $cuota): BankTransaction
    {
        return $this->movimiento($cuota, TransactionDirection::Debit);
    }

    private function movimiento(
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

    private function movimientoDe(
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

    private function informar(BeneficiaryInstallment $cuota): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.report', $cuota), [
                'reportedAt' => BusinessDate::today()->toDateString(),
                'reference' => '83690105',
            ]);
    }

    private function vincularDebito(
        BeneficiaryInstallment $cuota,
        BankTransaction $movimiento,
    ): TestResponse {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.link-debit', $cuota), [
                'bankTransactionId' => $movimiento->id,
            ]);
    }

    /** Validar es del contador, no del administrativo. */
    private function validar(BeneficiaryInstallment $cuota): TestResponse
    {
        return $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.disbursement.validate', $cuota), []);
    }

    private function emitirRecibo(BeneficiaryInstallment $cuota): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.receipt', $cuota), []);
    }

    /** @return Builder */
    private function imputacionesDelPago()
    {
        return DB::table('bank_transaction_allocations')
            ->where('allocation_role', 'payment_confirmation');
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Receipt> */
    private function recibosDeEgreso()
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Expense);
    }
}
