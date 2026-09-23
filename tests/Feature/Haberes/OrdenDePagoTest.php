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
use App\Modules\Haberes\Actions\CompletePaymentOrderData;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\EditPaymentOrderDetails;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\UnlockInstallmentEdit;
use App\Modules\Haberes\Actions\UpdateInstallment;
use App\Modules\Haberes\Actions\VoidPaymentOrder;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Http\Controllers\PaymentOrderController;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Haberes\Pdf\PasePdf;
use App\Modules\Haberes\Pdf\PaymentOrderPdf;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Haberes\Support\PaymentOrderReadiness;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use ReflectionParameter;
use Tests\TestCase;

/**
 * La Orden de Pago y su Pase.
 *
 * Dos preguntas gobiernan esta etapa. La primera es **si corresponde**: la
 * Orden existe solo cuando el dinero sale por transferencia del organismo,
 * y el §2.4.6 separa cómo entró de cómo sale. La segunda es **con qué**:
 * cuota completa, recibo de ingreso emitido, CBU verificado y el dinero ya
 * en la cuenta.
 *
 * Los dos documentos nacen juntos y caen juntos, que es como el área los
 * usa: al organismo se remiten la Orden y el Pase, y el expediente se
 * queda.
 */
class OrdenDePagoTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA_ORGANISMO = '23456789';

    private const CBU_VALIDO = '2850000300000000000017';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /*
    |---------------------------------------------------------------------
    | Si corresponde
    |---------------------------------------------------------------------
    */

    /**
     * El efectivo que sigue en la caja se paga por mostrador.
     *
     * No es una traba: es la otra mitad del circuito. El beneficiario se
     * presenta, cobra y firma el recibo de egreso, y nadie le pide nada al
     * organismo superior.
     */
    public function test_el_efectivo_en_la_caja_no_lleva_orden(): void
    {
        $estado = $this->estadoDe($this->cuotaCobrada());

        $this->assertSame(PaymentChannel::Counter, $estado->channel);
        $this->assertFalse($estado->applies);
        $this->assertFalse($estado->canIssue());
    }

    /**
     * §2.4.7: depositado el efectivo, el pago solo puede hacerse por
     * transferencia. La misma cuota pasa a llevar Orden sin que nadie
     * cambie nada en ella.
     */
    public function test_el_efectivo_depositado_y_acreditado_si_lleva_orden(): void
    {
        $cuota = $this->cuotaConEfectivoAcreditado();
        $estado = $this->estadoDe($cuota);

        $this->assertSame(PaymentChannel::Transfer, $estado->channel);
        $this->assertTrue($estado->applies);
        $this->assertNull($estado->blockedReason);
    }

    /**
     * El área fue explícita: la Orden se habilita **cuando esté
     * acreditado**. En tránsito el dinero salió de la caja y la cuenta del
     * organismo todavía no lo tiene; pedirle que transfiera eso sería
     * pedirle un pago sin respaldo.
     */
    public function test_el_deposito_sin_acreditar_todavia_no_habilita(): void
    {
        $cuota = $this->cuotaCobrada();
        $this->depositar($cuota);

        $estado = $this->estadoDe($cuota->refresh());

        $this->assertSame(PaymentChannel::Undetermined, $estado->channel);
        $this->assertTrue($estado->applies);
        $this->assertStringContainsString('no está acreditado', (string) $estado->blockedReason);
        $this->assertFalse($estado->canIssue());
    }

    /**
     * La Orden referencia el recibo por número: no puede salir antes que
     * él, ni sobrevivir a que se lo anule.
     *
     * El recibo no se borra —lo impide un trigger, y es correcto: un
     * comprobante que existió no desaparece— así que el escenario se arma
     * anulándolo, que es lo que pasa en la realidad.
     */
    public function test_sin_recibo_de_ingreso_vigente_no_hay_orden(): void
    {
        $cuota = $this->cuotaConEfectivoAcreditado();

        DB::table('receipts')
            ->where('beneficiary_installment_id', $cuota->id)
            ->update([
                'status' => 'voided',
                'voided_by' => $this->operador()->id,
                'voided_at' => now(),
                'void_reason' => 'El importe se cargó con un cero de más.',
            ]);

        $estado = $this->estadoDe($cuota->refresh());

        $this->assertStringContainsString('recibo de ingreso', (string) $estado->blockedReason);
    }

    /**
     * §2.2.7: una condición administrativa bloquea el egreso, nunca el
     * ingreso. El empleador deposita igual; lo que se retiene es la
     * entrega al trabajador.
     */
    public function test_una_etiqueta_que_bloquea_el_pago_impide_la_orden(): void
    {
        $cuota = $this->cuotaLista();

        $etiqueta = HaberManagementLabel::query()->firstOrCreate(
            ['code' => 'P.P. HOMOL.'],
            ['description' => 'Pendiente de homologación', 'blocks_payment' => true, 'sort_order' => 99],
        );
        $etiqueta->forceFill(['blocks_payment' => true])->save();

        $cuota->forceFill(['management_label_id' => $etiqueta->id])->save();

        $estado = $this->estadoDe($cuota->refresh());

        $this->assertStringContainsString('impide emitir la Orden', (string) $estado->blockedReason);
    }

    /*
    |---------------------------------------------------------------------
    | Los datos que el papel imprime
    |---------------------------------------------------------------------
    */

    /**
     * §2.2.9: el sistema opera únicamente con CBU y la cuenta tiene que
     * estar verificada. Sin eso el organismo no tiene a dónde transferir.
     */
    public function test_sin_cbu_verificado_no_se_puede_emitir(): void
    {
        $cuota = $this->cuotaConEfectivoAcreditado();
        $this->completarDatosDelBeneficiario($cuota);

        $estado = $this->estadoDe($cuota->refresh());

        $codigos = array_map(fn ($campo): string => $campo->code, $estado->requiredMissing());

        $this->assertContains('account.verified', $codigos);
        $this->assertFalse($estado->canIssue());

        $this->expectException(ValidationException::class);
        app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());
    }

    /** El domicilio del beneficiario va impreso: sin él, el papel sale incompleto. */
    public function test_el_domicilio_del_beneficiario_es_obligatorio(): void
    {
        $cuota = $this->cuotaConEfectivoAcreditado();
        $this->cuentaVerificada($cuota);

        $estado = $this->estadoDe($cuota->refresh());
        $codigos = array_map(fn ($campo): string => $campo->code, $estado->requiredMissing());

        $this->assertContains('beneficiary.address', $codigos);
    }

    /**
     * El teléfono de la empresa no frena nada: la Orden 3582 sale con ese
     * renglón en blanco y el área la firma igual.
     */
    public function test_el_telefono_del_empleador_no_frena_la_emision(): void
    {
        $cuota = $this->cuotaLista();
        $estado = $this->estadoDe($cuota);

        $codigos = array_map(fn ($campo): string => $campo->code, $estado->missing);
        $obligatorios = array_map(fn ($campo): string => $campo->code, $estado->requiredMissing());

        $this->assertContains('employer.phone', $codigos);
        $this->assertNotContains('employer.phone', $obligatorios);
        $this->assertTrue($estado->canIssue());
    }

    /** Vaciar un dato opcional lo elimina en vez de conservarlo en silencio. */
    public function test_los_datos_maestros_se_pueden_limpiar_explicitamente(): void
    {
        $cuota = $this->cuotaLista();
        $cuota->loadMissing('haber.expediente.employer');

        app(CompletePaymentOrderData::class)->handle($cuota, [
            'beneficiaryPhone' => '   ',
            'employerAddress' => null,
        ], $this->operador()->id);

        $this->assertNull($cuota->haber->beneficiary->refresh()->phone);
        $this->assertNull($cuota->haber->expediente->employer->refresh()->address);
        $this->assertSame('38.357.040', $cuota->haber->beneficiary->document);
    }

    /*
    |---------------------------------------------------------------------
    | La emisión
    |---------------------------------------------------------------------
    */

    /** §9.7: la Orden y el Pase se generan juntos para una cuota financiada. */
    public function test_la_orden_y_el_pase_nacen_juntos(): void
    {
        $cuota = $this->cuotaLista();

        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $this->assertSame(PaymentOrderStatus::Draft, $orden->status);
        $this->assertSame($cuota->id, $orden->beneficiary_installment_id);
        $this->assertStringStartsWith('0030/', $orden->formatted_number);

        $pase = $orden->pase()->first();

        $this->assertNotNull($pase);
        $this->assertSame(PaseStatus::Generated, $pase->status);
        $this->assertSame('SAF GOBIERNO', $pase->destination);
    }

    /** Lo que el papel dice queda congelado en la Orden, no leído del maestro. */
    public function test_la_orden_congela_lo_que_imprime(): void
    {
        $cuota = $this->cuotaLista();
        $beneficiario = $cuota->haber->beneficiary;

        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $this->assertSame($beneficiario->name, $orden->beneficiary_name_snapshot);
        $this->assertSame('B° San Jorge Mza 54', $orden->beneficiary_address_snapshot);
        $this->assertSame(self::CBU_VALIDO, $orden->beneficiary_cbu_snapshot);
        $this->assertSame('19', $orden->cbu_folio_snapshot);
        $this->assertNotNull($orden->organism_bank_account_id);
        $this->assertNotNull($orden->custody_start_date_snapshot);

        // Corregir el maestro después no puede reescribir el papel emitido.
        $beneficiario->forceFill(['address' => 'Otro domicilio'])->save();

        $this->assertSame('B° San Jorge Mza 54', $orden->refresh()->beneficiary_address_snapshot);
    }

    /**
     * La tabla de depósitos —«esta plata vino de acá»— se copia al emitir.
     * Es la evidencia que el auditor consulta, y no se recalcula.
     */
    public function test_la_tabla_de_depositos_queda_congelada(): void
    {
        $cuota = $this->cuotaLista();

        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());
        $fuentes = $orden->fundingSources()->get();

        $this->assertCount(1, $fuentes);
        $this->assertSame('995979830', $fuentes->first()->operation_number_snapshot);
        $this->assertSame(self::CUENTA_ORGANISMO, $fuentes->first()->bank_account_snapshot);
    }

    /*
    |---------------------------------------------------------------------
    | La pantalla que arma los documentos
    |---------------------------------------------------------------------
    */

    /** Con la cuota lista, la pantalla abre con sus dos bloques de datos. */
    public function test_la_pantalla_de_la_orden_se_abre(): void
    {
        $cuota = $this->cuotaLista();

        $this->actingAs($this->operador('contador'))
            ->get(route('haberes.installments.order.create', $cuota))
            ->assertOk()
            ->assertInertia(
                fn ($pagina) => $pagina
                    ->component('haberes/ordenes/create')
                    ->where('estado.canIssue', true)
                    ->where('cuota.id', $cuota->id)
                    ->has('contexto.accounts', 1),
            );
    }

    /**
     * Una cuota que se paga por mostrador no tiene esta pantalla.
     *
     * Se corta antes de dibujar un formulario que el Action va a rechazar:
     * es la misma guarda que usa el traslado del efectivo.
     */
    public function test_la_pantalla_no_existe_para_una_cuota_de_mostrador(): void
    {
        $this->actingAs($this->operador('contador'))
            ->get(route('haberes.installments.order.create', $this->cuotaCobrada()))
            ->assertNotFound();
    }

    /** Ni para una que ya tiene su Orden: no puede emitir otra. */
    public function test_la_pantalla_no_existe_si_ya_hay_orden(): void
    {
        $cuota = $this->cuotaLista();
        app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $this->actingAs($this->operador('contador'))
            ->get(route('haberes.installments.order.create', $cuota->refresh()))
            ->assertNotFound();
    }

    /**
     * Emitida la Orden, se vuelve al haber.
     *
     * La pantalla que la armaba ya cumplió; lo que sigue —verla,
     * imprimirla, corregirla— vive en la tarjeta de la cuota.
     */
    public function test_al_emitir_se_vuelve_al_haber(): void
    {
        $cuota = $this->cuotaLista();

        $this->actingAs($this->operador('contador'))
            ->post(route('haberes.installments.order', $cuota), [
                'incomeReceiptNumberSource' => ReceiptNumberSource::System->value,
                'paseDestination' => IssuePaymentOrderData::DEFAULT_DESTINATION,
                'cbuFolio' => '19',
            ])
            ->assertRedirect(route('haberes.haber.show', [$cuota->haber->expediente,
                $cuota->haber,
            ]));
    }

    /*
    |---------------------------------------------------------------------
    | Corregir, que es lo que el área hace
    |---------------------------------------------------------------------
    */

    /**
     * **Uno de los dos caminos, y lo elige quien opera.** Cuando falta o
     * está mal un dato accesorio se agrega una foja explicando el problema
     * y los mismos documentos siguen su camino; cuando el organismo la
     * devuelve, se anula y se rehace. Por eso lo accesorio —la foja del
     * CBU, el destinatario de la nota— se corrige sin tocar el resto.
     */
    public function test_lo_accesorio_del_documento_se_corrige(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());
        $importeOriginal = $orden->amount;

        app(EditPaymentOrderDetails::class)->handle($orden, [
            'cbuFolio' => '24',
            'paseDestination' => 'SAF GOBIERNO — Mesa de Entradas',
            'paseNotes' => 'Se adjunta constancia de la cuenta rectificada.',
        ], $this->operador()->id);

        $orden->refresh();

        // El OBS acompaña a la foja: se redacta solo desde ella (D-003).
        $this->assertSame(
            'Se observa en expte. CBU del trabajador a fs. n.º 24',
            $orden->notes,
        );
        $this->assertSame('24', $orden->cbu_folio_snapshot);
        $this->assertSame('SAF GOBIERNO — Mesa de Entradas', $orden->pase()->first()->destination);

        // Y nada de lo que compromete el pago se movió.
        $this->assertSame($importeOriginal, $orden->amount);
        $this->assertSame(PaymentOrderStatus::Draft, $orden->status);
    }

    /**
     * El pie «RECIBO EGRESO N°» sale vacío mientras el recibo no exista.
     *
     * Y tiene que salir vacío: al imprimir la Orden para remitirla, el
     * egreso no se validó todavía. El renglón se completa solo cuando
     * `IssueExpenseReceipt` ata el comprobante a su Orden.
     */
    public function test_el_pie_del_egreso_sale_vacio_hasta_que_el_recibo_existe(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());

        $this->assertNull($orden->expense_receipt_id);

        $papel = app(PaymentOrderPdf::class)->preview($orden)->render();

        $this->assertStringContainsString('RECIBO EGRESO N', $papel);
    }

    /**
     * El `OBS` no se puede escribir aparte de la foja.
     *
     * Es la garantía de que los dos renglones no puedan divergir: aunque
     * alguien mande `notes` a mano, el Action lo ignora y redacta desde la
     * foja. Sin esto, la unificación sería una convención del formulario y
     * no una regla.
     */
    public function test_el_obs_no_se_puede_escribir_por_separado(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());

        app(EditPaymentOrderDetails::class)->handle($orden, [
            'cbuFolio' => '31',
            'notes' => 'Un texto que nadie pidió.',
        ], $this->operador()->id);

        $orden->refresh();

        $this->assertSame(
            'Se observa en expte. CBU del trabajador a fs. n.º 31',
            $orden->notes,
        );
        $this->assertStringNotContainsString('nadie pidió', (string) $orden->notes);
    }

    /**
     * Corregir toca **solo** lo accesorio.
     *
     * Se compara la fila entera antes y después: lo único que puede haber
     * cambiado son las cuatro columnas administrativas y la marca de
     * tiempo. Cualquier otra diferencia sería una filtración por esta
     * puerta, y este test la encuentra sin tener que enumerar a mano qué
     * proteger.
     */
    public function test_corregir_no_toca_ninguna_otra_columna(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());
        $antes = (array) DB::table('payment_orders')->where('id', $orden->id)->first();

        app(EditPaymentOrderDetails::class)->handle($orden, [
            'cbuFolio' => '31',
        ], $this->operador()->id);

        $despues = (array) DB::table('payment_orders')->where('id', $orden->id)->first();

        // La marca de tiempo se mueve o no según el segundo; no dice nada.
        $cambiadas = array_values(array_diff(
            array_keys(array_diff_assoc($despues, $antes)),
            ['updated_at'],
        ));
        sort($cambiadas);

        $this->assertSame(['cbu_folio_snapshot', 'notes'], $cambiadas);
    }

    /** Una Orden que ya salió de circulación queda como quedó. */
    public function test_una_orden_anulada_no_se_corrige(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());

        app(VoidPaymentOrder::class)->handle($orden, 'El CBU es de otra cuenta.', $this->operador()->id);

        $this->expectException(ValidationException::class);
        app(EditPaymentOrderDetails::class)->handle($orden->refresh(), [
            'cbuFolio' => '99',
        ], $this->operador()->id);
    }

    /**
     * La foja volvió a ser obligatoria al emitir.
     *
     * Se había relajado porque frenaba la emisión cuando el dato no estaba
     * a mano. Lo que cambió es cuánto pesa: desde que redacta también el
     * renglón OBS (D-003), emitir sin ella deja **dos** renglones en
     * blanco, y el área pidió volver a exigirla.
     *
     * Se prueba por la ruta y no por el Action: quien la exige es el
     * `FormRequest`, y el Action tiene que seguir aceptando el nulo porque
     * las Órdenes emitidas mientras fue opcional lo tienen.
     */
    public function test_la_emision_exige_la_foja_del_cbu(): void
    {
        $cuota = $this->cuotaLista();
        $operador = $this->operador('contador');

        $respuesta = $this->actingAs($operador)->post(
            route('haberes.installments.order', $cuota),
            [
                'incomeReceiptNumberSource' => ReceiptNumberSource::System->value,
                'paseDestination' => IssuePaymentOrderData::DEFAULT_DESTINATION,
                'cbuFolio' => '',
            ],
        );

        $respuesta->assertSessionHasErrors('cbuFolio');
        $this->assertNull($cuota->refresh()->paymentOrders()->first());
    }

    /**
     * Corregir tampoco puede vaciarla.
     *
     * Si se exige al emitir pero se deja borrar al corregir, la Orden sin
     * foja se consigue igual, en dos pasos.
     */
    public function test_corregir_no_puede_vaciar_la_foja(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());
        $operador = $this->operador('contador');

        $respuesta = $this->actingAs($operador)->patch(
            route('ordenes.update', $orden),
            ['cbuFolio' => '   '],
        );

        $respuesta->assertSessionHasErrors('cbuFolio');
        $this->assertSame('19', $orden->refresh()->cbu_folio_snapshot);
    }

    /**
     * El Action sigue tolerando el nulo, y tiene que seguir haciéndolo.
     *
     * Es el estado de las Órdenes emitidas mientras la foja fue opcional:
     * salen sin cita en la nota y con el OBS en blanco, y se reimprimen
     * así. Por eso la regla vive en el formulario y no en un `NOT NULL`,
     * que las volvería ilegales retroactivamente.
     */
    public function test_una_orden_sin_foja_se_sigue_imprimiendo(): void
    {
        $cuota = $this->cuotaLista();

        $orden = app(IssuePaymentOrder::class)->handle(
            $cuota,
            new IssuePaymentOrderData(
                beneficiaryBankAccountId: null,
                cbuFolio: null,
                incomeReceiptNumberSource: ReceiptNumberSource::System,
                paseDestination: IssuePaymentOrderData::DEFAULT_DESTINATION,
                treasurerId: null,
            ),
        );

        $this->assertNull($orden->cbu_folio_snapshot);
        $this->assertNull($orden->notes);

        $nota = app(PasePdf::class)->preview($orden->pase()->firstOrFail())->render();

        $this->assertStringNotContainsString('informada en fs.', $nota);
        $this->assertStringContainsString(self::CBU_VALIDO, $nota);

        // Y la Orden se imprime igual, con el renglón OBS vacío.
        $papel = app(PaymentOrderPdf::class)->preview($orden)->render();

        $this->assertStringNotContainsString('Se observa en expte.', $papel);
    }

    /** El operador elige qué número de recibo imprime el renglón del papel. */
    public function test_se_elige_que_numero_de_recibo_se_imprime(): void
    {
        $cuota = $this->cuotaLista(talonario: '72273');

        $orden = app(IssuePaymentOrder::class)->handle(
            $cuota,
            $this->eleccion(numeroDeRecibo: ReceiptNumberSource::Talonario),
        );

        $this->assertSame('72273', $orden->income_receipt_number_snapshot);
    }

    /** §2.2.4: una sola Orden activa por cuota, impuesto por la base. */
    public function test_la_base_rechaza_dos_ordenes_activas_por_cuota(): void
    {
        $cuota = $this->cuotaLista();
        $primera = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $fila = (array) DB::table('payment_orders')->where('id', $primera->id)->first();
        unset($fila['id']);
        $fila['number'] = 999999;
        $fila['formatted_number'] = '0030/00999999';

        $this->expectException(QueryException::class);
        DB::table('payment_orders')->insert($fila);
    }

    /**
     * Ninguna Orden existe sin las dos puntas de la transferencia.
     *
     * El invariante era condicional mientras la Orden tuvo tipo: se le
     * exigía destino sólo a «VALORES EN CUSTODIA», y «CHEQUES PROPIOS»
     * quedaba afuera porque nadie sabía qué datos llevaba. Al desaparecer
     * las casillas del formulario quedó un solo circuito, y con él la
     * regla se volvió incondicional.
     */
    public function test_la_base_rechaza_una_orden_sin_cuenta_del_organismo(): void
    {
        $cuota = $this->cuotaLista();
        $emitida = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $fila = (array) DB::table('payment_orders')->where('id', $emitida->id)->first();
        unset($fila['id']);
        $fila['number'] = 999998;
        $fila['formatted_number'] = '0030/00999998';
        $fila['organism_bank_account_id'] = null;

        $this->expectException(QueryException::class);
        DB::table('payment_orders')->insert($fila);
    }

    /**
     * Y el Action lo dice en castellano antes de que la base tenga que hablar.
     *
     * El mensaje sale de la habilitación y no de la elección de cuenta:
     * `assertIssuable()` corre primero y nombra el dato que falta, así que
     * el operador lee «cbu verificado del beneficiario» y no el texto de
     * un `CHECK` de PostgreSQL.
     */
    public function test_el_action_avisa_cuando_el_beneficiario_no_tiene_cbu_verificado(): void
    {
        $cuota = $this->cuotaLista();

        PersonBankAccount::query()
            ->where('person_id', $cuota->haber->beneficiary_id)
            ->update(['verification_status' => 'unverified']);

        try {
            app(IssuePaymentOrder::class)->handle($cuota->refresh(), $this->eleccion());
            $this->fail('Se emitió una Orden sin cuenta verificada del beneficiario.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cbu verificado del beneficiario', $e->getMessage());
        }
    }

    /** El Action da el mensaje legible antes de que la base tenga que hablar. */
    public function test_el_action_avisa_antes_de_emitir_la_segunda(): void
    {
        $cuota = $this->cuotaLista();
        $primera = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        try {
            app(IssuePaymentOrder::class)->handle($cuota->refresh(), $this->eleccion());
            $this->fail('Se emitió una segunda Orden activa para la misma cuota.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($primera->formatted_number, $e->getMessage());
        }
    }

    /** Los fondos de una Orden vigente se liberan recién después de anularla. */
    public function test_no_se_liberan_fondos_que_respaldan_una_orden_vigente(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());
        $asignacion = FundingAllocation::query()
            ->where('beneficiary_installment_id', $cuota->id)
            ->live()
            ->firstOrFail();

        try {
            app(UnallocateFunds::class)->handle(
                allocation: $asignacion,
                amount: '1.00',
                notes: 'Intento de liberar fondos con documento vigente.',
                idempotencyKey: 'liberar-con-orden-'.Str::random(8),
                actorId: $this->operador()->id,
            );
            $this->fail('Se liberaron fondos que respaldaban una Orden vigente.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($orden->formatted_number, $e->getMessage());
            $this->assertStringContainsString('anular primero', $e->getMessage());
        }
    }

    /**
     * §2.4: un excedente de redondeo en efectivo nunca respalda una Orden.
     * La regla mira otra tabla, así que vive en un trigger.
     */
    public function test_un_excedente_de_redondeo_no_respalda_una_orden(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $this->expectException(QueryException::class);
        DB::table('payment_order_funding_sources')->insert([
            'payment_order_id' => $orden->id,
            'funding_allocation_id' => $this->excedenteDeRedondeo($cuota),
            'amount' => '1.00',
            'created_at' => now(),
        ]);
    }

    /** Los datos impresos no se editan: lo impide un trigger, no la buena fe. */
    public function test_los_datos_impresos_de_la_orden_no_se_editan(): void
    {
        $orden = app(IssuePaymentOrder::class)->handle($this->cuotaLista(), $this->eleccion());

        $this->expectException(QueryException::class);
        DB::table('payment_orders')->where('id', $orden->id)->update(['amount' => '1.00']);
    }

    /*
    |---------------------------------------------------------------------
    | La anulación
    |---------------------------------------------------------------------
    */

    /** §9.7: si el error está en la Orden, se anula junto con su Pase. */
    public function test_anular_la_orden_anula_su_pase(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        app(VoidPaymentOrder::class)->handle(
            $orden,
            'El CBU impreso es el de otra cuenta.',
            $this->operador()->id,
        );

        $this->assertSame(PaymentOrderStatus::Voided, $orden->refresh()->status);
        $this->assertSame(PaseStatus::Voided, $orden->pase()->first()->status);
        $this->assertSame('El CBU impreso es el de otra cuenta.', $orden->rejection_or_void_reason);
    }

    /**
     * El número no se recicla: la anulada se queda con el suyo y la nueva
     * toma el siguiente libre, encadenada por `replaces_order_id`.
     */
    public function test_la_orden_que_reemplaza_toma_el_siguiente_numero(): void
    {
        $cuota = $this->cuotaLista();
        $primera = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        app(VoidPaymentOrder::class)->handle(
            $primera,
            'Se imprimió con el domicilio viejo.',
            $this->operador()->id,
        );

        $segunda = app(IssuePaymentOrder::class)->handle($cuota->refresh(), $this->eleccion());

        $this->assertSame($primera->number + 1, $segunda->number);
        $this->assertSame($primera->id, $segunda->replaces_order_id);
    }

    /*
    |---------------------------------------------------------------------
    | El papel
    |---------------------------------------------------------------------
    */

    /**
     * El formulario impreso dice lo que la Orden congeló.
     *
     * Se mira el HTML y no el PDF: es la misma plantilla —de ahí que la
     * vista previa y la impresión no puedan discrepar— y el texto se puede
     * leer. Lo que se verifica es que cada dato caiga en el papel, que es
     * exactamente lo que una plantilla rota deja de hacer en silencio.
     */
    public function test_la_orden_impresa_dice_lo_que_congelo(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $papel = app(PaymentOrderPdf::class)->preview($orden->refresh())->render();
        $documento = app(PaymentOrderPdf::class)->render($orden);
        $documento->output();

        /*
         * El número va completo, con su serie. Salió pelado un tiempo,
         * copiando el formulario preimpreso del área —que no dice de qué
         * serie viene porque tiene un solo talonario—. Acá hay tres series
         * conviviendo y el pelado no identifica nada: la 3 de Órdenes y la
         * 3 de recibos de ingreso son papeles distintos.
         */
        $this->assertStringContainsString('ORDEN DE PAGO', $papel);
        $this->assertSame(1, $documento->getDomPDF()->getCanvas()->get_page_count());
        $this->assertStringContainsString($orden->formatted_number, $papel);

        /*
         * **Sin los dos títulos.** El papel encabezaba con «VALORES EN
         * CUSTODIA» y «CHEQUES PROPIOS», cada uno con su casilla, y el
         * área confirmó que esos campos ya no van. Se afirma la ausencia
         * y no se borra el renglón: es lo que impide que vuelvan sin que
         * nadie lo note.
         */
        $this->assertStringNotContainsString('VALORES EN CUSTODIA', $papel);
        // «PROPIOS» y no «CHEQUES»: el renglón «CHEQUE N°» del
        // beneficiario sigue en el papel y es otra cosa.
        $this->assertStringNotContainsString('PROPIOS', $papel);

        $this->assertStringContainsString('B° San Jorge Mza 54', $papel);
        $this->assertStringContainsString('387-6004216', $papel);
        // La cuenta del organismo lleva la cruz.
        $this->assertStringContainsString(self::CUENTA_ORGANISMO, $papel);
        $this->assertStringContainsString('XXX', $papel);
        // El renglón del cuadro de depósitos.
        $this->assertStringContainsString('995979830', $papel);
        $this->assertStringContainsString('Se observa en expte.', $papel);
        // El pie que se completa a mano sale vacío.
        $this->assertStringContainsString('RECIBO EGRESO N°', $papel);

        /*
         * **Sin sello.** El formulario del área no lo tiene: a diferencia
         * del recibo de ingreso, que sí le reserva el lugar. Se agregó por
         * analogía y no correspondía.
         */
        $this->assertStringNotContainsString('SELLO', $papel);
    }

    /**
     * Los dos borradores se miran antes de emitir, con lo elegido hasta ese
     * momento.
     *
     * La nota es la mitad que el operador no ve de otro modo: la Orden es
     * un formulario que se reconoce de un vistazo, y el Pase es texto
     * corrido que hay que leer. Que salga sin revisar es cómo se manda al
     * organismo una nota con el destinatario o la foja equivocados.
     */
    public function test_los_borradores_se_arman_con_lo_elegido(): void
    {
        $cuota = $this->cuotaLista();
        $eleccion = $this->eleccion();

        $orden = app(IssuePaymentOrder::class)->preview($cuota, $eleccion);
        $pase = app(IssuePaymentOrder::class)->previewPase($cuota, $eleccion);

        /*
         * El número que le tocaría, con su serie, que es como lo imprime
         * el formulario. Anticipo y no promesa: entre mirar y confirmar
         * puede emitirse otra Orden, y el correlativo real se toma bajo
         * lock recién en ese momento.
         */
        $this->assertGreaterThan(0, $orden->number);
        $this->assertStringStartsWith('0030/', (string) $orden->formatted_number);
        $this->assertNull($orden->id, 'El borrador no se guarda.');
        $this->assertNull($pase->id, 'El borrador de la nota tampoco.');

        $nota = app(PasePdf::class)->preview($pase)->render();

        $this->assertStringContainsString('A SAF GOBIERNO', $nota);
        $this->assertStringContainsString('CBU informada en fs. 19', $nota);
        $this->assertStringContainsString($cuota->haber->beneficiary->name, $nota);
    }

    /**
     * Los tres visores se pueden mostrar dentro del diálogo.
     *
     * **Regresión.** El sistema manda `X-Frame-Options: DENY` y
     * `frame-ancestors 'none'` en todas partes, salvo en las rutas que la
     * configuración declara enmarcables. Las tres de esta etapa no estaban
     * en esa lista, y el efecto no era un error del servidor —el HTML se
     * generaba perfecto— sino un «no se puede abrir esta página» adentro
     * del modal.
     *
     * Se prueban las tres juntas porque las tres se miran igual: en un
     * iframe, sin salir de la pantalla.
     */
    public function test_los_visores_se_pueden_mostrar_en_un_iframe(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());
        $operador = $this->operador('contador');

        $urls = [
            'borrador de la Orden' => route('haberes.installments.order.preview', $cuota),
            'borrador del Pase' => route('haberes.installments.pase.preview', $cuota),
            'Orden emitida' => route('ordenes.view', $orden),
            'nota de Pase' => route('pases.view', $orden->pase()->firstOrFail()),
        ];

        foreach ($urls as $que => $url) {
            $respuesta = $this->actingAs($operador)->get($url);

            $respuesta->assertOk();
            $respuesta->assertHeader('X-Frame-Options', 'SAMEORIGIN');

            $this->assertStringContainsString(
                "frame-ancestors 'self'",
                (string) $respuesta->headers->get('Content-Security-Policy'),
                "El {$que} no se puede enmarcar: el diálogo lo va a mostrar en blanco.",
            );
        }
    }

    /**
     * El «DEPÓSITO U OPERACIÓN N°» sale del extracto cuando nadie lo tipeó.
     *
     * **La celda salía vacía teniendo el dato a un join de distancia.** El
     * renglón de un ingreso en efectivo leía sólo el número que la
     * contadora copia a mano de la boleta, que es opcional. Pero confirmar
     * el depósito contra el extracto ata el traslado a un movimiento
     * concreto —por la imputación de su evento de acreditación—, y ese
     * movimiento trae el `operation_id` del archivo del banco.
     *
     * La rama de las transferencias ya imprimía ese mismo campo; la del
     * efectivo se había quedado atrás.
     */
    public function test_el_numero_de_operacion_sale_del_extracto_si_no_se_tipeo(): void
    {
        $cuota = $this->cuotaLista();

        // El caso normal: lo que se escribió a mano manda.
        $this->assertSame('995979830', $this->estadoDe($cuota)->rows[0]->operationNumber);

        $traslado = app(PaymentOrderSources::class)->transferOf($cuota);
        $traslado?->forceFill(['deposit_operation_number' => null])->save();

        $renglon = $this->estadoDe($cuota->refresh())->rows[0];

        // Sin él, el del extracto tapa el agujero en vez de dejar el blanco.
        $this->assertSame('83690105', $renglon->operationNumber);

        // Y el renglón queda atado al movimiento que lo respalda, que antes
        // se perdía: el snapshot de la Orden lo va a congelar.
        $this->assertNotNull($renglon->bankTransactionId);

        // Lo que importa: el papel lo imprime.
        $orden = app(IssuePaymentOrder::class)->preview($cuota, $this->eleccion());
        $papel = app(PaymentOrderPdf::class)->preview(
            $orden,
            renglones: $this->estadoDe($cuota)->rows,
        )->render();

        $this->assertStringContainsString('83690105', $papel);
    }

    /**
     * «Imprimir» del borrador abre un PDF, no la misma hoja otra vez.
     *
     * El visor ofrece dos enlaces —ver en grande e imprimir— y el borrador
     * tenía una sola ruta, que devuelve HTML para meter en el iframe. Los
     * dos enlaces apuntaban ahí, así que «Imprimir» abría de nuevo la
     * hoja en HTML y nunca llegaba al lector de PDF del navegador, que es
     * desde donde se manda al papel. Los documentos ya emitidos sí tenían
     * las dos rutas; el borrador quedó a medias.
     *
     * Se afirma el `content-type` de las cuatro y no sólo de las nuevas:
     * lo que hay que sostener es que **vista e impresión no devuelven lo
     * mismo**, que es la confusión que dejó el enlace roto.
     */
    public function test_el_borrador_tambien_se_puede_imprimir_en_pdf(): void
    {
        $cuota = $this->cuotaLista();
        $operador = $this->operador('contador');

        $enHtml = [
            'borrador de la Orden' => route('haberes.installments.order.preview', $cuota),
            'borrador del Pase' => route('haberes.installments.pase.preview', $cuota),
        ];

        foreach ($enHtml as $que => $url) {
            $respuesta = $this->actingAs($operador)->get($url);

            $respuesta->assertOk();
            $this->assertStringContainsString(
                'text/html',
                (string) $respuesta->headers->get('Content-Type'),
                "El {$que} tiene que venir en HTML: es lo que va adentro del iframe.",
            );
        }

        $enPdf = [
            'borrador de la Orden' => route('haberes.installments.order.preview.print', $cuota),
            'borrador del Pase' => route('haberes.installments.pase.preview.print', $cuota),
        ];

        foreach ($enPdf as $que => $url) {
            $respuesta = $this->actingAs($operador)->get($url);

            $respuesta->assertOk();
            $this->assertStringContainsString(
                'application/pdf',
                (string) $respuesta->headers->get('Content-Type'),
                "El {$que} tiene que venir en PDF: es lo que se manda al papel.",
            );

            // Y se abre en el lector, no se baja a Descargas.
            $disposicion = (string) $respuesta->headers->get('Content-Disposition');
            $this->assertStringContainsString('inline', $disposicion);
            // El nombre dice que es un borrador: la hoja sale igual que la
            // emitida y su número es el que le tocaría, no el definitivo.
            $this->assertStringContainsString('-borrador.pdf', $disposicion);
        }
    }

    /**
     * La previa del diálogo de corregir muestra lo que todavía no se guardó.
     *
     * Es lo que permite ver el efecto de una foja antes de confirmarla, en
     * vez de corregir a ciegas un renglón que va impreso. **La previa
     * redacta el `OBS` igual que el Action**: si compusiera el texto por su
     * cuenta, mostraría un papel distinto del que se va a guardar, que es
     * peor que no mostrar nada.
     */
    public function test_la_vista_acepta_retoques_sin_guardarlos(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());
        $operador = $this->operador('contador');

        $vista = $this->actingAs($operador)->get(route('ordenes.view', $orden).'?'.http_build_query([
            'cbuFolio' => '99',
        ]));

        $vista->assertOk();
        $vista->assertSee('a fs. n.º 99', escape: false);

        // Y en la base no cambió nada.
        $orden->refresh();
        $this->assertSame('19', $orden->cbu_folio_snapshot);
        $this->assertStringContainsString('fs. n.º 19', (string) $orden->notes);
    }

    /**
     * **La impresión no los acepta**, y esa diferencia es la que importa.
     *
     * El papel que se manda a la impresora no puede decir algo que el
     * registro no dice. Si el retoque llegara hasta acá, cualquiera podría
     * imprimir una Orden con una observación que nunca se guardó.
     *
     * Se comprueba sobre la forma del método y no sobre el PDF: dompdf
     * devuelve binario comprimido, y buscar texto ahí no prueba nada. Lo
     * que sí se puede afirmar es que **las rutas de impresión no reciben la
     * petición**, así que no tienen de dónde leer un parámetro. Es la misma
     * clase de garantía que dan los tests de arquitectura del proyecto.
     */
    public function test_la_impresion_no_puede_leer_retoques(): void
    {
        foreach (['print', 'printPase'] as $metodo) {
            $tipos = array_map(
                fn (ReflectionParameter $p): string => (string) $p->getType(),
                (new ReflectionMethod(PaymentOrderController::class, $metodo))->getParameters(),
            );

            $this->assertNotContains(
                Request::class,
                $tipos,
                "«{$metodo}» recibe la petición: podría imprimir un texto que no está guardado.",
            );
        }
    }

    /** La nota redacta la CBU por su foja, como la escribe el área. */
    public function test_la_nota_de_pase_se_redacta_con_la_foja(): void
    {
        $cuota = $this->cuotaLista();
        $orden = app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        $nota = app(PasePdf::class)->preview($orden->pase()->firstOrFail())->render();

        $this->assertStringContainsString('Pase de Contable', $nota);
        $this->assertStringContainsString('A SAF GOBIERNO', $nota);
        $this->assertStringContainsString('CBU informada en fs. 19', $nota);
        $this->assertStringContainsString($orden->beneficiary_name_snapshot, $nota);
        // El importe va en números y en letras, como el papel del área.
        $this->assertStringContainsString('pesos', mb_strtolower($nota));
        // El membrete y la firma quedan reservados hasta que el área los dé.
        $this->assertStringContainsString('MEMBRETE DEL ORGANISMO', $nota);
        $this->assertStringContainsString('Firma y sello', $nota);
    }

    /*
    |---------------------------------------------------------------------
    | El bloqueo de edición
    |---------------------------------------------------------------------
    */

    /**
     * Con el Pase emitido el expediente sale del área: corregir la cuota
     * exige registrar antes el caso y el porqué.
     */
    public function test_con_el_pase_emitido_la_cuota_queda_trabada(): void
    {
        $cuota = $this->cuotaLista();
        app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        try {
            $this->corregir($cuota->refresh(), '1000.00');
            $this->fail('Se editó una cuota cuyo expediente está en circulación.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('en circulación', $e->getMessage());
        }
    }

    /** Registrado el caso, se corrige; y al guardar se vuelve a bloquear. */
    public function test_la_ventana_justificada_habilita_y_se_cierra_sola(): void
    {
        $cuota = $this->cuotaLista();
        $importe = $cuota->importeEsperado();
        app(IssuePaymentOrder::class)->handle($cuota, $this->eleccion());

        app(UnlockInstallmentEdit::class)->handle(
            $cuota->refresh(),
            'El expediente volvió del SAF observado: el concepto no coincide con la resolución a fs. 24.',
        );

        $this->assertNotNull($cuota->refresh()->edit_unlocked_at);

        // Se corrige algo que no toca el dinero ya asignado.
        $this->corregir($cuota->refresh(), $importe, concepto: 'Diferencias salariales — corregido');

        $cuota->refresh();

        $this->assertSame('Diferencias salariales — corregido', $cuota->description);
        $this->assertNull($cuota->edit_unlocked_at, 'La ventana tenía que cerrarse al guardar.');
    }

    /** Sin Orden en circulación no hay nada que destrabar. */
    public function test_no_se_destraba_una_cuota_que_no_esta_trabada(): void
    {
        $this->expectException(ValidationException::class);

        app(UnlockInstallmentEdit::class)->handle(
            $this->cuotaLista(),
            'Un motivo suficientemente largo para pasar la validación.',
        );
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function estadoDe(BeneficiaryInstallment $cuota): PaymentOrderReadiness
    {
        return app(PaymentOrderEligibility::class)->for($cuota);
    }

    /** Lo que el formulario aporta, con los valores del caso real. */
    private function eleccion(
        ?ReceiptNumberSource $numeroDeRecibo = null,
    ): IssuePaymentOrderData {
        return new IssuePaymentOrderData(
            beneficiaryBankAccountId: null,
            cbuFolio: '19',
            incomeReceiptNumberSource: $numeroDeRecibo ?? ReceiptNumberSource::System,
            paseDestination: IssuePaymentOrderData::DEFAULT_DESTINATION,
            treasurerId: null,
        );
    }

    /** Una cuota con todo lo que el papel pide, lista para emitir. */
    private function cuotaLista(?string $talonario = null): BeneficiaryInstallment
    {
        $cuota = $this->cuotaConEfectivoAcreditado($talonario);

        $this->completarDatosDelBeneficiario($cuota);
        $this->cuentaVerificada($cuota);

        return $cuota->refresh();
    }

    /** El circuito completo del efectivo que nadie retiró. */
    private function cuotaConEfectivoAcreditado(?string $talonario = null): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobrada($talonario);
        $traslado = $this->depositar($cuota);

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->credito($traslado->amount),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /**
     * Cobrada por mostrador, con su recibo de ingreso emitido.
     *
     * `$talonario` emite el recibo sobre el papel del talonario, que es lo
     * que le da dos números y hace que la Orden tenga algo que elegir.
     */
    private function cuotaCobrada(?string $talonario = null): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
            talonarioNumber: $talonario,
        );

        return $cuota->refresh();
    }

    private function cuota(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        return $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
    }

    private function completarDatosDelBeneficiario(BeneficiaryInstallment $cuota): void
    {
        $cuota->loadMissing('haber.beneficiary');

        $cuota->haber->beneficiary->forceFill([
            'document' => '38.357.040',
            'address' => 'B° San Jorge Mza 54',
            'phone' => '387-6004216',
        ])->save();
    }

    private function cuentaVerificada(BeneficiaryInstallment $cuota): PersonBankAccount
    {
        $cuota->loadMissing('haber.beneficiary');

        return PersonBankAccount::query()->create([
            'person_id' => $cuota->haber->beneficiary->id,
            'cbu' => self::CBU_VALIDO,
            'bank_name' => 'Banco Macro',
            'verification_status' => 'verified',
            /*
             * Quién y cuándo van juntos o no van: la base exige el par, y
             * una cuenta verificada sin verificador no explica nada.
             */
            'verified_by' => $this->operador()->id,
            'verified_at' => now(),
            'is_active' => true,
        ]);
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

    /**
     * Una asignación de excedente de redondeo, escrita a mano.
     *
     * **No hay Action que la produzca**, y es correcto: el circuito del
     * redondeo en efectivo todavía no existe (§2.4) y el enum lo dice. La
     * fila se arma cruda porque lo que este test prueba es el trigger de
     * la base, no el camino que algún día la va a crear.
     *
     * @return int el id de la asignación
     */
    private function excedenteDeRedondeo(BeneficiaryInstallment $cuota): int
    {
        $asignacion = DB::table('funding_allocations')
            ->where('beneficiary_installment_id', $cuota->id)
            ->first();

        /*
         * Evento propio: `allocation_event_id` es único, porque cada
         * asignación es un hecho monetario distinto.
         */
        $eventoId = DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'event_type' => 'funds_allocated',
            'event_date' => BusinessDate::today()->toDateString(),
            'status' => 'posted',
            'idempotency_key' => 'excedente-'.Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::table('funding_allocations')->insertGetId([
            'allocation_event_id' => $eventoId,
            'fund_receipt_id' => $asignacion->fund_receipt_id,
            'haber_id' => $asignacion->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'allocation_kind' => 'cash_rounding_surplus',
            'amount' => '1.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function corregir(
        BeneficiaryInstallment $cuota,
        string $importe,
        ?string $concepto = null,
    ): void {
        app(UpdateInstallment::class)->handle(
            $cuota->haber()->firstOrFail(),
            $cuota,
            new SaveInstallmentData(
                amount: $importe,
                managementLabelId: $cuota->management_label_id,
                concept: $concepto ?? $cuota->description,
                dueDate: $cuota->due_date?->toDateString(),
                expectedMedium: $cuota->expected_medium,
                notes: $cuota->notes,
                version: $cuota->updated_at,
            ),
        );
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

    private function credito(string $importe): BankTransaction
    {
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-05-28',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'operation_id' => '83690105',
            'description' => 'Deposito en efectivo',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
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
            'period_to' => '2026-05-28',
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
