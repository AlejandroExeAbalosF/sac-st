<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\ReceiveAndAllocateTicket;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Del ticket cruzado a la cuota financiada, en un solo acto.
 *
 * **Fusiona clics, no hechos.** Los dos asientos —la recepción y la
 * asignación— se siguen escribiendo por separado y con los mismos Actions;
 * lo que desaparece es recorrer dos pantallas tipeando importes que el
 * sistema ya conoce.
 *
 * Estos tests miran las dos cosas: que el libro quede igual que por el
 * camino largo, y que el atajo se plante cuando el caso pide una decisión
 * que él no puede tomar.
 */
class RegistrarYAsignarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El caso normal: un crédito que cubre la cuota, en un clic. */
    public function test_registra_y_asigna_en_un_solo_acto(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $esperado = $cuota->importeEsperado();

        $recepcion = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );

        $this->assertSame($esperado, $recepcion->amount);
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
    }

    /**
     * El libro queda como si se hubiera hecho a mano.
     *
     * Es el punto de todo: el atajo no inventa una forma nueva de asentar
     * plata, usa la de siempre. Si esto fallara, habría dos verdades
     * contables según por dónde entró el operador.
     */
    public function test_los_asientos_son_los_de_siempre(): void
    {
        $cuota = $this->cuotaConTicketCruzado();

        $recepcion = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['BANK_ACCOUNT', 'UNASSIGNED_FUNDS'], $cuentas);

        // Y el crédito del extracto queda imputado, no suelto.
        $this->assertSame(
            1,
            BankTransactionAllocation::query()
                ->where('financial_event_id', $recepcion->financial_event_id)
                ->count(),
        );
    }

    /**
     * Con la recepción ya registrada, termina el trabajo en vez de
     * empezarlo de nuevo.
     *
     * Es el caso de quien hizo el camino largo a medias. Asentar el dinero
     * dos veces sería duplicar un ingreso que entró una sola vez.
     */
    public function test_si_la_recepcion_ya_existe_solo_asigna(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();

        /*
         * El estado real de quien hizo el camino largo a medias: la
         * recepción registrada por su propio Action, sin asignar. No se
         * arma borrando una asignación —no se pueden borrar, se revierten—
         * sino no creándola nunca.
         */
        $existente = app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'a-mano-'.Str::random(8),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
            actorId: $this->operador()->id,
        );

        $recepcion = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );

        $this->assertSame(
            $existente->id,
            $recepcion->id,
            'Registró una recepción nueva en vez de terminar la que ya estaba.',
        );
        $this->assertSame(1, FundReceipt::query()->count(), 'El dinero quedó asentado dos veces.');
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
    }

    /** Sin cruce no hay de dónde tomar el dinero. */
    public function test_sin_ticket_cruzado_no_hace_nada(): void
    {
        $cuota = $this->cuota();

        try {
            app(ReceiveAndAllocateTicket::class)->handle($cuota, 'atajo-'.Str::random(8));
            $this->fail('Registró un ingreso sin comprobante cruzado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cruzado', $e->getMessage());
        }

        $this->assertSame(0, FundReceipt::query()->count());
    }

    /** Una cuota ya completa no tiene nada que recibir. */
    public function test_una_cuota_completa_no_vuelve_a_recibir(): void
    {
        $cuota = $this->cuotaConTicketCruzado();

        app(ReceiveAndAllocateTicket::class)->handle($cuota, 'atajo-'.Str::random(8));

        $this->expectException(ValidationException::class);
        app(ReceiveAndAllocateTicket::class)->handle($cuota->refresh(), 'atajo-'.Str::random(8));
    }

    /** Repetir exactamente el mismo envío devuelve el mismo resultado. */
    public function test_el_reintento_con_la_misma_clave_es_idempotente(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $clave = 'atajo-'.Str::random(8);

        $primera = app(ReceiveAndAllocateTicket::class)->handle($cuota, $clave, $this->operador()->id);
        $segunda = app(ReceiveAndAllocateTicket::class)->handle($cuota->refresh(), $clave, $this->operador()->id);

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, FundReceipt::query()->count());
        $this->assertSame(1, FundingAllocation::query()->live()->count());
    }

    /** Un crédito ya dividido necesita el flujo que deja elegir la recepción. */
    public function test_un_credito_con_varias_recepciones_deriva_al_flujo_manual(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->valueOrFail('id');

        foreach (['1.00', '1.00'] as $indice => $importe) {
            app(RegisterBankFundReceipt::class)->handle(
                transaction: $movimiento,
                amount: $importe,
                idempotencyKey: "recepcion-dividida-{$indice}",
                cashBoxId: $caja,
                actorId: $this->operador()->id,
            );
        }

        $this->expectExceptionMessageMatches('/dividido en varias recepciones/u');
        app(ReceiveAndAllocateTicket::class)->handle($cuota, 'atajo-'.Str::random(8));
    }

    /** La Orden usa el saldo de cada imputación, no su importe histórico. */
    public function test_las_fuentes_descontaron_reversiones_parciales(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $recepcion = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );
        $original = FundingAllocation::query()->live()->firstOrFail();

        app(UnallocateFunds::class)->handle(
            allocation: $original,
            amount: '40.00',
            idempotencyKey: 'liberar-parcial-'.Str::random(8),
            notes: 'Corrección parcial para probar el saldo documental.',
            actorId: $this->operador()->id,
        );
        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota->refresh(),
            amount: '40.00',
            idempotencyKey: 'reasignar-parcial-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $fuentes = app(PaymentOrderSources::class)->rows($cuota->refresh());

        $this->assertCount(2, $fuentes);
        $this->assertSame($cuota->importeEsperado(), app(PaymentOrderSources::class)->total($fuentes));
        $this->assertSame('40.00', $fuentes[1]->amount);
    }

    /** Una imputación completamente revertida ya no fija fuente ni medio. */
    public function test_una_fuente_totalmente_revertida_deja_de_estar_vigente(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );
        $original = FundingAllocation::query()->live()->firstOrFail();

        app(UnallocateFunds::class)->handle(
            allocation: $original,
            amount: $original->amount,
            idempotencyKey: 'liberar-total-'.Str::random(8),
            notes: 'Corrección total para probar el saldo documental.',
            actorId: $this->operador()->id,
        );

        $this->assertSame([], app(PaymentOrderSources::class)->rows($cuota->refresh()));
        $this->assertNull(app(InstallmentFunding::class)->medium($cuota->refresh()));
    }

    /** Y desde la pantalla, con su permiso. */
    public function test_el_boton_de_la_cuota_lo_hace(): void
    {
        $cuota = $this->cuotaConTicketCruzado();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('haberes.installments.receive-and-allocate', $cuota), [
                'idempotencyKey' => 'atajo-'.Str::random(8),
            ])
            ->assertRedirect();

        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
    }

    /** El atajo hace dos actos y por eso exige los dos permisos. */
    public function test_el_atajo_exige_permiso_para_registrar_y_asignar(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $soloRegistra = $this->operador('consulta');
        $soloRegistra->givePermissionTo('recepciones.registrar');

        $this->actingAs($soloRegistra)
            ->post(route('haberes.installments.receive-and-allocate', $cuota), [
                'idempotencyKey' => 'atajo-'.Str::random(8),
            ])
            ->assertForbidden();

        $this->assertSame(0, FundReceipt::query()->count());
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function cuota(): BeneficiaryInstallment
    {
        return Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
    }

    /** Una cuota con su comprobante ya cruzado con el crédito del extracto. */
    private function cuotaConTicketCruzado(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $cuenta = $this->cuenta();

        $movimiento = BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-08-21',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'operation_id' => '83690105',
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);

        DepositTicket::query()->create([
            'beneficiary_installment_id' => $cuota->id,
            'haber_id' => $cuota->haber_id,
            'expediente_id' => $cuota->haber->expediente_id,
            'bank_account_id' => $cuenta->id,
            'bank_transaction_id' => $movimiento->id,
            'amount' => $importe,
            'deposited_at' => '2026-08-21',
            'deposit_kind' => DepositKind::Transfer,
            'status' => DepositTicketStatus::Matched,
            // La base exige el par: un ticket cruzado dice cuándo se cruzó.
            'matched_by' => $this->operador()->id,
            'matched_at' => now(),
            'created_by' => $this->operador()->id,
        ]);

        return $cuota->refresh();
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '23456789'],
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
            'period_from' => '2026-08-21',
            'period_to' => '2026-08-21',
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
