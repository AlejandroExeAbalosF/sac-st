<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\FindCashDepositCandidates;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\RegisterCashPayment;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El efectivo que nadie retiró se lleva al banco — §9.5 del DER.
 *
 * Es un **traslado interno, no un ingreso nuevo**: el dinero sigue siendo
 * del mismo beneficiario y sigue imputado a la misma cuota. Lo único que
 * cambia es dónde está.
 *
 * Y son dos asientos, no uno, porque entre que el efectivo sale de la caja
 * y el banco lo acredita hay una ventana en la que ese dinero no está en
 * ninguno de los dos lugares.
 */
class TrasladoDeEfectivoTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA = '310000123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El efectivo sale de la caja y queda en tránsito, no en el banco. */
    public function test_el_deposito_deja_el_dinero_en_transito(): void
    {
        $cuota = $this->cuotaCobrada();

        $traslado = $this->depositar($cuota);

        $this->assertSame(CashTransferStatus::Deposited, $traslado->status);
        $this->assertSame($cuota->importeEsperado(), $traslado->amount);

        $this->assertSame(
            ['CASH_IN_TRANSIT', 'CASH_ON_HAND'],
            $this->cuentasDe($traslado->deposit_event_id),
        );
    }

    /**
     * El dinero no cambia de dueño: sigue siendo del beneficiario.
     *
     * Es la confusión más fácil de cometer acá. Si el traslado tocara
     * `BENEFICIARY_FUNDS`, depositar el efectivo equivaldría a devolverlo,
     * y la cuota quedaría sin respaldo de un día para el otro.
     */
    public function test_el_traslado_no_toca_los_fondos_del_beneficiario(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        $tocadas = DB::table('journal_lines')
            ->where('financial_event_id', $traslado->deposit_event_id)
            ->pluck('account_code')
            ->all();

        $this->assertNotContains('BENEFICIARY_FUNDS', $tocadas);
    }

    /** Y el recibo sigue diciendo «Efectivo», porque eso fue lo que pasó. */
    public function test_el_recibo_no_se_modifica(): void
    {
        $cuota = $this->cuotaCobrada();
        $antes = Receipt::query()->firstOrFail();

        $this->depositar($cuota);

        $despues = Receipt::query()->firstOrFail();

        $this->assertSame('cash', $despues->medium_snapshot);
        $this->assertSame($antes->formatted_number, $despues->formatted_number);
        $this->assertSame($antes->amount, $despues->amount);
    }

    /** El vínculo con la cuota no se pierde al depositar (§2.1, punto 146). */
    public function test_el_item_conserva_de_quien_era_el_efectivo(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        $item = CashToBankTransferItem::query()->firstOrFail();
        $asignacion = FundingAllocation::query()->firstOrFail();

        $this->assertSame($traslado->id, $item->cash_to_bank_transfer_id);
        $this->assertSame($asignacion->id, $item->funding_allocation_id);
        $this->assertSame($cuota->id, $asignacion->beneficiary_installment_id);
    }

    /** El ticket del cajero queda adjunto: es la prueba de que salió. */
    public function test_el_ticket_queda_adjunto(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        $adjunto = DB::table('attachments')
            ->where('subject_type', 'cash_transfer')
            ->where('subject_id', $traslado->id)
            ->first();

        $this->assertNotNull($adjunto);
    }

    /** El mismo efectivo no se deposita dos veces. */
    public function test_no_se_deposita_dos_veces_el_mismo_efectivo(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $this->expectException(ValidationException::class);
        $this->depositar($cuota);
    }

    /**
     * Y lo impide la base, no solo el Action.
     *
     * Se saltea la validación a propósito: si el índice viviera solo en
     * PHP, cualquier camino nuevo hacia estas tablas podría duplicar
     * efectivo sin que nada lo note.
     */
    public function test_la_base_rechaza_el_segundo_item_sobre_la_misma_asignacion(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);
        $item = CashToBankTransferItem::query()->firstOrFail();

        $this->expectExceptionMessageMatches('/ya se deposito/i');

        DB::table('cash_to_bank_transfer_items')->insert([
            'cash_to_bank_transfer_id' => $traslado->id,
            'fund_receipt_id' => $item->fund_receipt_id,
            'funding_allocation_id' => $item->funding_allocation_id,
            'amount' => $item->amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Sin recibo emitido no hay traslado.
     *
     * La cuota está cobrada y el efectivo está en la caja: lo que falta es
     * el papel. No es formalismo — el recibo documenta de quién se recibió
     * ese dinero, y depositar antes dejaría un movimiento bancario
     * respaldado por nada.
     */
    public function test_no_se_deposita_una_cuota_sin_recibo(): void
    {
        $cuota = $this->cuotaEnEfectivo();

        app(RegisterCashPayment::class)->handle(
            installment: $cuota,
            amount: $cuota->importeEsperado(),
            idempotencyKey: 'cobro-sin-recibo',
            cashBoxId: (int) CashBox::query()->where('code', 'haberes')->value('id'),
            receivedDate: now(),
            actorId: $this->operador()->id,
        );

        $this->assertSame(0, Receipt::query()->count());

        $this->expectExceptionMessage('Primero hay que emitir el recibo');
        $this->depositar($cuota->refresh());
    }

    /** Una cuota bancaria no tiene efectivo que llevar. */
    public function test_no_se_deposita_una_cuota_que_vino_por_transferencia(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $cuota->forceFill(['expected_medium' => 'bank'])->save();

        $this->expectException(ValidationException::class);
        $this->depositar($cuota->refresh());
    }

    /** El doble clic no deposita dos veces. */
    public function test_el_segundo_envio_devuelve_el_mismo_traslado(): void
    {
        $cuota = $this->cuotaCobrada();
        $clave = 'traslado-unico';

        $primero = $this->depositar($cuota, clave: $clave);
        $segundo = $this->depositar($cuota, clave: $clave);

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, CashToBankTransfer::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La acreditación
    |--------------------------------------------------------------------------
    */

    /** El crédito del extracto cierra la ventana de tránsito. */
    public function test_la_acreditacion_lleva_el_dinero_al_banco(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $confirmado = $this->confirmar($traslado, $credito);

        $this->assertSame(CashTransferStatus::BankConfirmed, $confirmado->status);
        $this->assertNotNull($confirmado->credit_event_id);

        $this->assertSame(
            ['BANK_ACCOUNT', 'CASH_IN_TRANSIT'],
            $this->cuentasDe($confirmado->credit_event_id),
        );
    }

    /**
     * Y ese crédito ya no se puede contar de nuevo como una recepción.
     *
     * Es el escenario que duplica plata: el depósito propio llega al
     * extracto como cualquier otro crédito y, si quedara disponible,
     * alguien podría registrarlo además como dinero que entró.
     */
    public function test_el_credito_acreditado_queda_sin_saldo_libre(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $this->confirmar($traslado, $credito);

        $this->assertSame(
            ReconciliationStatus::Reconciled,
            $credito->refresh()->reconciliation_status,
        );
        $this->assertSame('0.00', app(AllocatableAmount::class)->for($credito));
    }

    /** Un crédito anterior al depósito no es su acreditación. */
    public function test_un_credito_anterior_al_deposito_no_es_candidato(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        $this->credito($traslado->amount, '2026-08-01');
        $bueno = $this->credito($traslado->amount, '2026-08-27');

        $candidatos = app(FindCashDepositCandidates::class)->handle($traslado);

        $this->assertCount(1, $candidatos);
        $this->assertSame($bueno->id, $candidatos[0]->transaction->id);
    }

    /** El importe se compara exacto: un centavo de más es otro depósito. */
    public function test_un_credito_por_otro_importe_no_es_candidato(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        $this->credito('1.00', '2026-08-26');

        $this->assertCount(0, app(FindCashDepositCandidates::class)->handle($traslado));
    }

    /** El doble clic no acredita dos veces. */
    public function test_el_segundo_envio_de_la_acreditacion_es_inocuo(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $this->confirmar($traslado, $credito, 'acreditacion-unica');
        $this->confirmar($traslado, $credito, 'acreditacion-unica');

        $this->assertSame(1, DB::table('bank_transaction_allocations')->count());
    }

    /*
    |--------------------------------------------------------------------------
    | La salida cuando el filtro duro no encuentra
    |--------------------------------------------------------------------------
    */

    /**
     * «No hay candidatos» casi nunca significa que el crédito no exista.
     *
     * El filtro duro pide importe exacto y una ventana corta. Cuando no
     * encuentra, el listado ancho muestra los créditos disponibles de la
     * cuenta para que el operador reconozca el movimiento real —el banco
     * acreditó más tarde, o la fecha del depósito se cargó mal—.
     */
    public function test_el_listado_ancho_ofrece_lo_que_el_filtro_duro_descarta(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        // Fuera de la ventana y por otro importe: el filtro duro no lo ve.
        $lejano = $this->credito(Decimal::sub($traslado->amount, '500.00'), '2026-09-20');

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado))
            ->assertOk()
            ->assertJsonCount(0, 'candidates');

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado).'?todos=1')
            ->assertOk()
            ->assertJsonPath('todos', true)
            ->assertJsonPath('candidates.0.id', $lejano->id)
            ->assertJsonPath('candidates.0.signals.importe', 'difiere en $ 500,00');
    }

    /** Y ordena por cercanía: primero el importe, después la fecha. */
    public function test_el_listado_ancho_ordena_por_cercania(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        // Más reciente pero mucho más lejos en importe.
        $this->credito(Decimal::add($traslado->amount, '400000.00'), '2026-08-26');
        $parecido = $this->credito(Decimal::add($traslado->amount, '10.00'), '2026-10-01');

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado).'?todos=1')
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $parecido->id);
    }

    /** Un crédito ya imputado no vuelve a ofrecerse ni en el listado ancho. */
    public function test_el_listado_ancho_no_ofrece_lo_ya_imputado(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $this->confirmar($traslado, $credito);

        // Otro traslado, para preguntar por el mismo crédito desde afuera.
        $otro = CashToBankTransfer::query()->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $otro).'?todos=1')
            ->assertOk()
            ->assertJsonCount(0, 'candidates');
    }

    /**
     * El aviso del extracto se verifica, no se supone.
     *
     * Decir «puede que falte importar» sin mirarlo es una excusa que el
     * operador no puede comprobar sin salir de la pantalla.
     */
    public function test_la_pantalla_sabe_si_el_extracto_esta_importado(): void
    {
        $traslado = $this->depositar($this->cuotaCobrada());

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado))
            ->assertJsonPath('periodImported', false);

        // Importar el extracto de ese día cambia la afirmación.
        $this->importacion($this->cuenta(), $traslado->deposit_date->toDateString());

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado))
            ->assertJsonPath('periodImported', true);
    }

    /*
    |--------------------------------------------------------------------------
    | La pantalla
    |--------------------------------------------------------------------------
    */

    /** La tarjeta de la cuota recibe el traslado y su estado. */
    public function test_la_cuota_llega_con_su_traslado(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.cashTransfer.id', $traslado->id)
                ->where('haber.installments.0.cashTransfer.status', 'deposited')
                ->where('haber.installments.0.cashTransfer.amount', $cuota->importeEsperado())
                ->where('canTransferCash', true)
                ->where('canConfirmTransfer', true),
            );
    }

    /** Y sin traslado llega en `null`, que es lo que habilita el botón. */
    public function test_una_cuota_sin_traslado_llega_en_null(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.cashTransfer', null),
            );
    }

    /** Acreditado, la tarjeta trae con qué decirlo. */
    public function test_el_traslado_acreditado_llega_con_su_movimiento(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $this->confirmar($traslado, $credito);

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.cashTransfer.status', 'bank_confirmed')
                ->where('haber.installments.0.cashTransfer.bankTransactionId', $credito->id),
            );

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'traslado.acreditado')
            ->assertJsonFragment([
                'field' => 'Movimiento del extracto',
                'before' => null,
                'after' => 'Movimiento n.º '.$credito->id,
            ]);
    }

    /**
     * La tarjeta y el canal miran el mismo traslado.
     *
     * Estuvieron resueltos por dos consultas distintas —una pedía
     * asignaciones con saldo en pie, la otra cualquiera que no fuera una
     * reversión— y coincidían de casualidad: lo que las mantenía de acuerdo
     * era la guarda de `UnallocateFunds`, que vive en otro archivo. Ahora
     * las dos salen de `PaymentOrderSources::transfersFor()`, y este caso
     * es el que lo fija: si alguien vuelve a escribir el criterio por
     * segunda vez, tiene que escribirlo igual o esto se cae.
     */
    public function test_la_tarjeta_y_el_canal_ven_el_mismo_traslado(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        $fuentes = app(PaymentOrderSources::class);

        $this->assertSame($traslado->id, $fuentes->transferOf($cuota)?->id);
        $this->assertSame(
            $traslado->id,
            $fuentes->transfersFor([(int) $cuota->id])[$cuota->id]?->id,
        );

        // Y la tarjeta, que es el tercer lugar donde se veía.
        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.cashTransfer.id', $traslado->id),
            );
    }

    /** Sin traslado, las dos vías contestan lo mismo: nada. */
    public function test_sin_traslado_las_dos_vias_contestan_nada(): void
    {
        $cuota = $this->cuotaCobrada();

        $fuentes = app(PaymentOrderSources::class);

        $this->assertNull($fuentes->transferOf($cuota));
        $this->assertSame([], $fuentes->transfersFor([(int) $cuota->id]));
    }

    /**
     * El traslado cancelado no cuenta para ninguna de las dos.
     *
     * Es el caso que separa «hubo un traslado alguna vez» de «el efectivo
     * está en el banco»: cancelado, la plata volvió al cajón y la cuota
     * vuelve a pagarse por mostrador. El historial sí lo sigue mostrando,
     * y eso es a propósito.
     */
    public function test_un_traslado_cancelado_no_cuenta(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        $traslado->forceFill(['status' => CashTransferStatus::Cancelled])->save();

        $fuentes = app(PaymentOrderSources::class);

        $this->assertNull($fuentes->transferOf($cuota->refresh()));
        $this->assertSame([], $fuentes->transfersFor([(int) $cuota->id]));
        $this->assertSame(PaymentChannel::Counter, $fuentes->channel($cuota));
    }

    /** La pantalla del traslado llega con lo que hay que transcribir. */
    public function test_la_pantalla_del_traslado_trae_la_cuota_y_su_recibo(): void
    {
        $cuota = $this->cuotaCobrada();
        $recibo = Receipt::query()->firstOrFail();

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.transfer.create', $cuota))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/traslados/create')
                ->where('cuota.amount', $cuota->importeEsperado())
                ->where('cuota.receiptNumber', $recibo->formatted_number)
                ->has('accounts'),
            );
    }

    /**
     * Sin recibo la pantalla no existe.
     *
     * Se corta antes de dibujar un formulario que el Action va a rechazar
     * igual: llenarlo entero para que falle al enviarlo es peor que no
     * ofrecerlo.
     */
    public function test_la_pantalla_del_traslado_no_existe_sin_recibo(): void
    {
        $this->actingAs($this->operador())
            ->get(route('haberes.installments.transfer.create', $this->cuotaEnEfectivo()))
            ->assertNotFound();
    }

    /** El circuito entero por HTTP, como lo recorre el operador. */
    public function test_el_circuito_completo_desde_la_pantalla(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.transfer', $cuota), [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => '2026-08-25',
                'depositTime' => '10:35',
                'operationNumber' => '995979830',
                'ticket' => UploadedFile::fake()->image('ticket.jpg'),
                'idempotencyKey' => 'traslado-http',
            ])
            ->assertSessionHasNoErrors();

        $traslado = CashToBankTransfer::query()->firstOrFail();
        $credito = $this->credito($traslado->amount, '2026-08-26');

        $this->actingAs($this->operador())
            ->get(route('traslados.candidates', $traslado))
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $credito->id);

        $this->actingAs($this->operador())
            ->post(route('traslados.confirm', $traslado), [
                'bankTransactionId' => $credito->id,
                'idempotencyKey' => 'acreditacion-http',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            CashTransferStatus::BankConfirmed,
            $traslado->refresh()->status,
        );
    }

    /** Sin ticket no hay depósito, y lo dice el formulario. */
    public function test_la_pantalla_exige_el_ticket(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.transfer', $cuota), [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => '2026-08-25',
                'idempotencyKey' => 'traslado-sin-ticket',
            ])
            ->assertSessionHasErrors('ticket');

        $this->assertSame(0, CashToBankTransfer::query()->count());
    }

    /**
     * El ticket del cajero se valida con el mismo criterio que el del
     * expediente: por lo que el archivo es, no por cómo se llama.
     */
    public function test_la_pantalla_rechaza_un_archivo_disfrazado_de_ticket(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.transfer', $cuota), [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => '2026-08-25',
                'ticket' => $this->archivoSubido('ticket.jpg', 'esto no es una foto'),
                'idempotencyKey' => 'traslado-disfrazado',
            ])
            ->assertSessionHasErrors([
                'ticket' => 'El comprobante puede ser una foto JPG, PNG o WEBP, o un PDF.',
            ]);

        $this->assertSame(0, CashToBankTransfer::query()->count());
    }

    /** Quien solo consulta no mueve efectivo. */
    public function test_quien_solo_consulta_no_traslada(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->actingAs($this->operador('consulta'))
            ->post(route('haberes.installments.transfer', $cuota), [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => '2026-08-25',
                'ticket' => UploadedFile::fake()->image('ticket.jpg'),
                'idempotencyKey' => 'traslado-consulta',
            ])
            ->assertForbidden();

        $this->assertSame(0, CashToBankTransfer::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function verHaber(BeneficiaryInstallment $cuota): TestResponse
    {
        $haber = $cuota->haber;

        return $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk();
    }

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

    /** Una cuota cobrada por mostrador, con su recibo emitido. */
    /*
     |--------------------------------------------------------------------
     | El historial de la cuota
     |--------------------------------------------------------------------
     |
     | El traslado se audita con el traslado como sujeto, no con la cuota,
     | así que quedaba afuera del historial. Un depósito cancelado
     | desaparecía por completo de la pantalla: la tarjeta no muestra los
     | cancelados, y el rastro quedaba solo en la base.
     |
     */

    /**
     * El depósito dado de baja se sigue viendo en la tarjeta.
     *
     * La tarjeta trae solo el traslado vigente, así que sin esto un
     * depósito cargado y cancelado desaparecía y la cuota volvía a ofrecer
     * «Depositar en el banco» como si nada hubiera pasado. Es lo mismo que
     * ya se hacía con el recibo anulado.
     */
    public function test_la_tarjeta_muestra_el_traslado_dado_de_baja(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);
        $operador = $this->operador();

        app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'El importe estaba mal copiado del ticket.',
            idempotencyKey: 'cancelacion-'.Str::random(8),
            actorId: $operador->id,
        );

        $haber = $cuota->haber;

        $this->actingAs($operador)
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // El vigente ya no está: el efectivo volvió a la caja.
                ->where('haber.installments.0.cashTransfer', null)
                ->has('haber.installments.0.cancelledTransfers', 1)
                ->where(
                    'haber.installments.0.cancelledTransfers.0.cancelReason',
                    'El importe estaba mal copiado del ticket.',
                )
                ->where(
                    'haber.installments.0.cancelledTransfers.0.cancelledByName',
                    $operador->name,
                ));
    }

    /** Sin bajas, la lista llega vacía y la tarjeta no dibuja nada. */
    public function test_una_cuota_sin_bajas_no_lista_traslados_cancelados(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $haber = $cuota->haber;

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('haber.installments.0.cancelledTransfers', 0));
    }

    public function test_el_historial_de_la_cuota_cuenta_su_traslado(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'traslado.depositado')
            ->assertJsonFragment(['field' => 'Importe'])
            ->assertJsonFragment(['field' => 'Fecha del depósito'])
            // El id de la cuenta no le dice nada a nadie: va su etiqueta.
            ->assertJsonFragment([
                'field' => 'Cuenta',
                'before' => null,
                'after' => $this->cuenta()->label,
            ]);
    }

    /** Lo deshecho es justamente lo que el historial tiene que contar. */
    public function test_el_historial_cuenta_el_traslado_cancelado_y_su_motivo(): void
    {
        $cuota = $this->cuotaCobrada();
        $traslado = $this->depositar($cuota);

        app(CancelCashToBankTransfer::class)->handle(
            transfer: $traslado,
            reason: 'La fecha estaba mal copiada del ticket.',
            idempotencyKey: 'cancelacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonPath('events.0.action', 'traslado.cancelado')
            ->assertJsonFragment([
                'field' => 'Motivo',
                'before' => null,
                'after' => 'La fecha estaba mal copiada del ticket.',
            ])
            // Y el depósito que se deshizo sigue estando: el historial
            // cuenta las dos cosas, no la última.
            ->assertJsonPath('events.1.action', 'traslado.depositado');
    }

    /** El enlace interno con la cuota no es un dato para leer. */
    public function test_el_historial_no_muestra_el_enlace_con_la_cuota(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.history', $cuota))
            ->assertOk()
            ->assertJsonMissing(['field' => 'beneficiary_installment_id']);
    }

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function depositar(
        BeneficiaryInstallment $cuota,
        ?string $clave = null,
    ): CashToBankTransfer {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $this->cuenta()->id,
                'depositDate' => now()->parse('2026-08-25'),
                'depositTime' => '10:35:00',
                'operationNumber' => '995979830',
                'terminal' => 'T-014',
                'notes' => null,
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: $clave ?? 'traslado-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    private function confirmar(
        CashToBankTransfer $traslado,
        BankTransaction $credito,
        ?string $clave = null,
    ): CashToBankTransfer {
        return app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $credito,
            idempotencyKey: $clave ?? 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    /** @return list<string> */
    private function cuentasDe(int $eventoId): array
    {
        /** @var list<string> $cuentas */
        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $eventoId)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        return $cuentas;
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => self::CUENTA],
            [
                'label' => 'Cta. Cte. 2693 — Haberes',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function credito(string $importe, string $fecha): BankTransaction
    {
        $cuenta = $this->cuenta();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta, $fecha)->id,
            'transaction_date' => $fecha,
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Deposito en efectivo',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function importacion(BankAccount $cuenta, string $fecha): BankStatementImport
    {
        return BankStatementImport::query()->create([
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
    }
}
