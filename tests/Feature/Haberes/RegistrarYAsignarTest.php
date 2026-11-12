<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CancelHaber;
use App\Modules\Haberes\Actions\DiscardDepositTicket;
use App\Modules\Haberes\Actions\FindTicketCandidates;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\LinkDepositTicket;
use App\Modules\Haberes\Actions\ListAvailableCredits;
use App\Modules\Haberes\Actions\ReceiveAndAllocateTicket;
use App\Modules\Haberes\Actions\ReverseFundReceipt;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\DepositTicketLinks;
use App\Modules\Haberes\Support\HaberesBankMovementReferences;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Haberes\Support\ReceiptFormData;
use App\Modules\Haberes\Support\ReceiptOrigin;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
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

        $this->abrirLibrosSinSaldo();

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
        // Las casillas de «Depósito Cta.» llevan el día del crédito en el extracto.
        $this->assertSame('2026-08-21', app(ReceiptFormData::class)->forInstallment($cuota)->fechaDeposito?->toDateString());
    }

    public function test_registra_dos_creditos_del_mismo_comprobante_sin_duplicar_ingresos(): void
    {
        config(['haberes.split_deposit_match_enabled' => true]);
        $cuota = $this->cuota();
        $cuenta = $this->cuenta();
        $importe = $cuota->importeEsperado();
        $mitad = Decimal::scale(bcdiv($importe, '2', 4));
        $resto = Decimal::sub($importe, $mitad);
        $importacion = $this->importacion($cuenta);
        $movimientos = [];
        foreach (['2026-08-21' => $mitad, '2026-08-22' => $resto] as $fecha => $parte) {
            $movimientos[] = BankTransaction::query()->create([
                'bank_account_id' => $cuenta->id,
                'first_seen_import_id' => $importacion->id,
                'transaction_date' => $fecha,
                'amount' => $parte,
                'direction' => TransactionDirection::Credit,
                'description' => 'Transferencia recibida',
                'fingerprint' => hash('sha256', uniqid('', true)),
            ]);
        }
        $ticket = DepositTicket::query()->create([
            'beneficiary_installment_id' => $cuota->id,
            'haber_id' => $cuota->haber_id,
            'expediente_id' => $cuota->haber->expediente_id,
            'bank_account_id' => $cuenta->id,
            'amount' => $importe,
            'deposited_at' => '2026-08-21',
            'deposit_kind' => DepositKind::Transfer,
            'status' => DepositTicketStatus::Waiting,
        ]);
        app(LinkDepositTicket::class)->handleMany($ticket, $movimientos, $this->operador()->id);
        $viewer = $this->operador();
        $referencias = app(HaberesBankMovementReferences::class)->forTransactions($movimientos, $viewer);
        foreach ($movimientos as $movimiento) {
            $this->assertSame('ticket_matched', $referencias[$movimiento->id][0]['status']);
            $this->assertSame($cuota->id, $referencias[$movimiento->id][0]['installmentId']);
        }

        $clave = 'atajo-'.Str::random(8);
        app(ReceiveAndAllocateTicket::class)->handle($cuota, $clave, $this->operador()->id);
        app(ReceiveAndAllocateTicket::class)->handle($cuota, $clave, $this->operador()->id);

        $this->assertSame($importe, app(InstallmentFunding::class)->allocated($cuota));
        $this->assertSame(2, FundReceipt::query()->whereNull('reversal_event_id')->count());
        $this->assertSame(2, BankTransactionAllocation::query()->whereNull('reversal_of_id')->count());
        $referencias = app(HaberesBankMovementReferences::class)->forTransactions($movimientos, $viewer);
        foreach ($movimientos as $movimiento) {
            $this->assertSame('funds_allocated', $referencias[$movimiento->id][0]['status']);
            $this->assertSame($movimiento->amount, $referencias[$movimiento->id][0]['amount']);
        }
        foreach (FundReceipt::query()->whereNull('reversal_event_id')->get() as $recepcion) {
            $this->assertSame($ticket->expediente_id, app(ReceiptOrigin::class)->for($recepcion)['expedienteId']);
        }
        // Con dos créditos, el recibo lleva el día del último: el que completó la cuota.
        $this->assertSame('2026-08-22', app(ReceiptFormData::class)->forInstallment($cuota)->fechaDeposito?->toDateString());

        // Desasignado del todo el del 22, ya no financia la cuota: vuelve el del 21.
        $delVeintidos = FundingAllocation::query()->live()
            ->join('fund_receipts', 'fund_receipts.id', '=', 'funding_allocations.fund_receipt_id')
            ->join('bank_transaction_allocations', 'bank_transaction_allocations.financial_event_id', '=', 'fund_receipts.financial_event_id')
            ->where('bank_transaction_allocations.bank_transaction_id', $movimientos[1]->id)
            ->select('funding_allocations.*')
            ->firstOrFail();
        app(UnallocateFunds::class)->handle(
            allocation: $delVeintidos,
            amount: $delVeintidos->amount,
            idempotencyKey: 'liberar-22-'.Str::random(8),
            motivo: new Reason(HaberesReasonCode::Other, 'Prueba de la fecha del recibo.'),
            actorId: $this->operador()->id,
        );
        $this->assertSame('2026-08-21', app(ReceiptFormData::class)->forInstallment($cuota)->fechaDeposito?->toDateString());

        // Se vuelve a asignar la misma recepción para seguir con el recibo: vuelve el 22.
        app(AllocateFundsToInstallment::class)->handle(
            FundReceipt::query()->findOrFail($delVeintidos->fund_receipt_id),
            $cuota->refresh(),
            $delVeintidos->amount,
            'reasignar-22-'.Str::random(8),
            $this->operador()->id,
        );
        $this->assertSame('2026-08-22', app(ReceiptFormData::class)->forInstallment($cuota)->fechaDeposito?->toDateString());

        $this->emitirRecibo($cuota);
        $this->anularElCobro($cuota);
        $this->assertSame([], $ticket->refresh()->transactionIds());
        foreach ($movimientos as $movimiento) {
            $this->assertSame('0.00', app(AllocatableAmount::class)->allocated($movimiento));
        }
        $this->assertSame([], app(HaberesBankMovementReferences::class)->forTransactions($movimientos, $viewer));
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
            motivo: new Reason(HaberesReasonCode::Other, 'Corrección parcial para probar el saldo documental.'),
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
            motivo: new Reason(HaberesReasonCode::Other, 'Corrección total para probar el saldo documental.'),
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

    /** La conciliación se ofrece desde la cuota solo a quien puede entrar. */
    public function test_la_pantalla_del_haber_dice_si_se_puede_buscar_el_comprobante(): void
    {
        $haber = $this->cuota()->haber;
        $sinPermiso = User::factory()->create();
        $sinPermiso->givePermissionTo('expedientes.ver');

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertInertia(fn (Assert $page) => $page->where('canSearchTickets', true));

        $this->actingAs($sinPermiso)
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertInertia(fn (Assert $page) => $page->where('canSearchTickets', false));
    }

    /*
    |---------------------------------------------------------------------
    | Después de revertir la recepción
    |---------------------------------------------------------------------
    |
    | La reversión promete que el crédito «vuelve a quedar sin imputar». Las
    | imputaciones no se borran —se les agrega la reversa—, así que preguntar
    | si existe una fila, o tomar la recepción sin mirar si sigue viva, deja
    | el crédito libre en el extracto y sin ningún camino para usarlo.
    */

    /** La misma cuota se vuelve a cobrar: nace otra recepción. */
    public function test_tras_revertir_el_ticket_vuelve_a_cobrar_con_una_recepcion_nueva(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $revertida = $this->cobrarYRevertir($cuota);

        $nueva = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota->refresh(),
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );

        $this->assertNotSame($revertida->id, $nueva->id);
        $this->assertNull($nueva->reversal_event_id);
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));

        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $this->assertSame('0.00', app(AllocatableAmount::class)->for($movimiento));
    }

    /**
     * La tarjeta no presenta como vigente una recepción revertida.
     *
     * Es el dato que le dice al operador si falta registrar o falta asignar:
     * con la revertida adentro lo mandaba a asignar algo que la base rechaza.
     */
    public function test_el_vinculo_del_ticket_ignora_la_recepcion_revertida(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $this->cobrarYRevertir($cuota);

        $this->assertSame([], app(DepositTicketLinks::class)->receipts([$cuota->id]));

        $nueva = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota->refresh(),
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );

        $this->assertSame(
            [$ticket->id => $nueva->id],
            app(DepositTicketLinks::class)->receipts([$cuota->id]),
        );
    }

    /** El ticket se suelta y el crédito queda disponible para otro. */
    public function test_tras_revertir_el_ticket_se_desvincula_y_libera_el_credito(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $this->cobrarYRevertir($cuota);

        app(LinkDepositTicket::class)->unlink($ticket, 'Era el depósito de otro expediente', $this->operador()->id);

        $this->assertSame(DepositTicketStatus::Waiting, $ticket->refresh()->status);
        $this->assertNull($ticket->bank_transaction_id);

        $otro = $this->otroTicket($movimiento);
        $this->assertSame(1, app(ListAvailableCredits::class)->handle($otro)->total());
        $this->assertCount(1, app(FindTicketCandidates::class)->handle($otro)->candidates);

        app(LinkDepositTicket::class)->handle($otro, $movimiento, $this->operador()->id);
        $this->assertSame($movimiento->id, $otro->refresh()->bank_transaction_id);
    }

    /** Sigue sin soltarse mientras la recepción esté viva, aunque sin dueño. */
    public function test_con_la_recepcion_viva_el_ticket_no_se_desvincula(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $this->cobrarYLiberar($cuota);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revertir la recepción');

        app(LinkDepositTicket::class)->unlink($ticket, 'Era el depósito de otro expediente', $this->operador()->id);
    }

    /** Con dos recepciones y una sola revertida, el crédito sigue respaldando dinero. */
    public function test_con_una_recepcion_viva_de_dos_el_ticket_no_se_desvincula(): void
    {
        $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->valueOrFail('id');

        foreach (['primera', 'segunda'] as $clave) {
            $recepcion = app(RegisterBankFundReceipt::class)->handle(
                transaction: $movimiento,
                amount: '1.00',
                idempotencyKey: "dividida-{$clave}",
                cashBoxId: $caja,
                actorId: $this->operador()->id,
            );
        }

        app(ReverseFundReceipt::class)->handle($recepcion, new Reason(HaberesReasonCode::Other, 'Se registró de más.'), 'revertir-segunda');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('revertir la recepción');

        app(LinkDepositTicket::class)->unlink($ticket->refresh(), 'Era el depósito de otro expediente', $this->operador()->id);
    }

    /**
     * El recorrido completo de un haber cargado de más.
     *
     * Anular no libera el depósito, y eso es deliberado: cada paso es una
     * decisión con su motivo. Lo que se prueba es que al final de esos pasos
     * el crédito queda de verdad disponible y el comprobante se puede dar de
     * baja.
     */
    public function test_anular_el_haber_y_desarmar_el_dinero_deja_el_credito_disponible(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $recepcion = $this->cobrarYLiberar($cuota);

        app(CancelHaber::class)->handle($cuota->haber->refresh(), new Reason(HaberesReasonCode::DataEntryError));

        // Anular no toca el banco: el crédito sigue tomado por la recepción.
        $this->assertSame('0.00', app(AllocatableAmount::class)->for($movimiento->refresh()));

        app(ReverseFundReceipt::class)->handle($recepcion, new Reason(HaberesReasonCode::Other, 'El haber se cargó por error.'), 'revertir-'.Str::random(8));
        app(LinkDepositTicket::class)->unlink($ticket, 'El haber se cargó por error.', $this->operador()->id);
        app(DiscardDepositTicket::class)->handle(
            $ticket->refresh(),
            new Reason(HaberesReasonCode::WrongExpediente),
            $this->operador()->id,
        );

        $this->assertSame(DepositTicketStatus::Discarded, $ticket->refresh()->status);
        $this->assertSame($movimiento->amount, app(AllocatableAmount::class)->for($movimiento->refresh()));
        $this->assertSame(1, app(ListAvailableCredits::class)->handle($this->otroTicket($movimiento))->total());
    }

    /*
    |---------------------------------------------------------------------
    | Anular el recibo deshace la conciliación
    |---------------------------------------------------------------------
    */

    /** Un solo acto, con un solo motivo, y el movimiento queda libre en el extracto. */
    public function test_anular_el_recibo_deshace_toda_la_conciliacion(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();

        $recepcion = app(ReceiveAndAllocateTicket::class)->handle($cuota, 'atajo-'.Str::random(8), $this->operador()->id);
        $recibo = $this->emitirRecibo($cuota);

        $this->assertSame('0.00', app(AllocatableAmount::class)->for($movimiento->refresh()));

        $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.void-collection', $cuota), [
                'reasonCode' => 'wrong_installment',
                'reason' => 'Era de la cuota siguiente.',
                'idempotencyKey' => 'anular-'.Str::random(8),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));
        $this->assertSame('voided', $recibo->refresh()->status->value);
        $this->assertSame('wrong_installment', $recibo->void_reason_code);
        $this->assertNotNull($recepcion->refresh()->reversal_event_id);
        $this->assertSame('wrong_installment', $recepcion->reversal_reason_code);

        $this->assertSame(DepositTicketStatus::Waiting, $ticket->refresh()->status);
        $this->assertNull($ticket->bank_transaction_id);
        $this->assertSame($movimiento->amount, app(AllocatableAmount::class)->for($movimiento->refresh()));
    }

    /** Lo deshecho se puede volver a conciliar: el depósito no se perdió. */
    public function test_el_movimiento_liberado_se_vuelve_a_conciliar(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $ticket = DepositTicket::query()->latest('id')->firstOrFail();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();

        app(ReceiveAndAllocateTicket::class)->handle($cuota, 'atajo-'.Str::random(8), $this->operador()->id);
        $this->anularElCobro($cuota);

        app(LinkDepositTicket::class)->handle($ticket->refresh(), $movimiento->refresh(), $this->operador()->id);
        $nueva = app(ReceiveAndAllocateTicket::class)->handle($cuota->refresh(), 'atajo-'.Str::random(8), $this->operador()->id);

        $this->assertNull($nueva->reversal_event_id);
        $this->assertSame($cuota->importeEsperado(), app(InstallmentFunding::class)->allocated($cuota->refresh()));
        $this->assertSame(2, FundReceipt::query()->where('medium', 'bank')->count());
    }

    /** Sin deshacer: si la recepción también financia otra cuota, se rechaza y dice cuál. */
    public function test_no_se_anula_si_la_recepcion_financia_otra_cuota(): void
    {
        $cuota = $this->cuotaConTicketCruzado();
        $movimiento = BankTransaction::query()->latest('id')->firstOrFail();
        $otra = BeneficiaryInstallment::query()
            ->where('id', '!=', $cuota->id)
            ->where('workflow_status', 'active')
            ->where('expected_medium', 'bank')
            ->orderBy('id')
            ->firstOrFail();

        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->valueOrFail('id');
        $recepcion = app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $movimiento->amount,
            idempotencyKey: 'compartida-'.Str::random(8),
            cashBoxId: $caja,
            actorId: $this->operador()->id,
        );
        $parte = Decimal::scale('10.00');
        app(AllocateFundsToInstallment::class)->handle($recepcion, $cuota, $parte, 'parte-1', $this->operador()->id);
        app(AllocateFundsToInstallment::class)->handle($recepcion, $otra, $parte, 'parte-2', $this->operador()->id);

        try {
            $this->anularElCobro($cuota);
            $this->fail('Anuló una recepción que también financia otra cuota.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('también financia la cuota', $e->errors()['installmentId'][0]);
        }

        $this->assertNull($recepcion->refresh()->reversal_event_id);
        $this->assertSame($parte, app(InstallmentFunding::class)->allocated($cuota->refresh()));
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function anularElCobro(BeneficiaryInstallment $cuota): void
    {
        app(VoidCashCollection::class)->handle(
            installment: $cuota->refresh(),
            motivo: new Reason(HaberesReasonCode::WrongInstallment),
            idempotencyKey: 'anular-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    private function emitirRecibo(BeneficiaryInstallment $cuota): Receipt
    {
        return app(IssueIncomeReceipt::class)->handle(
            installment: $cuota->refresh(),
            actorId: $this->operador()->id,
        );
    }

    private function cobrarYLiberar(BeneficiaryInstallment $cuota): FundReceipt
    {
        $recepcion = app(ReceiveAndAllocateTicket::class)->handle(
            $cuota,
            'atajo-'.Str::random(8),
            $this->operador()->id,
        );
        $imputacion = FundingAllocation::query()->live()->firstOrFail();

        app(UnallocateFunds::class)->handle(
            allocation: $imputacion,
            amount: $imputacion->amount,
            idempotencyKey: 'liberar-'.Str::random(8),
            motivo: new Reason(HaberesReasonCode::Other, 'Se cobró en la cuota equivocada.'),
            actorId: $this->operador()->id,
        );

        return $recepcion;
    }

    private function cobrarYRevertir(BeneficiaryInstallment $cuota): FundReceipt
    {
        return app(ReverseFundReceipt::class)->handle(
            $this->cobrarYLiberar($cuota),
            new Reason(HaberesReasonCode::Other, 'No correspondía a este expediente.'),
            'revertir-'.Str::random(8),
        );
    }

    /** Un comprobante en espera, de otro expediente, por el mismo depósito. */
    private function otroTicket(BankTransaction $movimiento): DepositTicket
    {
        return DepositTicket::query()->create([
            'expediente_id' => Expediente::query()->whereNotNull('employer_id')->orderByDesc('id')->firstOrFail()->id,
            'bank_account_id' => $movimiento->bank_account_id,
            'amount' => $movimiento->amount,
            'deposited_at' => '2026-08-21',
            'deposit_kind' => DepositKind::Transfer,
            'status' => DepositTicketStatus::Waiting,
            'created_by' => $this->operador()->id,
        ]);
    }

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
