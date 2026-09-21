<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La entrega al beneficiario y el papel que firma.
 *
 * **El acto simétrico del cobro por mostrador**, en el otro extremo del
 * circuito: allá el empleador deja el dinero y se lleva su recibo de
 * ingreso; acá el trabajador se lleva el dinero y firma el de egreso.
 *
 * La asimetría está en el orden y es una regla, no una comodidad: el
 * recibo de ingreso se emite al recibir y no espera nada (§2.5.4),
 * mientras que el de egreso exige que el pago ya esté confirmado
 * (invariante 13). Un trigger sobre `receipts` lo impone.
 */
class EgresoPorMostradorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El circuito entero en un acto: se entrega, se asienta y sale el recibo. */
    public function test_la_entrega_en_efectivo_paga_la_cuota_y_emite_el_recibo(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->entregar($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertSame(DisbursementStatus::Confirmed, $egreso->status);
        $this->assertSame(DisbursementMethod::Cash, $egreso->method);
        $this->assertSame($cuota->importeEsperado(), $egreso->amount);
        $this->assertNotNull($egreso->financial_event_id);

        // Y la cuota queda pagada, que es lo que `paid` significa.
        $this->assertSame(
            InstallmentWorkflowStatus::Paid,
            $cuota->refresh()->workflow_status,
        );

        $this->assertSame(1, $this->recibosDeEgreso()->count());
    }

    /**
     * Y el recibo aparece en los movimientos del día, del lado de egresos.
     *
     * Es la pregunta práctica del mostrador: el beneficiario se llevó la
     * plata, ¿lo veo en la caja sin esperar nada? El libro del día sale de
     * los comprobantes --no de los asientos-- así que lo que lo hace
     * aparecer es el recibo, con su número y su importe en la columna del
     * efectivo.
     */
    public function test_el_egreso_en_efectivo_sale_en_los_movimientos_del_dia(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->entregar($cuota)->assertSessionHasNoErrors();

        $recibo = $this->recibosDeEgreso()->firstOrFail();

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/dia?fecha='.now()->toDateString())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('book.expense', 1)
                ->where('book.expense.0.number', (string) $recibo->formatted_number)
                ->where('book.expense.0.cash', $cuota->importeEsperado())
                // No se cuela del otro lado del cuadro.
                ->where('book.expense.0.bank', '0.00')
            );
    }

    /** El §10: el dinero deja de estar asignado y sale de la caja. */
    public function test_el_asiento_del_egreso_saca_el_dinero_de_la_caja(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->entregar($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['BENEFICIARY_FUNDS', 'CASH_ON_HAND'], $cuentas);

        // El débito es el que libera la atribución al beneficiario.
        $debito = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->where('account_code', 'BENEFICIARY_FUNDS')
            ->firstOrFail();

        $this->assertSame($cuota->id, (int) $debito->beneficiary_installment_id);
        $this->assertSame('0.00', $debito->credit);
    }

    /**
     * El papel dice quién cobró, no quién depositó.
     *
     * Es el cambio de persona respecto del recibo de ingreso: `person_id`
     * apunta al beneficiario porque el formulario habla en primera
     * persona —«Recibí conforme»— y quien lo dice es el trabajador. El
     * empleador no desaparece: baja al renglón que le corresponde.
     */
    public function test_el_recibo_de_egreso_lo_encabeza_el_beneficiario(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->entregar($cuota)->assertSessionHasNoErrors();

        $recibo = $this->recibosDeEgreso()->firstOrFail();
        $haber = $cuota->haber;

        $this->assertSame($haber->beneficiary_id, $recibo->person_id);
        $this->assertSame($haber->beneficiary->name, $recibo->beneficiary_name_snapshot);
        $this->assertSame(
            $haber->expediente->employer?->name,
            $recibo->counterparty_name_snapshot,
        );
        $this->assertSame('cash', $recibo->medium_snapshot);
        $this->assertSame($cuota->importeEsperado(), $recibo->amount);
    }

    /** Sin firmante del área: ese renglón lo completa quien cobra. */
    public function test_el_recibo_de_egreso_sale_sin_firma_del_area(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->entregar($cuota)->assertSessionHasNoErrors();

        $recibo = $this->recibosDeEgreso()->firstOrFail();

        $this->assertNull($recibo->signed_by);
        $this->assertNull($recibo->signed_by_name_snapshot);
        $this->assertNull($recibo->signed_by_title_snapshot);
    }

    /** Los dos comprobantes toman su número de series distintas. */
    public function test_el_egreso_numera_por_la_serie_0020(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->entregar($cuota)->assertSessionHasNoErrors();

        $this->assertStringStartsWith('0010/', $this->reciboDeIngreso($cuota)->formatted_number);
        $this->assertStringStartsWith('0020/', $this->recibosDeEgreso()->firstOrFail()->formatted_number);
    }

    /**
     * El cheque se entrega como cheque (§2.5.5).
     *
     * Acredita `CHEQUES_IN_CUSTODY` y no la caja, y el papel deja el
     * inventario: si el estado no lo dijera, el arqueo lo seguiría
     * contando.
     */
    public function test_entregar_un_cheque_lo_saca_de_la_custodia(): void
    {
        $cuota = $this->cuotaCobrada(cheque: true);

        $this->entregar($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();

        $this->assertSame(DisbursementMethod::Cheque, $egreso->method);

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['BENEFICIARY_FUNDS', 'CHEQUES_IN_CUSTODY'], $cuentas);

        $this->assertSame(
            ChequeStatus::Delivered,
            FundReceipt::query()->firstOrFail()->cheque_status,
        );

        $this->assertSame('cheque', $this->recibosDeEgreso()->firstOrFail()->medium_snapshot);
    }

    /**
     * §2.2.7: la condición administrativa retiene la entrega, no el
     * ingreso.
     *
     * El empleador deposita igual; lo que la Secretaría retiene es el
     * dinero del trabajador. La Resolución 3671/16 lo establece.
     */
    public function test_una_etiqueta_que_bloquea_el_pago_retiene_la_entrega(): void
    {
        $cuota = $this->cuotaCobrada();

        /*
         * El catálogo del área ya la trae; lo que la prueba necesita es
         * que bloquee, que es la consecuencia que el usuario administra.
         */
        $etiqueta = HaberManagementLabel::query()->firstOrCreate(
            ['code' => 'P.P. HOMOL.'],
            ['description' => 'Pendiente de homologación', 'sort_order' => 9, 'is_active' => true],
        );

        $etiqueta->forceFill(['blocks_payment' => true])->save();

        $cuota->forceFill(['management_label_id' => $etiqueta->id])->save();

        $this->entregar($cuota->refresh())->assertSessionHasErrors('installmentId');

        $this->assertSame(0, Disbursement::query()->count());
        $this->assertSame(0, $this->recibosDeEgreso()->count());
    }

    /** El recibo de ingreso va antes: los dos extremos se archivan juntos. */
    public function test_sin_recibo_de_ingreso_no_se_entrega(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->reciboDeIngreso($cuota)->forceFill([
            'status' => ReceiptStatus::Voided,
            'voided_by' => $this->operador()->id,
            'voided_at' => now(),
            'void_reason' => 'Prueba: el comprobante del ingreso deja de estar vigente.',
        ])->save();

        $this->entregar($cuota)->assertSessionHasErrors('installmentId');

        $this->assertSame(0, Disbursement::query()->count());
    }

    /**
     * Invariante 13, impuesto por la base.
     *
     * El Action da el mensaje legible, pero la regla vive en un trigger:
     * si algún día alguien escribe el comprobante salteándose el Action,
     * PostgreSQL lo rechaza igual.
     */
    public function test_la_base_rechaza_un_recibo_de_egreso_sin_egreso_confirmado(): void
    {
        $cuota = $this->cuotaCobrada();
        $ingreso = $this->reciboDeIngreso($cuota);

        $this->expectExceptionMessageMatches('/egreso confirmado/');

        DB::table('receipts')->insert([
            'document_series_id' => $ingreso->document_series_id,
            'number' => 9999,
            'formatted_number' => '0020/00009999',
            'receipt_type' => ReceiptType::Expense->value,
            'person_id' => $cuota->haber->beneficiary_id,
            'beneficiary_installment_id' => $cuota->id,
            'medium_snapshot' => 'cash',
            'amount' => $cuota->importeEsperado(),
            'issue_date' => now()->toDateString(),
            'status' => ReceiptStatus::Issued->value,
            'issue_mode' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** El doble clic no paga dos veces ni emite dos papeles. */
    public function test_el_segundo_envio_no_duplica_la_entrega(): void
    {
        $cuota = $this->cuotaCobrada();
        $clave = 'egreso-mostrador-unico';

        $this->entregar($cuota, ['idempotencyKey' => $clave])->assertSessionHasNoErrors();
        $this->entregar($cuota, ['idempotencyKey' => $clave])->assertSessionHasNoErrors();

        $this->assertSame(1, Disbursement::query()->count());
        $this->assertSame(1, $this->recibosDeEgreso()->count());
    }

    /**
     * Y tampoco con otra clave.
     *
     * La idempotencia cubre el reintento del mismo formulario; el índice
     * único cubre el resto, que es lo que importa: dos egresos vivos por
     * la misma cuota serían dos pagos por el mismo dinero.
     */
    public function test_una_cuota_ya_pagada_no_se_vuelve_a_pagar(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->entregar($cuota)->assertSessionHasNoErrors();
        $this->entregar($cuota->refresh())->assertSessionHasNoErrors();

        $this->assertSame(1, Disbursement::query()->count());
        $this->assertSame(1, $this->recibosDeEgreso()->count());
    }

    /** La fecha de la entrega no puede ser futura. */
    public function test_la_fecha_de_la_entrega_no_puede_ser_futura(): void
    {
        $this->entregar($this->cuotaCobrada(), ['paymentDate' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('paymentDate');

        $this->assertSame(0, Disbursement::query()->count());
    }

    /** El papel impreso es el del egreso, no el del ingreso. */
    public function test_la_impresion_elige_el_formulario_por_el_tipo(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->entregar($cuota)->assertSessionHasNoErrors();

        $egreso = $this->recibosDeEgreso()->firstOrFail();

        $html = $this->actingAs($this->operador())
            ->get(route('recibos.view', $egreso))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('RECIBO DE EGRESO', $html);
        $this->assertStringContainsString('Recibí conforme', $html);
        // Y el de ingreso sigue saliendo con el suyo.
        $ingreso = $this->actingAs($this->operador())
            ->get(route('recibos.view', $this->reciboDeIngreso($cuota)))
            ->assertOk()
            ->getContent();

        $this->assertIsString($ingreso);
        $this->assertStringContainsString('RECIBO DE INGRESO', $ingreso);
    }

    public function test_quien_solo_consulta_no_entrega(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador('consulta'))
            ->post(route('haberes.installments.disbursement', $cuota), $this->datos())
            ->assertForbidden();

        $this->assertSame(0, Disbursement::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * Una cuota financiada por mostrador y con su recibo de ingreso.
     *
     * Es el punto de partida real del egreso: el empleador ya pagó y el
     * dinero está en la caja esperando que el trabajador se presente.
     */
    private function cuotaCobrada(bool $cheque = false): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->orderBy('installment_number')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => $cheque ? 'cheque' : 'cash'])->save();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'receivedDate' => now()->toDateString(),
                'idempotencyKey' => 'cobro-'.Str::random(10),
                ...($cheque ? ['chequeNumber' => '12345678', 'chequeBank' => 'Banco Nación'] : []),
            ])
            ->assertSessionHasNoErrors();

        return $cuota->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function entregar(BeneficiaryInstallment $cuota, array $extra = []): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement', $cuota), [
                ...$this->datos(),
                ...$extra,
            ]);
    }

    /**
     * Lo único que el formulario aporta.
     *
     * El importe y el método no están: los deriva el servidor de la propia
     * cuota, igual que en el cobro.
     *
     * @return array<string, mixed>
     */
    private function datos(): array
    {
        return [
            'paymentDate' => now()->toDateString(),
            'idempotencyKey' => 'egreso-'.Str::random(10),
        ];
    }

    /** @return Builder<Receipt> */
    private function recibosDeEgreso()
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Expense);
    }

    private function reciboDeIngreso(BeneficiaryInstallment $cuota): Receipt
    {
        return Receipt::query()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $cuota->id)
            ->latest('id')
            ->firstOrFail();
    }
}
