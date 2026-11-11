<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\IgnoreBankTransaction;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/**
 * El egreso por transferencia cuando el banco cobra comisión.
 *
 * Una transferencia MacrOnline E-set sale del extracto en dos renglones
 * con la misma fecha y referencia: la transferencia (causal 3913) y la
 * comisión (3914). En el extracto relevado, el 04/08/2026:
 *
 * ```text
 *   86934222  3913  Transf. MacrOnline E-set D/T   −891.841,00
 *   86934222  3914  Comision Trf. MacrOL E-set         −121,00
 * ```
 *
 * **La comisión la absorbe el beneficiario**: la Orden se cancela con los
 * dos débitos, el recibo dice lo que él recibió, y el extracto queda
 * conciliado completo. Cada regla se prueba en el buscador, en la
 * vinculación y en la validación, porque las tres aplican la misma.
 */
class EgresoConComisionBancariaTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA_ORGANISMO = '23456789ARS';

    private const CBU_VALIDO = '2850000300000000000017';

    private const OPERACION = '86934222';

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo(Currency::Ars);
        $this->seed(HaberesDemoSeeder::class);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 09:00'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | El par que cierra
    |--------------------------------------------------------------------------
    */

    /** El buscador propone la transferencia y muestra su comisión. */
    public function test_el_buscador_propone_la_transferencia_con_su_comision(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');

        $candidatos = $this->candidatos($cuota);

        $this->assertCount(1, $candidatos);
        $this->assertSame($transferencia->id, $candidatos[0]['id']);
        $this->assertSame($comision->id, $candidatos[0]['fee']['id']);
        $this->assertSame('121.00', $candidatos[0]['fee']['amount']);
        $this->assertSame(Decimal::abs($transferencia->amount), $candidatos[0]['transferAmount']);
        // El par lo suma el servidor, y cada renglón dice su causal.
        $this->assertSame($this->orden($cuota)->amount, $candidatos[0]['total']);
        $this->assertSame('3913', $candidatos[0]['causalCode']);
        $this->assertSame('3914', $candidatos[0]['fee']['causalCode']);
        $this->assertNull($candidatos[0]['reviewReason']);
        $this->assertSame('exacto con la comisión de $121,00', $candidatos[0]['signals']['importe']);
    }

    /**
     * Vincular la transferencia reserva también la comisión, y validar
     * asienta e imputa los dos renglones.
     */
    public function test_vincular_y_validar_concilian_la_transferencia_y_su_comision(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->firstOrFail();
        $this->assertSame($transferencia->id, $egreso->bank_transaction_id);
        $this->assertSame($comision->id, $egreso->fee_bank_transaction_id);
        // El egreso sigue siendo por lo que autorizó la Orden.
        $this->assertSame($orden->amount, $egreso->amount);

        $this->validar($cuota)->assertSessionHasNoErrors();

        $egreso->refresh();
        $this->assertSame(DisbursementStatus::Confirmed, $egreso->status);

        // Un evento: la Orden sale de los fondos del beneficiario, en dos patas del banco.
        $lineas = DB::table('journal_lines')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->orderBy('id')
            ->get(['account_code', 'debit', 'credit']);

        $this->assertSame(
            [
                ['BENEFICIARY_FUNDS', $orden->amount, '0.00'],
                ['BANK_ACCOUNT', '0.00', Decimal::abs($transferencia->amount)],
                ['BANK_ACCOUNT', '0.00', '121.00'],
            ],
            $lineas->map(fn (object $l): array => [$l->account_code, Decimal::scale((string) $l->debit), Decimal::scale((string) $l->credit)])->all(),
        );

        // Dos imputaciones, cada una con su rol, y los dos movimientos conciliados.
        $imputaciones = DB::table('bank_transaction_allocations')
            ->where('financial_event_id', $egreso->financial_event_id)
            ->orderBy('id')
            ->get(['bank_transaction_id', 'allocation_role', 'amount']);

        $this->assertSame(
            [
                [$transferencia->id, 'payment_confirmation', Decimal::abs($transferencia->amount)],
                [$comision->id, 'bank_fee', '121.00'],
            ],
            $imputaciones->map(fn (object $i): array => [(int) $i->bank_transaction_id, $i->allocation_role, Decimal::scale((string) $i->amount)])->all(),
        );

        $this->assertSame('reconciled', $transferencia->refresh()->reconciliation_status->value);
        $this->assertSame('reconciled', $comision->refresh()->reconciliation_status->value);
    }

    /**
     * El recibo dice el egreso que confirmó el organismo —el de la Orden,
     * sin descontar la comisión— y aclara cuánto llegó a la cuenta.
     */
    public function test_el_recibo_dice_el_egreso_de_la_orden_y_aclara_la_comision(): void
    {
        $cuota = $this->cuotaValidadaConComision('121.00');
        $orden = $this->orden($cuota);

        $this->emitirRecibo($cuota)->assertSessionHasNoErrors();

        $recibo = $this->recibosDeEgreso()->firstOrFail();

        $this->assertSame($orden->amount, $recibo->amount);
        $this->assertStringEndsWith(
            sprintf('Incluye comisión bancaria $121,00 (acreditado $%s)', Decimal::format(Decimal::sub($orden->amount, '121.00'))),
            (string) $recibo->concept_snapshot,
        );
    }

    /** La comisión es la del extracto, no $121 fijos. */
    public function test_una_comision_distinta_de_121_tambien_cierra(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '145.20');

        $this->assertSame('exacto con la comisión de $145,20', $this->candidatos($cuota)[0]['signals']['importe']);

        $this->informar($cuota);
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        $this->assertSame(
            '145.20',
            Decimal::scale((string) DB::table('bank_transaction_allocations')
                ->where('bank_transaction_id', $comision->id)
                ->value('amount')),
        );
    }

    /**
     * La planilla descuenta la Orden una sola vez: el cierre suma las dos
     * patas del banco, y el libro del día lleva un renglón por el recibo,
     * que dice el total.
     */
    public function test_la_planilla_descuenta_la_orden_una_sola_vez(): void
    {
        $cuota = $this->cuotaValidadaConComision('121.00');
        $orden = $this->orden($cuota);
        $this->emitirRecibo($cuota)->assertSessionHasNoErrors();

        $egreso = Disbursement::query()->with('financialEvent')->firstOrFail();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        $dia = CarbonImmutable::parse($egreso->financialEvent->event_date->toDateString());

        $libro = app(CashDayBook::class)->entries($caja, $dia, $dia);
        $recibo = $this->recibosDeEgreso()->firstOrFail();

        $this->assertSame(
            [
                [(string) ($recibo->talonario_number ?? $recibo->formatted_number), $orden->amount],
            ],
            array_map(fn (array $fila): array => [$fila['number'], $fila['bank']], $libro['expense']),
        );

        CarbonImmutable::setTestNow($dia->addDay()->setTime(10, 0));
        $this->arqueoListoParaCerrar($caja, $dia->toDateString());

        $cierre = app(ClosePeriod::class)->handle(cashBoxId: $caja, date: $dia);

        $this->assertSame($orden->amount, $cierre->disbursed_bank_deposits);
    }

    /*
    |--------------------------------------------------------------------------
    | Lo que no cierra o no se puede afirmar
    |--------------------------------------------------------------------------
    */

    /**
     * Los débitos que no son el pago se listan aparte, para consultar, cada
     * uno con el motivo de la misma regla que vincula. El pago y su comisión
     * no se repiten ahí.
     */
    public function test_los_otros_debitos_se_listan_con_su_motivo(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        // La trampa: un traslado con una comisión de su referencia que suman la Orden.
        $traslado = $this->movimiento(Decimal::sub($orden->amount, '121.00'), causal: '3861', operacion: '87012077');
        $suelta = $this->movimiento('121.00', causal: '3914', operacion: '87012077');
        // Otro pago E-set, con su comisión, que no cierra.
        $otra = $this->movimiento('250000.00', causal: '3913', operacion: '87012001');
        $suComision = $this->movimiento('121.00', causal: '3914', operacion: '87012001');

        $respuesta = $this->actingAs($this->operador())
            ->getJson(route('haberes.installments.disbursement.debits', $cuota))
            ->assertOk();

        $this->assertSame([$transferencia->id], array_column($respuesta->json('candidates'), 'id'));

        $otros = collect($respuesta->json('others'))->keyBy('id');

        $this->assertEqualsCanonicalizing([$traslado->id, $suelta->id, $otra->id], $otros->keys()->all());
        $this->assertStringContainsString('No es una transferencia E-set', $otros[$traslado->id]['reason']);
        $this->assertStringContainsString('sin una transferencia E-set', $otros[$suelta->id]['reason']);
        $this->assertSame($suComision->id, $otros[$otra->id]['fee']['id']);
        $this->assertStringContainsString('suman $250.121,00', $otros[$otra->id]['reason']);
        $this->assertArrayNotHasKey($comision->id, $otros->all());
    }

    /** Ni la comisión sola ni la transferencia sin la comisión que falta. */
    public function test_no_propone_la_comision_sola_ni_una_transferencia_incompleta(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);

        // Una transferencia por la Orden menos $121, sin comisión en el extracto.
        $incompleta = $this->movimiento(Decimal::sub($orden->amount, '121.00'), causal: '3913', operacion: '11111111');
        // Una comisión que por sí sola iguala la Orden.
        $comision = $this->movimiento($orden->amount, causal: '3914', operacion: '22222222');

        $this->assertSame([], $this->candidatos($cuota));

        $this->vincular($cuota, $incompleta)->assertSessionHasErrors('bankTransactionId');
        $this->vincular($cuota, $comision)->assertSessionHasErrors([
            'bankTransactionId' => 'Ese movimiento es una comisión bancaria: hay que elegir la transferencia a la que acompaña.',
        ]);
    }

    /**
     * Una transferencia que sola iguala la Orden pero trajo comisión no es
     * este pago: el banco habría cobrado de más. Ni el buscador la ofrece ni
     * la vinculación la acepta.
     */
    public function test_la_transferencia_que_sola_iguala_la_orden_con_comision_no_coincide(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);
        $transferencia = $this->movimiento($orden->amount, causal: '3913');
        $this->movimiento('121.00', causal: '3914');

        $this->assertSame([], $this->candidatos($cuota));

        $this->vincular($cuota, $transferencia)->assertSessionHasErrors('bankTransactionId');
        $this->assertSame(0, Disbursement::query()->whereNotNull('bank_transaction_id')->count());
    }

    /** Dos comisiones posibles: se muestra para revisión y no se vincula. */
    public function test_dos_comisiones_en_la_operacion_quedan_para_revision(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');
        $this->movimiento('121.00', causal: '3914');

        $candidatos = $this->candidatos($cuota);

        $this->assertCount(1, $candidatos);
        $this->assertStringContainsString('trae 2 comisiones', (string) $candidatos[0]['reviewReason']);

        $this->vincular($cuota, $transferencia)->assertSessionHasErrors('bankTransactionId');
        $this->assertSame(0, Disbursement::query()->whereNotNull('bank_transaction_id')->count());
    }

    /** La única comisión quedó fuera del circuito: para revisión. */
    public function test_una_comision_fuera_del_circuito_queda_para_revision(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');

        $comision->forceFill([
            'reconciliation_status' => 'ignored',
            'ignored_reason' => 'Comisión bancaria.',
            'ignored_by' => $this->operador()->id,
            'ignored_at' => now(),
        ])->save();

        $candidatos = $this->candidatos($cuota);

        $this->assertCount(1, $candidatos);
        $this->assertStringContainsString('fuera del circuito', (string) $candidatos[0]['reviewReason']);

        $this->vincular($cuota, $transferencia)->assertSessionHasErrors('bankTransactionId');
    }

    /**
     * La salida de esa revisión: se reincorpora la comisión excluida por
     * error, y el pago se concilia completo.
     */
    public function test_la_comision_reincorporada_se_concilia_con_su_pago(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->patch(route('banco.movimientos.ignore', $comision), ['reason' => 'Comisión bancaria.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($contador)
            ->patch(route('banco.movimientos.restore', $comision), ['reasonCode' => 'belongs_to_payment'])
            ->assertSessionHasNoErrors();

        $this->assertNull($this->candidatos($cuota)[0]['reviewReason']);

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        $this->assertSame('reconciled', $comision->refresh()->reconciliation_status->value);
    }

    /**
     * Un traslado entre cuentas propias no toma la comisión de una E-set,
     * aunque compartan cuenta, fecha, referencia y la suma cierre.
     */
    public function test_un_traslado_interno_no_toma_la_comision_de_una_transferencia(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);
        $traslado = $this->movimiento(Decimal::sub($orden->amount, '121.00'), causal: '3861');
        $comision = $this->movimiento('121.00', causal: '3914');

        $this->assertSame([], $this->candidatos($cuota));
        $this->vincular($cuota, $traslado)->assertSessionHasErrors('bankTransactionId');

        // Y la base, por su cuenta, al confirmar.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Solo una transferencia E-set (3913) lleva comisión');

        DB::table('disbursements')->insert([
            ...$this->camposConfirmados(),
            'beneficiary_installment_id' => $cuota->id,
            'payment_order_id' => $orden->id,
            'method' => 'bank_transfer',
            'amount' => $orden->amount,
            'bank_transaction_id' => $traslado->id,
            'fee_bank_transaction_id' => $comision->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Excluir con la pantalla vieja no deja fuera del circuito una comisión
     * que otro operador ya concilió: la exclusión relee bajo bloqueo.
     */
    public function test_no_se_excluye_una_comision_conciliada_con_el_estado_viejo(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        // Lo que tenía en la mano el primer operador: la comisión pendiente.
        $vieja = BankTransaction::query()->findOrFail($comision->id);

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        try {
            app(IgnoreBankTransaction::class)->handle($vieja, 'Comisión bancaria.', $this->operador('contador')->id);
            $this->fail('La exclusión tenía que rechazar una comisión ya conciliada.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ya tiene imputaciones', $e->getMessage());
        }

        $this->assertSame('reconciled', $comision->refresh()->reconciliation_status->value);
    }

    /** Lo que reserva un egreso en curso tampoco se excluye. */
    public function test_no_se_excluye_una_comision_vinculada_a_un_pago_en_curso(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        $this->egresoVinculado($cuota, $transferencia);

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $comision), ['reason' => 'Comisión bancaria.'])
            ->assertSessionHasNoErrors();

        $this->assertSame('pending', $comision->refresh()->reconciliation_status->value);
    }

    /** La base rechaza excluir lo imputado, sin pasar por el Action. */
    public function test_la_base_rechaza_excluir_un_movimiento_imputado(): void
    {
        $this->cuotaValidadaConComision('121.00');
        $comision = BankTransaction::query()->findOrFail(Disbursement::query()->firstOrFail()->fee_bank_transaction_id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Un movimiento con imputaciones no se deja fuera del circuito');

        DB::table('bank_transactions')->where('id', $comision->id)->update([
            'reconciliation_status' => 'ignored',
            'ignored_reason' => 'Comisión bancaria.',
            'ignored_by' => $this->operador('contador')->id,
            'ignored_at' => now(),
        ]);
    }

    /** En Banco › Movimientos, cada débito enlaza a la cuota con su importe. */
    public function test_la_transferencia_y_su_comision_enlazan_a_la_cuota(): void
    {
        $cuota = $this->cuotaValidadaConComision('121.00');
        $egreso = Disbursement::query()->firstOrFail();
        $transferencia = BankTransaction::query()->findOrFail($egreso->bank_transaction_id);

        $this->actingAs($this->operador('contador'))
            ->get(route('banco.movimientos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where("movementReferences.{$transferencia->id}.0.installmentId", $cuota->id)
                ->where("movementReferences.{$transferencia->id}.0.amount", Decimal::abs($transferencia->amount))
                ->where("movementReferences.{$egreso->fee_bank_transaction_id}.0.installmentId", $cuota->id)
                ->where("movementReferences.{$egreso->fee_bank_transaction_id}.0.amount", '121.00')
                ->where("movementReferences.{$egreso->fee_bank_transaction_id}.0.status", 'payment_confirmed'));
    }

    /** Un débito usado en parte no se concilia: los pagos van enteros. */
    public function test_un_debito_usado_en_parte_no_se_vincula(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');

        DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $transferencia->id,
            'financial_event_id' => (int) DB::table('financial_events')->min('id'),
            'allocation_role' => 'funds_received',
            'amount' => '10.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame([], $this->candidatos($cuota));
        $this->vincular($cuota, $transferencia)->assertSessionHasErrors([
            'bankTransactionId' => 'Ese débito ya tiene una parte imputada: un pago se concilia con débitos enteros.',
        ]);
    }

    /** Lo que reserva otro egreso vivo no se ofrece ni se vincula. */
    public function test_una_comision_reservada_por_otro_egreso_no_se_vincula(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');

        $this->egresoAjeno(feeId: $comision->id, debitId: $this->movimiento('5.00', operacion: '33333333')->id);

        $this->assertSame([], $this->candidatos($cuota));
        $this->vincular($cuota, $transferencia)->assertSessionHasErrors([
            'bankTransactionId' => 'La comisión de esa operación ya está en el egreso de otra cuota.',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | La base, por su cuenta
    |--------------------------------------------------------------------------
    */

    /** Confirmar con un par que no suma la Orden. */
    public function test_la_base_rechaza_confirmar_un_par_que_no_suma(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');
        $egreso = $this->egresoVinculado($cuota, $transferencia);
        // Otra comisión de la misma operación, que no suma: aparece después de vincular.
        $otra = $this->movimiento('130.00', causal: '3914');

        DB::table('disbursements')->where('id', $egreso->id)->update(['fee_bank_transaction_id' => $otra->id]);

        $this->assertConfirmarFalla($egreso, 'suman');
    }

    /** La comisión de otra operación no acompaña a esta transferencia. */
    public function test_la_base_rechaza_una_comision_de_otra_operacion(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');
        $ajena = $this->movimiento('121.00', causal: '3914', operacion: '44444444');
        $egreso = $this->egresoVinculado($cuota, $transferencia);

        DB::table('disbursements')->where('id', $egreso->id)->update(['fee_bank_transaction_id' => $ajena->id]);

        $this->assertConfirmarFalla($egreso, 'misma cuenta, fecha y operación');
    }

    /** Un crédito no es la comisión de un pago. */
    public function test_la_base_rechaza_un_credito_como_comision(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');
        $credito = $this->movimiento('121.00', TransactionDirection::Credit);
        $egreso = $this->egresoVinculado($cuota, $transferencia);

        DB::table('disbursements')->where('id', $egreso->id)->update(['fee_bank_transaction_id' => $credito->id]);

        $this->assertConfirmarFalla($egreso, 'misma cuenta, fecha y operación');
    }

    /** Insertar un egreso ya confirmado no saltea la guarda. */
    public function test_la_base_rechaza_insertar_un_egreso_confirmado_que_no_cierra(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = $this->orden($cuota);
        $transferencia = $this->movimiento(Decimal::sub($orden->amount, '121.00'), causal: '3913');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('suman');

        DB::table('disbursements')->insert([
            ...$this->camposConfirmados(),
            'beneficiary_installment_id' => $cuota->id,
            'payment_order_id' => $orden->id,
            'method' => 'bank_transfer',
            'amount' => $orden->amount,
            'bank_transaction_id' => $transferencia->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Validado, la comisión queda congelada como el débito. */
    public function test_la_base_rechaza_cambiar_la_comision_de_un_egreso_confirmado(): void
    {
        $this->cuotaValidadaConComision('121.00');
        $egreso = Disbursement::query()->firstOrFail();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('La comisión de un egreso confirmado no se cambia');

        DB::table('disbursements')->where('id', $egreso->id)->update(['fee_bank_transaction_id' => null]);
    }

    /** La misma comisión no puede estar en dos egresos vivos. */
    public function test_la_base_rechaza_la_misma_comision_en_dos_egresos_vivos(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        $this->egresoVinculado($cuota, $transferencia);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('disbursements_one_live_per_fee');

        $this->egresoAjeno(feeId: $comision->id, debitId: $this->movimiento('5.00', operacion: '55555555')->id);
    }

    /**
     * Entre vincular y validar el extracto puede cambiar: si aparece otra
     * comisión de la operación, ya no se sabe cuál es, y validar frena.
     */
    public function test_validar_revisa_el_par_de_nuevo(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, '121.00');
        $this->egresoVinculado($cuota, $transferencia);

        $this->movimiento('121.00', causal: '3914');

        $this->validar($cuota)->assertSessionHasErrors('installmentId');

        $this->assertSame(DisbursementStatus::ReadyForValidation, Disbursement::query()->firstOrFail()->status);
        $this->assertSame(0, DB::table('bank_transaction_allocations')->where('allocation_role', 'bank_fee')->count());
    }

    /** Ni transferencia de uno y comisión de otro. */
    public function test_la_base_rechaza_un_movimiento_cruzado_entre_egresos(): void
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia, $comision] = $this->parDe($cuota, '121.00');
        $this->egresoVinculado($cuota, $transferencia);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ya está reservado por otro egreso');

        $this->egresoAjeno(feeId: null, debitId: $comision->id);
    }

    /** `bank_fee` solo sobre un débito. */
    public function test_la_base_rechaza_bank_fee_sobre_un_credito(): void
    {
        // El circuito deja asientos a los que colgar la imputación.
        $this->cuotaConOrden();
        $credito = $this->movimiento('121.00', TransactionDirection::Credit);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ese movimiento es un crédito');

        DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $credito->id,
            'financial_event_id' => (int) DB::table('financial_events')->min('id'),
            'allocation_role' => 'bank_fee',
            'amount' => '121.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * La transferencia por la Orden menos la comisión, y la comisión, en la
     * misma fecha y operación: como las trae Macro.
     *
     * @param  numeric-string  $comision
     * @return array{0: BankTransaction, 1: BankTransaction}
     */
    private function parDe(BeneficiaryInstallment $cuota, string $comision): array
    {
        $orden = $this->orden($cuota);

        return [
            $this->movimiento(Decimal::sub($orden->amount, $comision), causal: '3913', descripcion: 'Transf. MacrOnline E-set D/T'),
            $this->movimiento($comision, causal: '3914', descripcion: 'Comision Trf. MacrOL E-set'),
        ];
    }

    /** @param  numeric-string  $importe */
    private function movimiento(
        string $importe,
        TransactionDirection $direccion = TransactionDirection::Debit,
        ?string $causal = null,
        string $operacion = self::OPERACION,
        ?string $descripcion = null,
    ): BankTransaction {
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-06-11',
            // El importe en positivo: el sentido lo dice `direction`.
            'amount' => $importe,
            'direction' => $direccion,
            'operation_id' => $operacion,
            'causal_code' => $causal,
            'description' => $descripcion ?? 'Movimiento',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function cuotaValidadaConComision(string $comision): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConOrden();
        [$transferencia] = $this->parDe($cuota, $comision);

        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();
        $this->validar($cuota)->assertSessionHasNoErrors();

        return $cuota->refresh();
    }

    /** Vinculado por el Action, todavía sin validar. */
    private function egresoVinculado(BeneficiaryInstallment $cuota, BankTransaction $transferencia): Disbursement
    {
        $this->informar($cuota)->assertSessionHasNoErrors();
        $this->vincular($cuota, $transferencia)->assertSessionHasNoErrors();

        return Disbursement::query()->firstOrFail();
    }

    /** Lo que exige un egreso confirmado además del par. @return array<string, mixed> */
    private function camposConfirmados(): array
    {
        return [
            'status' => 'confirmed',
            'financial_event_id' => DB::table('financial_events')->max('id'),
            'payment_date' => '2026-06-11',
            'report_received_at' => now(),
            'bank_debit_observed_at' => now(),
            'validated_by' => $this->operador('contador')->id,
            'validated_at' => now(),
        ];
    }

    private function assertConfirmarFalla(Disbursement $egreso, string $mensaje): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($mensaje);

        DB::table('disbursements')->where('id', $egreso->id)->update($this->camposConfirmados());
    }

    /**
     * Un egreso vivo de otra cuota, insertado directo: es lo que la base
     * tiene que frenar por su cuenta.
     */
    private function egresoAjeno(?int $feeId, int $debitId): void
    {
        $otra = BeneficiaryInstallment::query()
            ->whereNotIn('id', Disbursement::query()->select('beneficiary_installment_id'))
            ->orderByDesc('id')
            ->firstOrFail();

        DB::table('disbursements')->insert([
            'beneficiary_installment_id' => $otra->id,
            'method' => 'cash',
            'amount' => '5.00',
            'status' => 'pending',
            'bank_transaction_id' => $debitId,
            'fee_bank_transaction_id' => $feeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function candidatos(BeneficiaryInstallment $cuota): array
    {
        /** @var list<array<string, mixed>> */
        return $this->actingAs($this->operador())
            ->getJson(route('haberes.installments.disbursement.debits', $cuota))
            ->assertOk()
            ->json('candidates');
    }

    private function orden(BeneficiaryInstallment $cuota): PaymentOrder
    {
        return PaymentOrder::query()->where('beneficiary_installment_id', $cuota->id)->firstOrFail();
    }

    /** Una cuota cobrada en efectivo, depositada, acreditada y con su Orden. */
    private function cuotaConOrden(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();
        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        $cuota = $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
            receivedDate: CarbonImmutable::parse('2026-05-20'),
        );

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $this->depositar($cuota),
            transaction: $this->movimientoDeposito($cuota),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        $cuota->refresh()->loadMissing('haber.beneficiary');
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

    private function movimientoDeposito(BeneficiaryInstallment $cuota): BankTransaction
    {
        $traslado = CashToBankTransfer::query()->latest('id')->firstOrFail();
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-05-28',
            'amount' => $traslado->amount,
            'direction' => TransactionDirection::Credit,
            'operation_id' => '995979830',
            'description' => 'Deposito en efectivo',
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
                'reference' => self::OPERACION,
            ]);
    }

    private function vincular(BeneficiaryInstallment $cuota, BankTransaction $movimiento): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.disbursement.link-debit', $cuota), [
                'bankTransactionId' => $movimiento->id,
            ]);
    }

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

    /** @return Builder<Receipt> */
    private function recibosDeEgreso()
    {
        return Receipt::query()->issued()->where('receipt_type', ReceiptType::Expense);
    }
}
