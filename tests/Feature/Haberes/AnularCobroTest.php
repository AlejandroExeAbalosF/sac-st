<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Anular un cobro por mostrador: el dinero nunca entró.
 *
 * El caso que lo vuelve necesario es el cero de más. El cobro por
 * mostrador toma el importe **de la cuota**: si decía $1.000.000 y el
 * empleador trajo $100.000, el libro quedó afirmando que hay $1.000.000 en
 * la caja.
 *
 * **Liberar no alcanza ahí**, y de hecho empeora: movería $900.000
 * inexistentes al pozo de no identificados, donde quedarían ofreciéndose
 * para otra cuota. Lo que hay que deshacer es la recepción misma.
 */
class AnularCobroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El dinero sale de la caja: el libro deja de afirmar que está. */
    public function test_anular_saca_el_dinero_de_la_caja(): void
    {
        $cuota = $this->cuotaCobrada();
        $importe = $cuota->expected_amount;

        $this->assertSame($importe, $this->saldo('CASH_ON_HAND'));

        $this->anular($cuota)->assertSessionHasNoErrors();

        $this->assertSame('0.00', $this->saldo('CASH_ON_HAND'));
    }

    /** Y también deja de estar imputado al beneficiario. */
    public function test_anular_saca_el_dinero_del_beneficiario(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->anular($cuota);

        $this->assertSame('0.00', $this->saldo('BENEFICIARY_FUNDS'));
        $this->assertSame('0.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));
    }

    /**
     * El pozo de no identificados queda como estaba.
     *
     * Es lo que distingue anular de liberar: liberar deja la plata en el
     * pozo —existe, solo no tiene dueño—; anular la saca del sistema,
     * porque nunca entró.
     */
    public function test_anular_no_deja_plata_en_el_pozo(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->anular($cuota);

        $this->assertSame('0.00', $this->saldo('UNASSIGNED_FUNDS'));
    }

    /** La cuota vuelve a estar sin cobrar, lista para hacerlo bien. */
    public function test_la_cuota_vuelve_a_estar_sin_cobrar(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->anular($cuota);

        $this->assertFalse(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
    }

    /** El recibo queda anulado **con su número**, con motivo y responsable. */
    public function test_el_recibo_queda_anulado_con_su_numero(): void
    {
        $cuota = $this->cuotaCobrada();
        $numero = Receipt::query()->firstOrFail()->formatted_number;

        $this->anular($cuota, motivo: 'Se cargó un cero de más.');

        $recibo = Receipt::query()->firstOrFail();

        $this->assertSame(ReceiptStatus::Voided, $recibo->status);
        $this->assertSame($numero, $recibo->formatted_number);
        $this->assertSame('Se cargó un cero de más.', $recibo->void_reason);
        $this->assertNotNull($recibo->voided_at);
    }

    /*
    |--------------------------------------------------------------------------
    | El reemplazante
    |--------------------------------------------------------------------------
    */

    /** Cobrar de nuevo emite un recibo con número nuevo que apunta al anulado. */
    public function test_el_reemplazante_toma_un_numero_nuevo(): void
    {
        $cuota = $this->cuotaCobrada();
        $anulado = Receipt::query()->firstOrFail();

        $this->anular($cuota);
        $this->cobrar($cuota->refresh());

        $nuevo = Receipt::query()->where('status', ReceiptStatus::Issued)->firstOrFail();

        $this->assertNotSame($anulado->formatted_number, $nuevo->formatted_number);
        $this->assertSame($anulado->id, $nuevo->replaces_receipt_id);
        $this->assertSame(ReceiptStatus::Replaced, $anulado->refresh()->status);
    }

    /**
     * Y si otra cuota se llevó el número siguiente, toma el que sigue.
     *
     * El correlativo sale de `next_number` bajo lock en el momento de
     * emitir: el anulado no reserva nada.
     */
    public function test_el_reemplazante_no_reserva_el_numero_siguiente(): void
    {
        $cuota = $this->cuotaCobrada();
        $anulado = Receipt::query()->firstOrFail();

        $this->anular($cuota);

        // Otra cuota emite en el medio y se lleva el correlativo.
        $otra = $this->otraCuotaEnEfectivo();
        $this->cobrar($otra);
        $delMedio = Receipt::query()->where('beneficiary_installment_id', $otra->id)->firstOrFail();

        $this->cobrar($cuota->refresh());
        $nuevo = Receipt::query()
            ->where('beneficiary_installment_id', $cuota->id)
            ->where('status', ReceiptStatus::Issued)
            ->firstOrFail();

        $this->assertSame($anulado->number + 1, $delMedio->number);
        $this->assertSame($anulado->number + 2, $nuevo->number);
        $this->assertSame($anulado->id, $nuevo->replaces_receipt_id);
    }

    /** Tres intentos dejan una cadena que se lee del vigente hacia atrás. */
    public function test_la_cadena_de_reemplazos_se_encadena(): void
    {
        $cuota = $this->cuotaCobrada();
        $primero = Receipt::query()->firstOrFail();

        $this->anular($cuota);
        $this->cobrar($cuota->refresh());
        $segundo = Receipt::query()->where('status', ReceiptStatus::Issued)->firstOrFail();

        $this->anular($cuota->refresh());
        $this->cobrar($cuota->refresh());
        $tercero = Receipt::query()->where('status', ReceiptStatus::Issued)->firstOrFail();

        $this->assertSame($primero->id, $segundo->replaces_receipt_id);
        $this->assertSame($segundo->id, $tercero->replaces_receipt_id);
        $this->assertSame(3, Receipt::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Los límites
    |--------------------------------------------------------------------------
    */

    /** Lo que entró por el banco no se anula: ese dinero existe. */
    public function test_no_se_anula_un_cobro_bancario(): void
    {
        $this->anular($this->cuotaFinanciadaPorBanco())
            ->assertSessionHasErrors('installmentId');
    }

    /**
     * No se anula un cobro cuyo efectivo ya se fue al banco.
     *
     * Anular afirma que el dinero nunca entró. Si el traslado lo llevó a
     * la cuenta del organismo, esa afirmación es falsa: el depósito
     * ocurrió y hay un ticket del cajero que lo prueba. Sin esta guarda
     * quedaba un traslado huérfano y el libro afirmando a la vez que el
     * dinero nunca entró y que se depositó.
     */
    public function test_no_se_anula_un_cobro_ya_depositado(): void
    {
        $cuota = $this->cuotaCobrada();

        app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => BankAccount::query()->create([
                    'label' => 'Cta. Cte. — pruebas',
                    'bank_name' => 'Banco Macro',
                    'account_number' => '310000123456789',
                    'currency' => 'ARS',
                    'is_active' => true,
                ])->id,
                'depositDate' => now(),
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-antes-de-anular',
            actorId: $this->operador()->id,
        );

        $this->anular($cuota->refresh())
            ->assertSessionHasErrors('installmentId');

        $this->assertSame(ReceiptStatus::Issued, Receipt::query()->firstOrFail()->status);
    }

    /** Una cuota sin cobro no tiene qué anular. */
    public function test_no_se_anula_una_cuota_sin_cobro(): void
    {
        $this->anular($this->cuotaEnEfectivo())
            ->assertSessionHasErrors('installmentId');
    }

    /** El motivo es obligatorio. */
    public function test_anular_exige_un_motivo(): void
    {
        $this->anular($this->cuotaCobrada(), motivo: '')
            ->assertSessionHasErrors('reason');
    }

    /** Quien no puede anular recibos, no anula cobros. */
    public function test_quien_no_puede_anular_recibos_no_anula_cobros(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('haberes.installments.void-collection', $cuota), [
                'reason' => 'Un motivo cualquiera.',
                'idempotencyKey' => 'anular-sin-permiso',
            ])
            ->assertForbidden();

        $this->assertSame(ReceiptStatus::Issued, Receipt::query()->firstOrFail()->status);
    }

    /**
     * Los anulados llegan a la pantalla con su motivo.
     *
     * Un comprobante anulado consumio su numero y alguien lo tuvo en la
     * mano: verlo es lo que explica por que el vigente tiene el numero que
     * tiene.
     */
    public function test_la_pantalla_muestra_los_recibos_anulados(): void
    {
        $cuota = $this->cuotaCobrada();
        $anulado = Receipt::query()->firstOrFail();

        $this->anular($cuota, motivo: 'Un cero de mas al cargar.');
        $this->cobrar($cuota->refresh());

        $haber = $cuota->haber;

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('haber.installments.0.voidedReceipts', 1)
                ->where('haber.installments.0.voidedReceipts.0.formattedNumber', $anulado->formatted_number)
                ->where('haber.installments.0.voidedReceipts.0.voidReason', 'Un cero de mas al cargar.')
                ->where('haber.installments.0.voidedReceipts.0.voidedByName', $anulado->refresh()->voidedBy?->name),
            );
    }

    /** Y queda en el rastro con su motivo. */
    public function test_la_anulacion_queda_auditada(): void
    {
        $this->anular($this->cuotaCobrada(), motivo: 'Un cero de más al cargar.');

        $evento = DB::table('audit_events')->where('action', 'cobro.anulado')->first();

        $this->assertNotNull($evento);
        $this->assertStringContainsString('Un cero de más al cargar.', (string) $evento->metadata);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaEnEfectivo(): BeneficiaryInstallment
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

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        return $cuota->refresh();
    }

    private function otraCuotaEnEfectivo(): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->whereKeyNot($this->cuotaEnEfectivo()->id)
            ->orderBy('installment_number')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        return $cuota->refresh();
    }

    /**
     * Una recepción bancaria de verdad, para probar que no se anula.
     *
     * Se arma con los Actions reales y no tocando la fila: `fund_receipts`
     * es append-only, y cambiarle el medio a una recepción de mostrador
     * sería justamente lo que el sistema impide.
     */
    private function cuotaFinanciadaPorBanco(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();
        $importe = $cuota->importeEsperado();
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. — pruebas',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'recepcion-bancaria-de-prueba',
            lines: [
                EntryLine::debit(LedgerAccount::BankAccount, $importe)->onBankAccount($cuenta->id),
                EntryLine::credit(LedgerAccount::UnassignedFunds, $importe),
            ],
            date: now(),
        );

        $recepcion = FundReceipt::query()->create([
            'financial_event_id' => $evento->id,
            'medium' => PaymentMedium::Bank,
            'amount' => $importe,
            'received_date' => now(),
        ]);

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'imputacion-bancaria-de-prueba',
        );

        return $cuota->refresh();
    }

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();
        $this->cobrar($cuota);

        return $cuota->refresh();
    }

    private function cobrar(BeneficiaryInstallment $cuota): void
    {
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(10),
            actorId: $this->operador()->id,
        );
    }

    private function anular(
        BeneficiaryInstallment $cuota,
        string $motivo = 'El importe se cargó con un cero de más.',
    ): TestResponse {
        return $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.void-collection', $cuota), [
                'reason' => $motivo,
                'idempotencyKey' => 'anular-'.Str::random(10),
            ]);
    }

    /** El saldo de una cuenta del libro: débitos menos créditos. */
    private function saldo(string $cuenta): string
    {
        $neto = DB::table('journal_lines')
            ->where('account_code', $cuenta)
            ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as neto')
            ->value('neto');

        return Decimal::abs(Decimal::scale((string) $neto));
    }
}
