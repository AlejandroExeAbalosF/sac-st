<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CancelHaber;
use App\Modules\Haberes\Actions\RecordLegacySettlement;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\LegacySettlementMode;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\LegacySettlement;
use App\Modules\Haberes\Support\InstallmentStages;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Ledger\Actions\PayLegacyBeneficiary;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * Las cuotas de un expediente histórico que ya se pagaron.
 *
 * Cada invariante se prueba en los dos lados: el mensaje del Action y el
 * rechazo de la base escribiendo directo. Los triggers diferidos necesitan
 * `SET CONSTRAINTS ALL IMMEDIATE`, porque `RefreshDatabase` nunca confirma.
 */
class CuotaHistoricaTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    /* ── Pagada en papel, antes de la apertura ───────────────────────── */

    public function test_registra_una_cuota_pagada_antes_de_la_apertura_con_sus_papeles(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('120/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [
                ...$this->pagoEnPapel(),
                'incomePhoto' => UploadedFile::fake()->image('ingreso.jpg'),
                'orderNumber' => 'OP 3121',
                'orderDate' => '2025-03-15',
                'orderAmount' => '85000.00',
                'expenseNumber' => '5521',
                'expenseDate' => '2025-03-20',
                'expenseAmount' => '85000.00',
                'expensePhoto' => UploadedFile::fake()->image('egreso.jpg', 20, 20),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $cuota->refresh();
        $registro = LegacySettlement::query()->sole();

        $this->assertSame(InstallmentWorkflowStatus::LegacySettled, $cuota->workflow_status);
        $this->assertSame(LegacySettlementMode::BeforeOpening, $registro->mode);
        $this->assertSame('85000.00', $registro->amount);
        $this->assertSame(PaymentMedium::Cash, $registro->payment_medium);
        $this->assertSame(3, LegacyDocument::query()->where('legacy_settlement_id', $registro->id)->count());
        $this->assertSame(2, Attachment::query()->where('subject_type', AttachmentSubject::LegacyDocument->value)->count());
        $this->assertSame(
            InstallmentStage::PaidBeforeOpening,
            app(InstallmentStages::class)->forMany(collect([$cuota]))[$cuota->id],
        );
    }

    /** Sin apertura no hay corte: no se puede decir si el papel es anterior. */
    public function test_sin_apertura_de_la_caja_no_se_cargan_papeles(): void
    {
        $cuota = $this->cuota('121/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), $this->pagoEnPapel())
            ->assertSessionHasErrors(['installment' => 'La caja de Haberes todavía no tiene apertura: sin ella no se puede saber si un papel es anterior al sistema.']);

        $this->assertSame(InstallmentWorkflowStatus::Active, $cuota->refresh()->workflow_status);
    }

    public function test_un_papel_del_dia_de_la_apertura_o_posterior_se_rechaza(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('122/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [...$this->pagoEnPapel(), 'incomeDate' => '2026-06-01'])
            ->assertSessionHasErrors(['incomeDate' => 'El papel tiene que ser anterior a la apertura de la caja (01/06/2026).']);
    }

    public function test_la_base_rechaza_un_papel_posterior_a_la_apertura(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('123/2024', '85000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no es anterior a la apertura de la caja');

        $this->insertarPapel($cuota, LegacyDocumentKind::IncomeReceipt, '1', '2026-06-02');
    }

    /** Una apertura nueva no puede volver posterior un papel ya cargado. */
    public function test_la_base_rechaza_una_apertura_igual_o_anterior_a_un_papel_cargado(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('124/2024', '85000.00');
        $this->registrar($cuota);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('la apertura tiene que ser posterior');

        DB::table('financial_events')->insert([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => 'opening_balance',
            'event_date' => '2025-03-20',
            'status' => 'posted',
            'posted_at' => now(),
            'idempotency_key' => 'test-apertura-anterior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ── El mismo papel dos veces ────────────────────────────────────── */

    public function test_el_mismo_papel_el_mismo_dia_no_se_carga_dos_veces(): void
    {
        $this->abrirLibros();
        $primera = $this->cuota('125/2024', '85000.00');
        $segunda = $this->cuota('126/2024', '85000.00');
        $this->registrar($primera);

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($segunda), [...$this->pagoEnPapel(), 'incomeNumber' => ' 0001234 '])
            ->assertSessionHasErrors('incomeNumber');

        $this->assertSame(InstallmentWorkflowStatus::Active, $segunda->refresh()->workflow_status);
    }

    public function test_la_base_rechaza_el_mismo_papel_el_mismo_dia(): void
    {
        $this->abrirLibros();
        $primera = $this->cuota('127/2024', '85000.00');
        $segunda = $this->cuota('128/2024', '85000.00');

        $this->insertarPapel($primera, LegacyDocumentKind::IncomeReceipt, 'a-77', '2025-03-10');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('legacy_documents_paper_unique');

        $this->insertarPapel($segunda, LegacyDocumentKind::IncomeReceipt, ' A-77', '2025-03-10');
    }

    /**
     * No se sabe si la numeración de los talonarios se reinicia: el mismo
     * número con otra fecha puede ser otro papel. Se avisa y pasa si se
     * confirma.
     */
    public function test_el_mismo_numero_con_otra_fecha_avisa_y_pasa_con_confirmacion(): void
    {
        $this->abrirLibros();
        $primera = $this->cuota('129/2024', '85000.00');
        $segunda = $this->cuota('130/2024', '85000.00');
        $this->registrar($primera);

        $otraFecha = [...$this->pagoEnPapel(), 'incomeDate' => '2024-11-02', 'paidOn' => '2024-11-10'];
        $operador = $this->operador('administrativo');

        $this->actingAs($operador)
            ->post($this->url($segunda), $otraFecha)
            ->assertSessionHasErrors(['incomeNumber', 'confirmDuplicates']);

        $this->assertSame(InstallmentWorkflowStatus::Active, $segunda->refresh()->workflow_status);

        $this->actingAs($operador)
            ->post($this->url($segunda), [...$otraFecha, 'confirmDuplicates' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(InstallmentWorkflowStatus::LegacySettled, $segunda->refresh()->workflow_status);
    }

    /* ── Reglas del pago ─────────────────────────────────────────────── */

    public function test_el_recibo_de_ingreso_tiene_que_ser_por_la_cuota_entera(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('131/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [...$this->pagoEnPapel(), 'incomeAmount' => '40000.00'])
            ->assertSessionHasErrors('incomeAmount');
    }

    public function test_no_se_paga_antes_de_que_el_empleador_deposite(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('132/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [...$this->pagoEnPapel(), 'paidOn' => '2025-03-01'])
            ->assertSessionHasErrors('paidOn');
    }

    /* ── Una cuota que ya se movió adentro no se paga afuera ─────────── */

    public function test_una_cuota_cobrada_en_el_sistema_no_se_da_por_pagada_afuera(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('133/2024', '85000.00');
        $this->cobrar($cuota, 72190, '2026-06-02');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), $this->pagoEnPapel())
            ->assertSessionHasErrors('installment');
    }

    public function test_la_base_no_deja_saldar_una_cuota_con_fondos(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('134/2024', '85000.00');
        $this->cobrar($cuota, 72191, '2026-06-02');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('La cuota tiene fondos asignados');

        DB::table('legacy_settlements')->insert([
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'mode' => 'before_opening',
            'amount' => '85000.00',
            'paid_on' => '2025-03-20',
            'payment_medium' => 'cash',
        ]);
    }

    /* ── Una cuota pagada afuera no se vuelve a pagar adentro ────────── */

    public function test_una_cuota_pagada_afuera_no_acepta_fondos(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('135/2024', '85000.00');
        $this->registrar($cuota);

        $this->expectException(ValidationException::class);

        $this->cobrar($cuota->refresh(), 72192, '2026-06-02');
    }

    public function test_la_base_no_acepta_movimientos_en_una_cuota_pagada_afuera(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('136/2024', '85000.00');
        $this->registrar($cuota);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ya se pagó fuera del circuito');

        DB::table('deposit_tickets')->insert([
            'expediente_id' => $cuota->haber->expediente_id,
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'bank_account_id' => $this->cuentaBancaria(),
            'deposited_at' => '2026-06-02',
            'amount' => '85000.00',
            'deposit_kind' => 'transfer',
            'status' => 'waiting',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_la_base_no_deja_cambiar_el_importe_de_una_cuota_pagada_afuera(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('137/2024', '85000.00');
        $this->registrar($cuota);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no puede cambiar');

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['expected_amount' => '80000.00']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_anular_el_haber_no_barre_una_cuota_pagada_afuera(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('138/2024', '85000.00');
        $this->registrar($cuota);

        try {
            app(CancelHaber::class)->handle($cuota->haber, 'El expediente se cargó dos veces por error.');
            $this->fail('Se anuló un haber con una cuota pagada fuera del circuito.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'Una cuota ya se pagó fuera del circuito. Hay que anular ese registro antes.',
                $e->errors()['reason'][0],
            );
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('primero hay que anular ese registro');

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['workflow_status' => 'cancelled']);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /* ── Anular el registro ──────────────────────────────────────────── */

    public function test_anular_el_registro_devuelve_la_cuota_a_pendiente(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('139/2024', '85000.00');
        $this->registrar($cuota);

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota).'/anular', ['reason' => 'Se cargó en la cuota equivocada.'])
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->post($this->url($cuota).'/anular', ['reason' => 'Se cargó en la cuota equivocada.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(InstallmentWorkflowStatus::Active, $cuota->refresh()->workflow_status);
        $this->assertSame(0, LegacySettlement::query()->current()->count());
        $this->assertSame(0, LegacyDocument::query()->current()->count());
        $this->assertSame(1, LegacyDocument::query()->whereNotNull('voided_at')->count());

        // Anulado, el mismo papel se puede volver a cargar bien.
        $this->registrar($cuota->refresh());
        $this->assertSame(InstallmentWorkflowStatus::LegacySettled, $cuota->refresh()->workflow_status);
    }

    public function test_un_registro_no_se_borra_ni_se_edita(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('140/2024', '85000.00');
        $this->registrar($cuota);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se edita');

        DB::table('legacy_documents')->update(['amount' => '1.00']);
    }

    /* ── Pagada desde «Pagos anteriores» ─────────────────────────────── */

    public function test_vincula_una_cuota_con_un_pago_de_pagos_anteriores(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('141/2024', '85000.00');
        $recibo = $this->pagoAnterior($cuota, '85000.00');
        $pendiente = app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja());

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [
                'mode' => 'legacy_disbursement',
                'receiptId' => $recibo->id,
                'incomeNumber' => '1234',
                'incomeDate' => '2025-03-10',
                'incomeAmount' => '85000.00',
            ])
            ->assertSessionHasNoErrors();

        $registro = LegacySettlement::query()->sole();

        $this->assertSame($recibo->id, $registro->legacy_disbursement_receipt_id);
        $this->assertNull($registro->paid_on);
        $this->assertNull($registro->payment_medium);
        // Es documental: el libro no se mueve.
        $this->assertSame($pendiente, app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()));
        $this->assertSame(
            InstallmentStage::PaidFromLegacy,
            app(InstallmentStages::class)->forMany(collect([$cuota->refresh()]))[$cuota->id],
        );
    }

    public function test_un_pago_desde_pagos_anteriores_no_lleva_recibo_de_egreso_de_papel(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('142/2024', '85000.00');
        $recibo = $this->pagoAnterior($cuota, '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [
                'mode' => 'legacy_disbursement',
                'receiptId' => $recibo->id,
                'incomeNumber' => '1234',
                'incomeDate' => '2025-03-10',
                'incomeAmount' => '85000.00',
                'expenseNumber' => '9',
                'expenseDate' => '2025-03-20',
                'expenseAmount' => '85000.00',
            ])
            ->assertSessionHasErrors('expenseNumber');
    }

    public function test_el_recibo_vinculado_tiene_que_ser_del_mismo_beneficiario(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('143/2024', '85000.00');
        $ajena = $this->cuota('144/2024', '85000.00');
        $recibo = $this->pagoAnterior($ajena, '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($cuota), [
                'mode' => 'legacy_disbursement',
                'receiptId' => $recibo->id,
                'incomeNumber' => '1234',
                'incomeDate' => '2025-03-10',
                'incomeAmount' => '85000.00',
            ])
            ->assertSessionHasErrors('receiptId');

        $this->insertarPapel($cuota, LegacyDocumentKind::IncomeReceipt, '1234', '2025-03-10');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('se le pagó a otra persona');

        $this->insertarVinculo($cuota, $recibo, '85000.00');
    }

    /** Un recibo puede cubrir varias cuotas, pero no más de lo que pagó. */
    public function test_un_recibo_no_cubre_mas_de_su_importe(): void
    {
        $this->abrirLibros();
        [$primera, $segunda] = $this->dosCuotas('145/2024', '60000.00');
        $recibo = $this->pagoAnterior($primera, '100000.00');

        $this->vincular($primera, $recibo, '1001');

        $this->actingAs($this->operador('administrativo'))
            ->post($this->url($segunda), [
                'mode' => 'legacy_disbursement',
                'receiptId' => $recibo->id,
                'incomeNumber' => '1002',
                'incomeDate' => '2025-03-10',
                'incomeAmount' => '60000.00',
            ])
            ->assertSessionHasErrors('receiptId');

        $this->insertarPapel($segunda, LegacyDocumentKind::IncomeReceipt, '1002', '2025-03-10');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no alcanza para');

        $this->insertarVinculo($segunda, $recibo, '60000.00');
    }

    public function test_un_recibo_con_vinculos_vigentes_no_se_anula(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('146/2024', '85000.00');
        $recibo = $this->pagoAnterior($cuota, '85000.00');
        $this->vincular($cuota, $recibo, '1003');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('primero hay que anular esos vínculos');

        DB::table('receipts')->where('id', $recibo->id)->update([
            'status' => 'voided',
            'voided_at' => now(),
            'voided_by' => $this->operador('contador')->id,
            'void_reason' => 'Prueba',
        ]);
    }

    /** Anular el vínculo es documental: el pago sigue en el libro. */
    public function test_anular_el_vinculo_no_toca_el_pago_y_libera_el_recibo(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('147/2024', '85000.00');
        $recibo = $this->pagoAnterior($cuota, '85000.00');
        $this->vincular($cuota, $recibo, '1004');
        $pendiente = app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja());

        $this->actingAs($this->operador('contador'))
            ->post($this->url($cuota).'/anular', ['reason' => 'El recibo era de otra cuota.'])
            ->assertSessionHasNoErrors();

        $this->assertSame($pendiente, app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()));
        $this->assertSame(Receipt::query()->find($recibo->id)?->status, $recibo->status);

        // Y el recibo vuelve a estar disponible para la cuota correcta.
        $this->vincular($cuota->refresh(), $recibo, '1005');
        $this->assertSame(1, LegacySettlement::query()->current()->count());
    }

    /**
     * El Action bloquea el recibo antes de leer su disponible, igual que el
     * trigger. La carrera entre dos conexiones no se puede reproducir acá
     * —el recibo se crea dentro de la transacción del test y otra conexión
     * no lo ve—; lo verificable es que el bloqueo esté.
     */
    public function test_vincular_bloquea_el_recibo_antes_de_leer_su_disponible(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('148/2024', '85000.00');
        $recibo = $this->pagoAnterior($cuota, '85000.00');
        $bloqueos = [];

        DB::listen(function ($query) use (&$bloqueos): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, '"receipts"')) {
                $bloqueos[] = $sql;
            }
        });

        $this->vincular($cuota, $recibo, '1006');

        $this->assertNotEmpty($bloqueos);
    }

    /* ── La ficha del haber ──────────────────────────────────────────── */

    public function test_la_ficha_del_haber_muestra_los_papeles_y_ofrece_los_recibos_anteriores(): void
    {
        $this->abrirLibros();
        [$primera, $segunda] = $this->dosCuotas('150/2024', '60000.00');
        $this->registrar($primera);
        $recibo = $this->pagoAnterior($segunda, '60000.00');
        $haber = $primera->haber;

        $this->actingAs($this->operador('administrativo'))
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("historicos.{$primera->id}.settlement.mode", 'before_opening')
                ->where("historicos.{$primera->id}.documents.0.number", '0001234')
                ->where("historicos.{$segunda->id}.settlement", null)
                ->where("historicos.{$segunda->id}.obstacle", null)
                ->where('recibosAnteriores.0.id', $recibo->id)
                ->where('recibosAnteriores.0.available', '60000.00')
                ->where('corteHistorico', '2026-06-01')
                ->where('canRecordLegacy', true)
                ->where('canVoidLegacy', false)
                ->where('haber.installments.0.stage', 'paid_before_opening'));

        // Los recibos listan pagos con su beneficiario: solo viajan a quien los usa.
        $this->actingAs($this->operador('consulta'))
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recibosAnteriores', [])
                ->where('canRecordLegacy', false));
    }

    /* ── Permisos ────────────────────────────────────────────────────── */

    public function test_consulta_no_registra_pagos_fuera_del_circuito(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('149/2024', '85000.00');

        $this->actingAs($this->operador('consulta'))
            ->post($this->url($cuota), $this->pagoEnPapel())
            ->assertForbidden();
    }

    /* ── Ayudas ──────────────────────────────────────────────────────── */

    private function url(BeneficiaryInstallment $cuota): string
    {
        return "/haberes/cuotas/{$cuota->id}/pago-anterior";
    }

    /** @return array<string, mixed> */
    private function pagoEnPapel(): array
    {
        return [
            'mode' => 'before_opening',
            'incomeNumber' => '0001234',
            'incomeDate' => '2025-03-10',
            'incomeAmount' => '85000.00',
            'paidOn' => '2025-03-20',
            'paymentMedium' => 'cash',
        ];
    }

    private function registrar(BeneficiaryInstallment $cuota): LegacySettlement
    {
        return app(RecordLegacySettlement::class)->handle(
            installment: $cuota,
            mode: LegacySettlementMode::BeforeOpening,
            income: new LegacyPaper(
                LegacyDocumentKind::IncomeReceipt,
                'income',
                '0001234',
                CarbonImmutable::parse('2025-03-10'),
                $cuota->importeEsperado(),
            ),
            paidOn: CarbonImmutable::parse('2025-03-20'),
            paymentMedium: PaymentMedium::Cash,
        );
    }

    private function vincular(BeneficiaryInstallment $cuota, Receipt $recibo, string $numeroDeIngreso): LegacySettlement
    {
        return app(RecordLegacySettlement::class)->handle(
            installment: $cuota,
            mode: LegacySettlementMode::LegacyDisbursement,
            income: new LegacyPaper(
                LegacyDocumentKind::IncomeReceipt,
                'income',
                $numeroDeIngreso,
                CarbonImmutable::parse('2025-03-10'),
                $cuota->importeEsperado(),
            ),
            receiptId: $recibo->id,
        );
    }

    private function insertarPapel(BeneficiaryInstallment $cuota, LegacyDocumentKind $tipo, string $numero, string $fecha): void
    {
        DB::table('legacy_documents')->insert([
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'kind' => $tipo->value,
            'number' => $numero,
            'issued_on' => $fecha,
            'amount' => $cuota->importeEsperado(),
        ]);
    }

    private function insertarVinculo(BeneficiaryInstallment $cuota, Receipt $recibo, string $importe): void
    {
        DB::table('legacy_settlements')->insert([
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'mode' => 'legacy_disbursement',
            'amount' => $importe,
            'legacy_disbursement_receipt_id' => $recibo->id,
        ]);
    }

    /** Un pago hecho desde «Pagos anteriores» al beneficiario de la cuota. */
    private function pagoAnterior(BeneficiaryInstallment $cuota, string $importe): Receipt
    {
        /** @var Person $beneficiario */
        $beneficiario = $cuota->haber->beneficiary;

        return app(PayLegacyBeneficiary::class)->handle(
            cashBoxId: $this->caja(),
            beneficiary: $beneficiario,
            amount: $importe,
            legacyReference: 'Planilla 2025 · fila '.$cuota->id,
            paymentDate: CarbonImmutable::parse('2026-06-05'),
        );
    }

    /**
     * Un haber con dos cuotas iguales del mismo beneficiario.
     *
     * @return array{0: BeneficiaryInstallment, 1: BeneficiaryInstallment}
     */
    private function dosCuotas(string $expediente, string $importe): array
    {
        $primera = $this->cuota($expediente, $importe);
        $haber = $primera->haber;

        $haber->forceFill([
            'assigned_amount' => bcmul($importe, '2', 2),
            'expected_installment_count' => 2,
        ])->save();

        $segunda = $haber->installments()->create([
            'installment_number' => 2,
            'expected_amount' => $importe,
            'expected_medium' => 'cash',
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);

        return [$primera, $segunda];
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '1000000.00'],
            denominations: $this->billetesPara('1000000.00'),
            date: CarbonImmutable::parse('2026-06-01'),
        );
    }

    private function cuentaBancaria(): int
    {
        return (int) DB::table('bank_accounts')->insertGetId([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
