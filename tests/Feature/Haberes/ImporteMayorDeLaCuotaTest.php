<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Data\InstallmentListItemData;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * Lo que entra de más por una cuota: se registra en la cuota, con su motivo,
 * antes de cobrarla. El recibo muestra lo que se debía y lo que se recibió.
 */
final class ImporteMayorDeLaCuotaTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();
        $this->seed(HaberesDemoSeeder::class);
    }

    // ── Registrar ───────────────────────────────────────────────────────

    public function test_el_total_mayor_queda_como_excedente_de_la_cuota(): void
    {
        $cuota = $this->cuota('50001/2026', '100000.00');

        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $cuota->refresh();
        $this->assertSame('110000.00', $cuota->importeEsperado());
        $this->assertSame('10000.00', $cuota->excedente());
        $this->assertSame('100000.00', $cuota->importeAAbonar());
        $this->assertSame('employer_overpaid', $cuota->excess_reason_code?->value);
        $this->assertTrue(AuditEvent::query()->where('action', 'cuota.excedente-registrado')->exists());
        // No movió dinero.
        $this->assertSame(0, FundReceipt::query()->count());
    }

    public function test_con_el_total_igual_a_lo_que_se_debe_se_quita_el_excedente(): void
    {
        $cuota = $this->cuota('50002/2026', '100000.00');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->registrar($cuota->refresh(), '100000.00', ['remove' => true, 'reasonCode' => 'data_entry_error'])
            ->assertSessionHasNoErrors();

        $cuota->refresh();
        $this->assertSame('100000.00', $cuota->importeEsperado());
        $this->assertSame('0.00', $cuota->excedente());
        $this->assertNull($cuota->excess_reason_code);
        $this->assertTrue(AuditEvent::query()->where('action', 'cuota.excedente-quitado')->exists());
    }

    public function test_un_total_menor_o_igual_sin_excedente_se_rechaza(): void
    {
        $cuota = $this->cuota('50003/2026', '100000.00');

        $this->registrar($cuota, '90000.00')->assertSessionHasErrors('amount');
        $this->registrar($cuota, '100000.00')->assertSessionHasErrors('amount');

        $this->assertSame('0.00', $cuota->refresh()->excedente());
    }

    public function test_una_cuota_con_dinero_imputado_ya_no_admite_el_importe_mayor(): void
    {
        $cuota = $this->cuota('50004/2026', '100000.00');
        $this->cobrarPorMostrador($cuota)->assertSessionHasNoErrors();

        $this->registrar($cuota->refresh(), '110000.00')->assertSessionHasErrors('amount');
    }

    public function test_una_version_vieja_se_rechaza(): void
    {
        $cuota = $this->cuota('50005/2026', '100000.00');
        $vieja = $cuota->updated_at->copy()->subMinute();

        $this->registrar($cuota, '110000.00', ['version' => $vieja->toISOString()])->assertSessionHasErrors('amount');
    }

    public function test_con_un_comprobante_esperando_el_total_es_el_del_comprobante(): void
    {
        $cuota = $this->cuota('50006/2026', '100000.00');
        $this->comprobante($cuota, '110000.00', DepositTicketStatus::Waiting);

        $this->registrar($cuota, '120000.00')->assertSessionHasErrors('amount');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->assertSame('110000.00', $cuota->refresh()->importeEsperado());
    }

    /** Un depósito fraccionado: el total es lo que suman los comprobantes, y la pantalla lo recibe sumado. */
    public function test_con_dos_comprobantes_esperando_el_total_es_la_suma(): void
    {
        $cuota = $this->cuota('50016/2026', '100000.00');
        $this->comprobante($cuota, '60000.00', DepositTicketStatus::Waiting);
        $this->comprobante($cuota, '50000.00', DepositTicketStatus::Waiting);

        $this->assertSame(
            '110000.00',
            InstallmentListItemData::fromModel($cuota->load('depositTickets.account'))->waitingTicketsAmount,
        );

        $this->registrar($cuota, '60000.00')->assertSessionHasErrors('amount');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->assertSame('10000.00', $cuota->refresh()->excedente());
    }

    public function test_con_un_comprobante_cruzado_se_rechaza(): void
    {
        $cuota = $this->cuota('50007/2026', '100000.00');
        $this->comprobante($cuota, '110000.00', DepositTicketStatus::Matched);

        $this->registrar($cuota, '110000.00')->assertSessionHasErrors('amount');
    }

    // ── Corregir lo que se debe ─────────────────────────────────────────

    public function test_corregir_lo_que_se_debe_conserva_el_total_registrado(): void
    {
        $cuota = $this->cuotaDeUnPlanAbierto('50008/2026');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->corregir($cuota->refresh(), '105000.00')->assertSessionHasNoErrors();
        $cuota->refresh();
        $this->assertSame('110000.00', $cuota->importeEsperado());
        $this->assertSame('5000.00', $cuota->excedente());

        $this->corregir($cuota, '110000.00')->assertSessionHasNoErrors();
        $cuota->refresh();
        $this->assertSame('110000.00', $cuota->importeEsperado());
        $this->assertSame('0.00', $cuota->excedente());
        $this->assertNull($cuota->excess_reason_code);

        $this->registrar($cuota, '120000.00')->assertSessionHasNoErrors();
        $this->corregir($cuota->refresh(), '125000.00')->assertSessionHasErrors('amount');
        $this->assertSame('120000.00', $cuota->refresh()->importeEsperado());
    }

    // ── Cobrar y el recibo ──────────────────────────────────────────────

    public function test_se_cobra_el_total_y_el_recibo_congela_lo_que_se_debia(): void
    {
        $cuota = $this->cuota('50009/2026', '100000.00');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->cobrarPorMostrador($cuota->refresh())->assertSessionHasNoErrors();

        $recibo = Receipt::query()->sole();
        $this->assertSame('110000.00', $recibo->amount);
        $this->assertSame('100000.00', $recibo->amount_due_snapshot);
        $this->assertSame('110000.00', FundReceipt::query()->sole()->amount);

        // Corregir después lo que se debe no cambia el papel.
        $this->corregir($cuota->refresh(), '95000.00')->assertSessionHasNoErrors();
        $this->assertSame('100000.00', $recibo->refresh()->amount_due_snapshot);

        $html = $this->actingAs($this->operador())->get(route('recibos.view', $recibo))->getContent();
        $this->assertStringContainsString('Importe a abonar', (string) $html);
        $this->assertStringContainsString('$ 100.000,00', (string) $html);
        $this->assertStringContainsString('$ 110.000,00', (string) $html);
    }

    public function test_un_recibo_sin_excedente_no_muestra_lo_que_se_debia(): void
    {
        $cuota = $this->cuota('50010/2026', '100000.00');

        $this->cobrarPorMostrador($cuota)->assertSessionHasNoErrors();

        $recibo = Receipt::query()->sole();
        $this->assertNull($recibo->amount_due_snapshot);

        $html = $this->actingAs($this->operador())->get(route('recibos.view', $recibo))->getContent();
        $this->assertStringNotContainsString('Importe a abonar', (string) $html);
    }

    /** Otro registró el importe mayor mientras el cobro estaba abierto. */
    public function test_el_cobro_rechaza_un_importe_que_cambio_mientras_tanto(): void
    {
        $cuota = $this->cuota('50011/2026', '100000.00');
        $visto = app(InstallmentFunding::class)->remaining($cuota);

        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();

        $this->cobrarPorMostrador($cuota->refresh(), $visto)->assertSessionHasErrors('installmentId');

        $this->assertSame(0, FundReceipt::query()->count());
        $this->assertSame(0, Receipt::query()->count());
    }

    // ── El importe mayor dentro del cobro ───────────────────────────────

    public function test_el_cobro_registra_el_importe_mayor_en_el_mismo_acto(): void
    {
        $cuota = $this->cuota('50020/2026', '100000.00');

        $this->cobrarConImporteMayor($cuota, '110000.00')->assertSessionHasNoErrors();

        $cuota->refresh();
        $this->assertSame('110000.00', $cuota->importeEsperado());
        $this->assertSame('10000.00', $cuota->excedente());
        $recibo = Receipt::query()->sole();
        $this->assertSame('110000.00', $recibo->amount);
        $this->assertSame('100000.00', $recibo->amount_due_snapshot);
        $this->assertSame('110000.00', FundReceipt::query()->sole()->amount);
        $this->assertTrue(AuditEvent::query()->where('action', 'cuota.excedente-registrado')->exists());
    }

    /** Si el cobro falla, el importe mayor tampoco queda: es un solo acto. */
    public function test_si_el_cobro_falla_no_queda_el_importe_mayor(): void
    {
        $cuota = $this->cuota('50021/2026', '100000.00');

        // Lo confirmado no es el total escrito: el cobro se rechaza adentro de la transacción.
        $this->cobrarConImporteMayor($cuota, '110000.00', confirmado: '100000.00')->assertSessionHasErrors('installmentId');

        $this->assertSame('0.00', $cuota->refresh()->excedente());
        $this->assertSame('100000.00', $cuota->importeEsperado());
        $this->assertSame(0, FundReceipt::query()->count());
        $this->assertSame(0, Receipt::query()->count());
    }

    public function test_el_importe_mayor_en_el_cobro_pide_el_motivo(): void
    {
        $cuota = $this->cuota('50022/2026', '100000.00');

        $this->cobrarConImporteMayor($cuota, '110000.00', motivo: '')->assertSessionHasErrors('reasonCode');

        $this->assertSame(0, Receipt::query()->count());
    }

    /** Cobrar no alcanza: cambiar lo que la cuota espera pide el permiso de corregirla. */
    public function test_quien_solo_cobra_no_registra_el_importe_mayor(): void
    {
        $cuota = $this->cuota('50023/2026', '100000.00');

        $this->cobrarConImporteMayor($cuota, '110000.00', rol: 'contador')->assertForbidden();

        $this->assertSame('0.00', $cuota->refresh()->excedente());
    }

    public function test_la_vista_previa_muestra_el_total_escrito(): void
    {
        $cuota = $this->cuota('50024/2026', '100000.00');

        $html = (string) $this->actingAs($this->operador())
            ->get(route('haberes.installments.receipt.preview', $cuota).'?excessTotal=110000')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Importe a abonar', $html);
        $this->assertStringContainsString('$ 100.000,00', $html);
        $this->assertStringContainsString('$ 110.000,00', $html);
        // Mirar no registra nada.
        $this->assertSame('0.00', $cuota->refresh()->excedente());
    }

    // ── La base ─────────────────────────────────────────────────────────

    public function test_la_base_rechaza_un_excedente_que_no_es_menor_que_el_total(): void
    {
        $cuota = $this->cuota('50012/2026', '100000.00');

        $this->expectException(QueryException::class);

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update([
            'excess_amount' => '100000.00',
            'excess_reason_code' => 'other',
            'excess_reason' => 'Prueba',
        ]);
    }

    public function test_la_base_exige_el_motivo_del_excedente(): void
    {
        $cuota = $this->cuota('50013/2026', '100000.00');

        $this->expectException(QueryException::class);

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update([
            'expected_amount' => '110000.00',
            'excess_amount' => '10000.00',
        ]);
    }

    public function test_la_base_pone_tope_a_lo_que_se_debe(): void
    {
        $cuota = $this->cuota('50014/2026', '100000.00');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        // Con el excedente registrado, el total puede superar lo reconocido.
        DB::table('beneficiary_installments')->where('id', $cuota->id)->update([
            'expected_amount' => '110000.00',
            'excess_amount' => '10000.00',
            'excess_reason_code' => 'employer_overpaid',
        ]);

        // Sin él, no.
        $this->expectException(QueryException::class);

        DB::table('beneficiary_installments')->where('id', $cuota->id)->update([
            'excess_amount' => '5000.00',
        ]);
    }

    public function test_lo_que_se_debia_en_el_recibo_no_se_edita(): void
    {
        $cuota = $this->cuota('50015/2026', '100000.00');
        $this->registrar($cuota, '110000.00')->assertSessionHasNoErrors();
        $this->cobrarPorMostrador($cuota->refresh())->assertSessionHasNoErrors();

        $this->expectException(QueryException::class);

        DB::table('receipts')->where('id', Receipt::query()->sole()->id)->update(['amount_due_snapshot' => '95000.00']);
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $extra */
    private function registrar(BeneficiaryInstallment $cuota, string $total, array $extra = []): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.record-excess', $cuota), [
                'amount' => $total,
                'version' => $cuota->updated_at->toISOString(),
                'reasonCode' => 'employer_overpaid',
                ...$extra,
            ]);
    }

    private function corregir(BeneficiaryInstallment $cuota, string $aAbonar): TestResponse
    {
        $haber = $cuota->haber;

        return $this->actingAs($this->operador())
            ->patch(route('haberes.installments.update', [$haber->expediente, $haber, $cuota]), [
                'amount' => $aAbonar,
                'expectedMedium' => $cuota->expected_medium->value,
                'version' => $cuota->updated_at->toISOString(),
            ]);
    }

    private function cobrarPorMostrador(BeneficiaryInstallment $cuota, ?string $visto = null): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'confirmedAmount' => $visto ?? app(InstallmentFunding::class)->remaining($cuota),
                'receivedDate' => BusinessDate::today()->toDateString(),
                'idempotencyKey' => 'cobro-'.Str::random(10),
            ]);
    }

    private function cobrarConImporteMayor(
        BeneficiaryInstallment $cuota,
        string $total,
        ?string $confirmado = null,
        string $motivo = 'employer_overpaid',
        string $rol = 'administrativo',
    ): TestResponse {
        return $this->actingAs($this->operador($rol))
            ->post(route('haberes.installments.receipt', $cuota), [
                'confirmedAmount' => $confirmado ?? $total,
                'excessTotal' => $total,
                'version' => $cuota->updated_at->toISOString(),
                'reasonCode' => $motivo,
                'receivedDate' => BusinessDate::today()->toDateString(),
                'idempotencyKey' => 'cobro-'.Str::random(10),
            ]);
    }

    /** Una cuota de $ 100.000 de un haber de $ 300.000 en tres cuotas, con dos por cargar. */
    private function cuotaDeUnPlanAbierto(string $expediente): BeneficiaryInstallment
    {
        $cuota = $this->cuota($expediente, '100000.00');
        $cuota->haber->forceFill(['assigned_amount' => '300000.00', 'expected_installment_count' => 3, 'payment_terms' => 'installments'])->save();

        return $cuota->refresh();
    }

    private function comprobante(BeneficiaryInstallment $cuota, string $importe, DepositTicketStatus $estado): DepositTicket
    {
        $cuenta = BankAccount::query()->firstOrCreate(
            ['account_number' => '23456789'],
            ['label' => 'Cta. Cte. Haberes', 'bank_name' => 'Banco Macro', 'currency' => 'ARS', 'is_active' => true],
        );

        $hoy = BusinessDate::today()->toDateString();
        $cruzado = $estado === DepositTicketStatus::Matched;

        return DepositTicket::query()->create([
            'beneficiary_installment_id' => $cuota->id,
            'haber_id' => $cuota->haber_id,
            'expediente_id' => $cuota->haber->expediente_id,
            'bank_account_id' => $cuenta->id,
            'amount' => $importe,
            'deposited_at' => $hoy,
            'deposit_kind' => DepositKind::CashDeposit,
            'status' => $estado,
            'created_by' => $this->operador()->id,
            ...($cruzado ? [
                'bank_transaction_id' => $this->credito($cuenta, $importe, $hoy)->id,
                'matched_by' => $this->operador()->id,
                'matched_at' => now(),
            ] : []),
        ]);
    }

    private function credito(BankAccount $cuenta, string $importe, string $fecha): BankTransaction
    {
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
            'description' => 'Deposito en efectivo',
            'fingerprint' => hash('sha256', $cuenta->id.$fecha.$importe.uniqid()),
        ]);
    }
}
