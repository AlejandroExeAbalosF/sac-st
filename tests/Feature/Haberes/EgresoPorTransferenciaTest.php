<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Data\DisbursementSummaryData;
use App\Modules\Haberes\Data\InstallmentDisbursementData;
use App\Modules\Haberes\Data\InstallmentOrderStateData;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Actions\VoidAttachment;
use App\Modules\Shared\Enums\AttachmentKind;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Enums\SharedReasonCode;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Attachments;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsTransferInstallment;
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
    use BuildsTransferInstallment;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo(Currency::Ars, Currency::Usd);

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

    /**
     * El papel del informe se adjunta al egreso, y la tarjeta lo ofrece para
     * verlo.
     */
    public function test_el_informe_guarda_su_papel_adjunto(): void
    {
        Storage::fake('local');
        $cuota = $this->cuotaConOrden();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.report', $cuota), [
                'reportedAt' => BusinessDate::today()->toDateString(),
                'reference' => '83690105',
                'document' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();
        $adjunto = Attachment::query()
            ->where('subject_type', 'disbursement')
            ->where('subject_id', $egreso->id)
            ->where('document_type', Disbursement::REPORT_DOCUMENT_TYPE)
            ->firstOrFail();

        Storage::disk($adjunto->storage_disk)->assertExists($adjunto->object_key);

        $estado = InstallmentDisbursementData::fromReadiness(app(DisbursementEligibility::class)->for($cuota->refresh()), $this->operador());
        $this->assertSame($adjunto->id, $estado->disbursement?->reportAttachment?->id);
        // El informe es un PDF y quien lo mira lo puede abrir: la cuota lo muestra en miniatura.
        $this->assertSame(AttachmentKind::Pdf, $estado->disbursement?->reportAttachment?->kind);
        $this->assertTrue($estado->disbursement?->reportAttachment?->canOpen);
    }

    /** Solo foto o PDF, como el resto de los papeles escaneados. */
    /**
     * Con dos informes, el egreso muestra el último vigente: si se anula, vuelve
     * el anterior, y el historial cuenta los dos.
     *
     * `reportDocument()` elige con `latestOfMany()`, por fuera de
     * `Attachments::latestFor`: sin su propio filtro seguiría mostrando el
     * anulado.
     */
    public function test_anular_el_ultimo_informe_vuelve_a_mostrar_el_anterior(): void
    {
        Storage::fake('local');
        $cuota = $this->cuotaConOrden();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.report', $cuota), [
                'reportedAt' => BusinessDate::today()->toDateString(),
                'reference' => '83690105',
                'document' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();
        $primero = Attachment::query()->where('subject_id', $egreso->id)->where('document_type', Disbursement::REPORT_DOCUMENT_TYPE)->sole();
        $segundo = Attachment::query()->create([
            ...Arr::except($primero->getAttributes(), ['id', 'created_at']),
            'object_key' => $primero->object_key.'-2',
            'sha256' => hash('sha256', 'segundo informe'),
        ]);

        $estado = fn (): ?DisbursementSummaryData => InstallmentDisbursementData::fromReadiness(
            app(DisbursementEligibility::class)->for($cuota->refresh()),
            $this->operador(),
        )->disbursement;
        $this->assertSame($segundo->id, $estado()?->reportAttachment?->id);

        app(VoidAttachment::class)->handle($segundo, new Reason(SharedReasonCode::Duplicate), $this->operador());

        $this->assertSame($primero->id, $estado()?->reportAttachment?->id);

        $historial = app(Attachments::class)->historyFor(AttachmentSubject::Disbursement, [$egreso->id], $this->operador(), Disbursement::REPORT_DOCUMENT_TYPE)[$egreso->id];
        $this->assertSame([2, 1], [$historial->total, $historial->voided]);
    }

    public function test_el_papel_del_informe_tiene_que_ser_foto_o_pdf(): void
    {
        Storage::fake('local');
        $cuota = $this->cuotaConOrden();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.report', $cuota), [
                'reportedAt' => BusinessDate::today()->toDateString(),
                'document' => UploadedFile::fake()->create('informe.docx', 20, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            ])
            ->assertSessionHasErrors('document');

        $this->assertSame(0, Disbursement::query()->count());
    }

    /** Si el informe se rechaza, su archivo no queda huérfano en el disco. */
    public function test_un_informe_rechazado_no_deja_su_archivo(): void
    {
        Storage::fake('local');
        $cuota = $this->cuotaValidada();
        $antes = Attachment::query()->count();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.report', $cuota), [
                'reportedAt' => BusinessDate::today()->toDateString(),
                'document' => UploadedFile::fake()->create('informe.pdf', 120, 'application/pdf'),
            ])
            ->assertSessionHasErrors('installmentId');

        $this->assertSame($antes, Attachment::query()->count());
        // Los adjuntos se guardan bajo la carpeta de su sujeto.
        $this->assertSame([], Storage::disk('local')->allFiles('disbursement'));
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

        $this->actingAs($this->operador())
            ->get(route('planillas.index', ['cola' => 'todas']))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('all.data.0.status', 'Pago confirmado · falta recibo de egreso')
                ->where('all.data.0.paymentMethod', 'Transferencia'));
    }

    /**
     * Un haber en dólares se asienta en dólares de punta a punta.
     *
     * El cobro, el traslado, la acreditación y el egreso escribían sus
     * líneas sin moneda, y `EntryLine` cae en pesos: un haber en dólares
     * terminaba con sus fondos en pesos, o la base rechazaba la línea del
     * banco por no coincidir con la cuenta.
     */
    public function test_un_haber_en_dolares_se_asienta_en_dolares(): void
    {
        $this->moneda = 'USD';
        $desde = (int) DB::table('journal_lines')->max('id');

        $this->cuotaValidada();

        $this->assertSame(
            ['USD'],
            DB::table('journal_lines')->where('id', '>', $desde)->distinct()->pluck('currency')->all(),
        );
        $this->assertSame(['USD'], DB::table('fund_receipts')->distinct()->pluck('currency')->all());
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
    /**
     * El egreso sale en la planilla del día en que se valida.
     *
     * Se asienta con la fecha de la validación, no con la del débito: el
     * día del débito pudo haberse cerrado. La fecha del pago sigue siendo
     * la del banco, guardada en el egreso.
     */
    public function test_el_cierre_resta_la_transferencia_de_los_depositos_directos(): void
    {
        $this->cuotaValidada();

        $egreso = Disbursement::query()->with('financialEvent')->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        $diaDelAsiento = $egreso->financialEvent->event_date->toDateString();

        $this->assertSame(BusinessDate::today()->toDateString(), $diaDelAsiento);
        $this->assertNotNull($egreso->payment_date);

        // Un período se cierra cuando termina, así que el día tiene que pasar.
        CarbonImmutable::setTestNow(CarbonImmutable::parse($diaDelAsiento)->addDay()->setTime(10, 0));
        $this->arqueoListoParaCerrar($caja, $diaDelAsiento);

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($diaDelAsiento),
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

    /**
     * Pagada la cuota, la Orden deja de estar vigente pero sus papeles se
     * siguen consultando: la sección la muestra completada.
     */
    public function test_la_orden_cumplida_se_sigue_mostrando(): void
    {
        $cuota = $this->cuotaValidada();
        $orden = PaymentOrder::query()->firstOrFail();

        $estado = InstallmentOrderStateData::fromReadiness(
            $cuota,
            app(PaymentOrderEligibility::class)->for($cuota),
        );

        $this->assertSame($orden->id, $estado->order?->id);
        $this->assertSame(PaymentOrderStatus::Completed, $estado->order->status);
        // Y no se puede emitir otra: la cuota ya está pagada.
        $this->assertFalse($estado->canIssue);
    }

    /** Un débito mal atribuido se corrige mientras no esté validado. */
    public function test_el_debito_se_desvincula_antes_de_validar(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota);
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), [
                'reasonCode' => 'wrong_credit',
                'reason' => 'Era el pago de otra Orden.',
            ])
            ->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertNull($egreso->bank_transaction_id);
        $this->assertSame(DisbursementStatus::ReportReceived, $egreso->status);

        // El motivo queda en la auditoría, con su código para contarlo.
        $evento = AuditEvent::query()->where('action', 'egreso.debito-desvinculado')->latest('id')->firstOrFail();
        $this->assertSame('Era otro movimiento del extracto: Era el pago de otra Orden.', $evento->metadata['reason'] ?? null);
        $this->assertSame('wrong_credit', $evento->metadata['motivo_codigo'] ?? null);
    }

    /** Desvincular pide el motivo de la lista: sin él, nada cambia. */
    public function test_desvincular_el_debito_exige_motivo(): void
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota);
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), [])
            ->assertSessionHasErrors('reasonCode');

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), ['reasonCode' => 'other'])
            ->assertSessionHasErrors('reason');

        $this->assertNotNull(Disbursement::query()->firstOrFail()->bank_transaction_id);
    }

    /** Validado ya no: hay un asiento y una imputación que lo referencian. */
    public function test_el_debito_de_un_egreso_validado_no_se_desvincula(): void
    {
        $cuota = $this->cuotaValidada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.unlink-debit', $cuota), ['reasonCode' => 'wrong_credit'])
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
    | La transferencia que no se pagó
    |--------------------------------------------------------------------------
    */

    /**
     * El circuito entero de una Orden equivocada con la transferencia en curso.
     *
     * Antes la Orden se anulaba igual, el egreso quedaba atado a ella y, al
     * validarlo, la anulada volvía a «completada» mientras la nueva quedaba
     * activa sobre una cuota pagada.
     */
    public function test_la_orden_con_transferencia_en_curso_se_anula_recien_al_darla_por_fallida(): void
    {
        $cuota = $this->cuotaConOrden();
        $ordenA = $this->ordenVigente($cuota);
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egresoA = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->anularOrden($ordenA)->assertSessionHasErrors('orderId');
        $this->assertSame(PaymentOrderStatus::Draft, $ordenA->refresh()->status);

        $this->darPorFallida($cuota, $egresoA)->assertSessionHasNoErrors();

        $egresoA->refresh();
        $this->assertSame(DisbursementStatus::Failed, $egresoA->status);
        $this->assertSame('bank_rejected', $egresoA->failure_reason_code);
        $this->assertTrue(AuditEvent::query()->where('action', 'egreso.fallido')->exists());

        $this->anularOrden($ordenA)->assertSessionHasNoErrors();
        $this->assertSame(PaymentOrderStatus::Voided, $ordenA->refresh()->status);

        $ordenB = $this->emitirOrden($cuota);
        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        $this->assertSame(PaymentOrderStatus::Voided, $ordenA->refresh()->status);
        $this->assertSame(PaymentOrderStatus::Completed, $ordenB->refresh()->status);
        $this->assertSame(DisbursementStatus::Failed, $egresoA->refresh()->status);
        $this->assertSame(
            $ordenB->id,
            Disbursement::query()->confirmed()->where('beneficiary_installment_id', $cuota->id)->sole()->payment_order_id,
        );
    }

    /** Con el débito reconocido el dinero salió: no falló. */
    public function test_no_se_da_por_fallida_con_el_debito_reconocido(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();
        $egreso = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->darPorFallida($cuota, $egreso)->assertSessionHasErrors('disbursementId');
        $this->assertSame(DisbursementStatus::ReadyForValidation, $egreso->refresh()->status);
    }

    /**
     * Un formulario abierto sobre la transferencia A no cierra la B que nació
     * después: el pedido nombra el egreso, no «el vivo de la cuota».
     */
    public function test_un_pedido_viejo_no_alcanza_a_la_transferencia_nueva(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egresoA = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->darPorFallida($cuota, $egresoA)->assertSessionHasNoErrors();
        $this->anularOrden($this->ordenVigente($cuota))->assertSessionHasNoErrors();
        $this->emitirOrden($cuota);
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egresoB = Disbursement::query()->live()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->darPorFallida($cuota, $egresoA)->assertSessionHasErrors('disbursementId');
        $this->assertSame(DisbursementStatus::ReportReceived, $egresoB->refresh()->status);
    }

    /** Darla por fallida es del contador, como validar. */
    public function test_dar_por_fallida_exige_su_permiso(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egreso = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.fail', $cuota), [
                'disbursementId' => $egreso->id,
                'reasonCode' => 'bank_rejected',
            ])
            ->assertForbidden();
    }

    /** La ficha ofrece el botón con la transferencia en curso, y solo a quien puede. */
    public function test_la_ficha_ofrece_darla_por_fallida_al_contador(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();
        $haber = $cuota->haber;
        $ficha = route('haberes.haber.show', [$haber->expediente, $haber]);

        $this->actingAs($this->operador('contador'))
            ->get($ficha)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canFailDisbursement', true)
                ->where("egresos.{$cuota->id}.canFailTransfer", true));

        $this->actingAs($this->operador())
            ->get($ficha)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canFailDisbursement', false));

        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();

        $this->actingAs($this->operador('contador'))
            ->get($ficha)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("egresos.{$cuota->id}.canFailTransfer", false));
    }

    /** La base cierra los mismos caminos aunque un Action se equivoque. */
    public function test_la_base_impone_las_transiciones_del_egreso_y_la_orden(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->ordenVigente($cuota);
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egreso = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        $egresos = fn () => DB::table('disbursements')->where('id', $egreso->id);
        $ordenes = fn () => DB::table('payment_orders')->where('id', $orden->id);

        $this->rechaza(fn () => $egresos()->update(['status' => 'reversed']), 'egreso en curso a revertido');
        $this->rechaza(fn () => $egresos()->update(['status' => 'failed', 'failure_reason_code' => 'cualquiera']), 'código fuera de la lista');
        $this->rechaza(fn () => $egresos()->update(['status' => 'failed', 'failure_reason_code' => 'other']), '«Otro» sin detalle');
        $this->rechaza(fn () => $egresos()->update(['status' => 'failed']), 'fallido sin motivo');
        $this->rechaza(fn () => $ordenes()->update([
            'status' => 'voided',
            'voided_by' => $this->operador()->id,
            'voided_at' => now(),
            'rejection_or_void_reason_code' => 'other',
            'rejection_or_void_reason' => 'x',
        ]), 'anular la Orden con su transferencia en curso');
        $this->rechaza(fn () => $ordenes()->update(['status' => 'completed']), 'completar la Orden sin egreso confirmado');

        $this->darPorFallida($cuota, $egreso)->assertSessionHasNoErrors();

        $this->rechaza(fn () => $egresos()->update(['status' => 'pending']), 'egreso fallido que vuelve a estar en curso');
        $this->rechaza(fn () => $egresos()->update(['failure_reason' => 'otro texto']), 'egreso fallido editado');
        $this->rechaza(fn () => $egresos()->update(['updated_at' => now()->addDay()]), 'egreso fallido tocado');
        $this->rechaza(
            fn () => $egresos()->update(['created_by' => User::factory()->create()->id]),
            'autor de un egreso fallido cambiado', 'no se modifica',
        );
        $this->rechaza(fn () => $egresos()->update(['created_by' => null]), 'autor de un egreso fallido borrado a mano', 'no se modifica');

        // Cerrado se llega por transición, nunca se nace.
        $nace = fn (array $fila) => DB::table('disbursements')->insert([
            'beneficiary_installment_id' => $cuota->id,
            'payment_order_id' => $orden->id,
            'method' => 'bank_transfer',
            'amount' => $orden->amount,
            'created_at' => now(),
            'updated_at' => now(),
            ...$fila,
        ]);
        $this->rechaza(fn () => $nace(['status' => 'reversed']), 'egreso que nace revertido', 'no nace');
        $this->rechaza(fn () => $nace([
            'status' => 'failed',
            'failure_reason_code' => 'bank_rejected',
            'bank_transaction_id' => $this->debito($cuota)->id,
        ]), 'egreso que nace fallido con su débito', 'no nace');

        $this->anularOrden($orden)->assertSessionHasNoErrors();

        $this->rechaza(fn () => $ordenes()->update(['status' => 'draft']), 'Orden anulada que vuelve a circular');
        $this->rechaza(fn () => DB::table('disbursements')->insert([
            'beneficiary_installment_id' => $cuota->id,
            'payment_order_id' => $orden->id,
            'method' => 'bank_transfer',
            'amount' => $orden->amount,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]), 'egreso contra una Orden anulada');
    }

    /** Borrar al usuario sigue anulando su autoría, aunque el egreso esté cerrado. */
    public function test_el_set_null_de_la_fk_alcanza_al_egreso_fallido(): void
    {
        $cuota = $this->cuotaConOrden();
        $this->informar($cuota)->assertSessionHasNoErrors();
        $egreso = Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->sole();
        $autor = User::factory()->create();
        DB::table('disbursements')->where('id', $egreso->id)->update(['created_by' => $autor->id]);

        $this->darPorFallida($cuota, $egreso)->assertSessionHasNoErrors();
        $autor->delete();

        $this->assertNull($egreso->refresh()->created_by);
        $this->assertSame(DisbursementStatus::Failed, $egreso->status);
    }

    /** Un egreso confirmado no se da por fallido ni por la base. */
    public function test_la_base_no_deja_cerrar_un_egreso_confirmado(): void
    {
        $cuota = $this->cuotaValidada();
        $egreso = Disbursement::query()->confirmed()->where('beneficiary_installment_id', $cuota->id)->sole();

        $this->rechaza(fn () => DB::table('disbursements')->where('id', $egreso->id)->update([
            'status' => 'failed',
            'failure_reason_code' => 'other',
            'failure_reason' => 'x',
        ]), 'egreso confirmado a fallido');
        $this->rechaza(fn () => DB::table('payment_orders')
            ->where('id', $egreso->payment_order_id)
            ->update(['status' => 'draft']), 'Orden completada que vuelve a circular');
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /** La misma, ya con el pago validado por el contador. */
    private function cuotaValidada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConOrden();

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincularDebito($cuota, $this->debito($cuota))->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        return $cuota->refresh();
    }

    /**
     * Que la base rechace la escritura, dentro de un punto de guardado.
     *
     * @param  string|null  $motivo  Parte del mensaje, cuando importa qué
     *                               guarda la frenó y no solo que se frenó.
     */
    private function rechaza(callable $escritura, string $caso, ?string $motivo = null): void
    {
        try {
            DB::transaction(static function () use ($escritura): void {
                $escritura();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            if ($motivo !== null) {
                $this->assertStringContainsString($motivo, $e->getMessage(), $caso);
            } else {
                $this->addToAssertionCount(1);
            }

            return;
        }

        $this->fail('La base aceptó: '.$caso);
    }

    private function ordenVigente(BeneficiaryInstallment $cuota): PaymentOrder
    {
        return PaymentOrder::query()->active()->where('beneficiary_installment_id', $cuota->id)->sole();
    }

    private function anularOrden(PaymentOrder $orden): TestResponse
    {
        return $this->actingAs($this->operador('contador'))
            ->post(route('ordenes.void', $orden), [
                'reasonCode' => 'wrong_beneficiary_data',
                'reason' => 'El CBU era de otra cuenta.',
            ]);
    }

    private function darPorFallida(BeneficiaryInstallment $cuota, Disbursement $egreso): TestResponse
    {
        return $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.disbursement.fail', $cuota), [
                'disbursementId' => $egreso->id,
                'reasonCode' => 'bank_rejected',
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
