<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\ImportBankStatement;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CancelExpediente;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverAndIssueExpenseReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\DiscardDepositTicket;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Actions\LinkTransferDebit;
use App\Modules\Haberes\Actions\RegisterDepositTicket;
use App\Modules\Haberes\Actions\RegisterTransferReport;
use App\Modules\Haberes\Actions\ValidateTransferDisbursement;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Actions\AdjustCashDifference;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PayLegacyBeneficiary;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Actions\VerifyPersonBankAccount;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SplitsPersonName;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Dos meses de operación real, día por día.
 *
 * **Reemplaza todo.** Vacía el circuito entero —expedientes, comprobantes,
 * libro, arqueos, cierres, extractos— y lo reconstruye desde la apertura de
 * los libros. Es para desarrollo y por eso puede permitirse borrar.
 *
 * Lo que lo distingue de `EscenariosDemoSeeder` —que arma tres expedientes
 * con sus bordes— es el **eje temporal**: acá interesa que la caja arrastre
 * saldo de un día al siguiente, que los meses cierren, y que el calendario
 * tenga historia para mostrar. Sin dos meses encadenados no hay forma de
 * probar un cierre mensual, una reapertura, ni un saldo inicial que venga
 * del día anterior.
 *
 * ─── La línea de tiempo ──────────────────────────────────────────────────
 *
 * | Cuándo | Qué |
 * |---|---|
 * | 30/06 | Apertura de los libros con las tres columnas de la planilla |
 * | Julio | Mes completo: jornadas con arqueo y cierre diario, cierre mensual |
 * | Agosto | Ídem, más los casos de borde |
 * | Septiembre | En curso: días con movimientos **sin cerrar**, como la realidad |
 *
 * Hoy es el 4 de septiembre de 2026, así que julio y agosto son pasado
 * —cerrables— y septiembre es el mes vivo. Es la forma de que el calendario
 * muestre a la vez meses cerrados, días pendientes y un día de hoy.
 *
 * ─── Cómo se producen los estados ────────────────────────────────────────
 *
 * **Corriendo los Actions reales**, no insertando filas. Una cuota «cobrada»
 * escrita a mano dejaría el libro sin asientos: serviría para mirar la
 * pantalla, no para probar nada. El reloj se viaja con `setTestNow` para que
 * cada hecho quede con la fecha que le toca, y se restituye en el `finally`.
 *
 * El extracto bancario es un archivo `.xlsx` de verdad, con el formato de
 * MacroOnline, importado por el Action de siempre con su parser y su
 * deduplicación. Se importa **al principio de cada mes** para que cada
 * crédito pueda procesarse en su propia fecha: es una simplificación de la
 * cadencia real —el área baja el extracto varias veces por mes— y se elige
 * así porque un período ya cerrado no admite un asiento con fecha adentro.
 *
 * ─── Los bordes que deja montados ────────────────────────────────────────
 *
 * | Caso | Dónde |
 * |---|---|
 * | Cobro en efectivo y cobro con cheque en custodia | 02/07, 04/08 |
 * | Depósito directo del empleador, imputado | 08/07, 16/07, 21/08 |
 * | Crédito que entra **sin dueño**, en la cola de trabajo | 23/07 |
 * | Haber anterior al sistema, contra el saldo de apertura | 10/07 |
 * | Egreso por mostrador | 15/07, 27/08, 03/09 |
 * | Orden de Pago y su Pase, con CBU verificado | 06/08 |
 * | Egreso por transferencia: informe, débito y validación | 11/08 |
 * | Cobro **anulado** y vuelto a cobrar | 13/08 y 25/08 |
 * | Traslado de efectivo al banco, y su acreditación | 18/08 y 20/08 |
 * | Arqueo que cuadra **sin contar todo** el cajón | 28/07 |
 * | Arqueo con **diferencia**, imputada contra `CASH_DIFFERENCE` | 25/08 |
 * | Comprobante de depósito pendiente, y uno **descartado** | 24/08 |
 * | Día cerrado y después **reabierto**, con motivo | 27/08 |
 * | Días con movimientos **sin cerrar** | 02/09 y 03/09 |
 * | Expediente **anulado** sin haber movido un peso | 02/09 |
 *
 * ─── Cómo se corre ───────────────────────────────────────────────────────
 *
 * ```
 * php artisan db:seed --class=OperacionDemoSeeder
 * ```
 *
 * No está en `DatabaseSeeder` a propósito: borra todo el circuito, y eso
 * tiene que ser una decisión explícita y no el efecto de un `migrate:fresh
 * --seed` distraído.
 */
class OperacionDemoSeeder extends Seeder
{
    use SplitsPersonName;

    private const CUENTA = '310000123456789';

    /** Los billetes que el área maneja, de mayor a menor. */
    private const BILLETES = [100_000, 50_000, 20_000, 10_000, 2_000, 1_000, 500, 200, 100, 50];

    /** Un JPEG mínimo, que hace de foto en todos los tickets. */
    private const PIXEL = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw'
        .'8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMj'
        .'IyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBg'
        .'cICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NT'
        .'Y3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8'
        .'jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBA'
        .'cFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVl'
        .'dYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5e'
        .'bn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';

    private string $foto;

    private User $cajero;

    private User $contadora;

    private int $caja;

    private BankAccount $cuenta;

    /** @var array<string, int> */
    private array $personas = [];

    /** @var array<string, BeneficiaryInstallment> */
    private array $cuotas = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'OperacionDemoSeeder reemplaza todos los datos del circuito y solo corre en local o testing.'
            );
        }

        $this->usuarios();

        $caja = DB::table('cash_boxes')->where('code', 'haberes')->value('id');

        if ($caja === null) {
            throw new RuntimeException('No existe la caja de Haberes. Ejecutá CashBoxSeeder antes.');
        }

        $this->caja = (int) $caja;
        $this->foto = $this->escribirFoto();

        try {
            $this->vaciar();

            $this->cuenta = BankAccount::query()->firstOrCreate(
                ['account_number' => self::CUENTA],
                [
                    'label' => 'Cta. Cte. 2693 — Haberes',
                    'bank_name' => 'Banco Macro',
                    'currency' => 'ARS',
                    'is_active' => true,
                ],
            );

            $this->personas();
            $this->abrirLibros();

            $this->julio();
            $this->agosto();
            $this->septiembre();

            $this->informe();
        } finally {
            CarbonImmutable::setTestNow();
            @unlink($this->foto);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Preparación
    |--------------------------------------------------------------------------
    */

    /**
     * Dos personas distintas, y no es decorativo.
     *
     * `ReviewCashCount` rechaza que revise el arqueo quien lo contó: con un
     * solo usuario el circuito de control no se puede reproducir.
     */
    private function usuarios(): void
    {
        $this->cajero = User::query()->orderBy('id')->firstOrFail();

        $this->contadora = User::query()->firstOrCreate(
            ['email' => 'contadora@example.test'],
            [
                'username' => 'demo.contadora',
                'first_name' => 'Nora Beatriz',
                'last_name' => 'Achad',
                'document_number' => '17455980',
                'password' => 'Contrasena.Segura.2026',
            ],
        );

        if (! $this->contadora->hasRole('contador')) {
            $this->contadora->assignRole('contador');
        }
    }

    /**
     * Deja la base sin nada del circuito.
     *
     * Los triggers append-only se apagan y se vuelven a prender en el
     * `finally`: son los que impiden borrar hechos monetarios, y acá se
     * está tirando todo a propósito. Fuera de desarrollo esto no existe.
     */
    private function vaciar(): void
    {
        /*
         * El orden importa: las FK son `RESTRICT` y siguen vigentes aunque
         * los triggers estén apagados. Lo que referencia va antes que lo
         * referenciado —las Órdenes antes que los recibos, los recibos
         * antes que las cuotas—.
         */
        $tablas = [
            'cash_count_lines',
            'cash_counts',
            'period_closings',
            'cash_to_bank_transfer_items',
            'cash_to_bank_transfers',
            'disbursements',
            'payment_order_funding_sources',
            'pases',
            'payment_orders',
            'receipt_financial_events',
            'receipts',
            'funding_allocations',
            'fund_receipts',
            'bank_transaction_allocations',
            'deposit_tickets',
            'bank_transactions',
            'bank_statement_rows',
            'bank_statement_imports',
            'journal_lines',
            'financial_events',
            'attachments',
            'audit_events',
            'beneficiary_installments',
            'haberes',
            'expedientes',
        ];

        $desactivadas = [];

        try {
            foreach ($tablas as $tabla) {
                DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
                $desactivadas[] = $tabla;
            }

            foreach ($tablas as $tabla) {
                DB::table($tabla)->delete();
            }
        } finally {
            foreach (array_reverse($desactivadas) as $tabla) {
                DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
            }
        }

        DB::table('document_series')->update(['next_number' => 1]);
    }

    /**
     * Empleadores y beneficiarios.
     *
     * Los CUIT están calculados con su dígito verificador: `Counterparty`
     * solo reconoce a la contraparte de un movimiento si el CUIT del
     * concepto valida por módulo 11. Con uno inventado, el extracto importa
     * sin identificar a nadie y el cruce no se puede probar.
     */
    private function personas(): void
    {
        $gente = [
            ['metalurgica', 'company', 'Metalúrgica del Norte SRL', '30711234566', 'employer', 'Av. Paraguay 1250, Salta', '3874216600'],
            ['frigorifico', 'company', 'Frigorífico Salta SA', '30709876542', 'employer', 'Ruta 9 Km 1592, Cerrillos', '3874390120'],
            ['transporte', 'company', 'Transporte Andino del Valle SRL', '30715558889', 'employer', 'Los Talas 480, Salta', '3874558890'],
            ['cardozo', 'individual', 'Cardozo, Ramón Alberto', '20144887', 'beneficiary', 'Belgrano 745, Salta', '3875110044'],
            ['vera', 'individual', 'Vera, Elena Beatriz', '27655012', 'beneficiary', 'Alvarado 1288, Salta', '3875220155'],
            ['nieva', 'individual', 'Nieva, Nora Cristina', '31288455', 'beneficiary', 'Mendoza 310, Salta', '3875330266'],
            ['paz', 'individual', 'Paz, Julio César', '24900713', 'beneficiary', 'Deán Funes 92, Salta', '3875440377'],
            ['quiroga', 'individual', 'Quiroga, Marta Isabel', '26709144', 'beneficiary', 'San Juan 655, Salta', '3875550488'],
            ['sosa', 'individual', 'Sosa, Hugo Daniel', '22315678', 'beneficiary', 'Caseros 1420, Salta', '3875660599'],
        ];

        /*
         * El domicilio y el teléfono no son decorado: la Orden de Pago los
         * imprime para las dos partes y `PaymentOrderEligibility` no deja
         * emitirla sin ellos. Un juego de datos sin esto no llega a la
         * documentación de pago.
         */
        foreach ($gente as [$apodo, $tipo, $nombre, $documento, $rol, $domicilio, $telefono]) {
            $persona = Person::query()->firstOrCreate(
                ['document' => $documento],
                [
                    'type' => $tipo,
                    ...$this->nameColumns($tipo, $nombre),
                    'address' => $domicilio,
                    'phone' => $telefono,
                    'is_active' => true,
                ],
            );

            $persona->forceFill(['address' => $domicilio, 'phone' => $telefono])->save();

            DB::table('person_roles')->insertOrIgnore([
                'person_id' => $persona->id,
                'role' => $rol,
                'person_type' => $tipo,
                'created_at' => now(),
            ]);

            $this->personas[$apodo] = (int) $persona->id;
        }
    }

    /**
     * La apertura, con las tres columnas de la planilla.
     *
     * La víspera del primer día operado. `BANK_ACCOUNT` es «DEPOSITOS
     * DIRECTOS»: lo que las empresas depositaron derecho en la cuenta y
     * todavía no se transfirió a su beneficiario.
     */
    private function abrirLibros(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 09:00'));

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja,
            balances: [
                LedgerAccount::CashOnHand->value => '2450000.00',
                LedgerAccount::ChequesInCustody->value => '380000.00',
                LedgerAccount::BankAccount->value => '1200000.00',
            ],
            date: CarbonImmutable::parse('2026-06-30'),
            bankAccountId: (int) $this->cuenta->id,
            actorId: $this->contadora->id,
            notes: 'Saldo existente al arranque del sistema, tomado de la planilla manual.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Julio
    |--------------------------------------------------------------------------
    */

    private function julio(): void
    {
        $this->importarExtracto('2026-07-01', 'extracto-macro-2026-07.xlsx', $this->movimientosDeJulio());

        // ── Los expedientes que entran en julio ──────────────────────────
        $metal = $this->expediente('EXP-4120/2026', '4120-2026', 'metalurgica', 'Metalúrgica del Norte — haberes adeudados', '1800000.00', '2026-07-01');
        $frigo = $this->expediente('EXP-4188/2026', '4188-2026', 'frigorifico', 'Frigorífico Salta — indemnización', '900000.00', '2026-07-06');

        $haberCardozo = $this->haber($metal, 'cardozo', 'Haberes adeudados marzo-mayo', '1800000.00', 3);
        $this->cuotas['cardozo-1'] = $this->cuota($haberCardozo, 1, '600000.00', ExpectedMedium::Cash);
        $this->cuotas['cardozo-2'] = $this->cuota($haberCardozo, 2, '600000.00', ExpectedMedium::Bank);
        $this->cuotas['cardozo-3'] = $this->cuota($haberCardozo, 3, '600000.00', ExpectedMedium::Bank);

        $haberVera = $this->haber($frigo, 'vera', 'Indemnización por despido', '900000.00', 2);
        $this->cuotas['vera-1'] = $this->cuota($haberVera, 1, '450000.00', ExpectedMedium::Cash);
        $this->cuotas['vera-2'] = $this->cuota($haberVera, 2, '450000.00', ExpectedMedium::Cash);

        // ── Las jornadas ─────────────────────────────────────────────────
        $this->jornada('2026-07-02', function (): void {
            $this->cobrarEnMostrador('cardozo-1', '72301');
        });

        $this->jornada('2026-07-07', function (): void {
            $this->cobrarEnMostrador('vera-1', '72302');
        });

        // El depósito directo del empleador: entra por el extracto.
        $this->jornada('2026-07-08', function (): void {
            $this->recibirDelBanco('cardozo-2', '2026-07-08', '600000.00', 'metalurgica');
        });

        // Un caso del sistema anterior, pagado contra el saldo de apertura.
        $this->jornada('2026-07-10', function (): void {
            app(PayLegacyBeneficiary::class)->handle(
                cashBoxId: $this->caja,
                beneficiary: Person::query()->findOrFail($this->personas['sosa']),
                amount: '300000.00',
                legacyReference: '131010/2023',
                paymentDate: CarbonImmutable::now(),
                medium: PaymentMedium::Cash,
                actorId: $this->contadora->id,
                talonarioNumber: '76410',
                printsTalonarioNumber: true,
            );
        });

        // La plata sale hacia su dueño, por mostrador.
        $this->jornada('2026-07-15', function (): void {
            $this->entregarEnMostrador('cardozo-1', '76411');
        });

        $this->jornada('2026-07-16', function (): void {
            $this->recibirDelBanco('cardozo-3', '2026-07-16', '600000.00', 'metalurgica');
        });

        /*
         * Un crédito que entra sin dueño: nadie lo imputa todavía. Queda en
         * `UNASSIGNED_FUNDS`, que es la cola de trabajo de la pantalla de
         * recepciones.
         */
        $this->jornada('2026-07-23', function (): void {
            app(RegisterBankFundReceipt::class)->handle(
                transaction: $this->movimiento('2026-07-23', '750000.00'),
                amount: '750000.00',
                idempotencyKey: 'demo:operacion:recepcion:sin-identificar',
                cashBoxId: $this->caja,
                depositorId: $this->personas['transporte'],
                actorId: $this->cajero->id,
                notes: 'Transferencia sin expediente identificado todavía.',
            );
        });

        // Un arqueo que cuadra pero no contó todo el cajón.
        $this->jornada('2026-07-28', function (): void {
            $this->cobrarEnMostrador('vera-2', '72303');
        }, [
            'sinRecontar' => '200000.00',
            'motivo' => 'El fajo de cincuenta mil quedó sin abrir, precintado del banco.',
        ]);

        $this->cerrarMes('2026-07-31');
    }

    /*
    |--------------------------------------------------------------------------
    | Agosto — el mes con los bordes
    |--------------------------------------------------------------------------
    */

    private function agosto(): void
    {
        $this->importarExtracto('2026-08-01', 'extracto-macro-2026-08.xlsx', $this->movimientosDeAgosto());

        $transporte = $this->expediente('EXP-4260/2026', '4260-2026', 'transporte', 'Transporte Andino — haberes', '750000.00', '2026-08-03');
        $metal2 = $this->expediente('EXP-4310/2026', '4310-2026', 'metalurgica', 'Metalúrgica del Norte — saldo final', '500000.00', '2026-08-10');

        $haberNieva = $this->haber($transporte, 'nieva', 'Haberes adeudados junio', '750000.00', 2);
        $this->cuotas['nieva-1'] = $this->cuota($haberNieva, 1, '375000.00', ExpectedMedium::Cheque);
        $this->cuotas['nieva-2'] = $this->cuota($haberNieva, 2, '375000.00', ExpectedMedium::Cash);

        $haberPaz = $this->haber($metal2, 'paz', 'Saldo final de la liquidación', '500000.00', 1);
        $this->cuotas['paz-1'] = $this->cuota($haberPaz, 1, '500000.00', ExpectedMedium::Bank);

        // Un cobro con cheque: entra a custodia, no al cajón.
        $this->jornada('2026-08-04', function (): void {
            $this->cobrarEnMostrador('nieva-1', '72304', [
                'number' => '00114577',
                'bank' => 'Banco Macro',
                'issueDate' => '2026-08-04',
            ]);
        });

        /*
         * La documentación de pago: la Orden y su Pase salen juntas para
         * una cuota ya financiada y con recibo. Exige CBU verificado.
         */
        $this->jornada('2026-08-06', function (): void {
            $this->emitirOrden('cardozo-2', 'cardozo');
        });

        /*
         * Los tres actos del §2.3: el organismo informa, el débito aparece
         * en el extracto, y el contador valida. Recién ahí hay egreso.
         */
        $this->jornada('2026-08-11', function (): void {
            $this->pagarPorTransferencia('cardozo-2', '2026-08-11', '600000.00');
        });

        // Un cobro que se anula: el dinero nunca entró.
        $this->jornada('2026-08-13', function (): void {
            $this->cobrarEnMostrador('nieva-2', '72305');

            app(VoidCashCollection::class)->handle(
                installment: $this->cuotas['nieva-2']->refresh(),
                reason: 'El beneficiario no llegó a firmar y el dinero volvió al cajón.',
                idempotencyKey: 'demo:operacion:anulacion:nieva-2',
                actorId: $this->contadora->id,
            );
        });

        // El efectivo que nadie retiró se deposita en la cuenta.
        $this->jornada('2026-08-18', function (): void {
            app(DepositCashToBank::class)->handle(
                installment: $this->cuotas['vera-1']->refresh(),
                ticket: [
                    'bankAccountId' => $this->cuenta->id,
                    'depositDate' => CarbonImmutable::parse('2026-08-18'),
                    'depositTime' => '10:42:00',
                    'operationNumber' => '995979830',
                    'terminal' => 'T-014',
                    'notes' => 'La beneficiaria no se presentó a retirarlo.',
                ],
                foto: $this->fotoDelTicket(),
                idempotencyKey: 'demo:operacion:traslado:vera-1',
                actorId: $this->cajero->id,
            );
        });

        // Y el banco lo acredita dos días después.
        $this->jornada('2026-08-20', function (): void {
            $this->acreditarTraslado('2026-08-20', '450000.00');
        });

        // El depósito directo de la última cuota.
        $this->jornada('2026-08-21', function (): void {
            $this->recibirDelBanco('paz-1', '2026-08-21', '500000.00', 'metalurgica');
        });

        /*
         * Los comprobantes que el empleador trae en papel. Uno queda
         * pendiente de cruzar contra el extracto y el otro se descarta:
         * son los dos estados que la pantalla de Comprobantes existe para
         * mostrar.
         */
        $this->jornada('2026-08-24', function (): void {
            $this->cargarComprobante('nieva-2', '1147200500', '2026-08-24', '375000.00');

            $descartado = $this->cargarComprobante('paz-1', '9988776655', '2026-08-24', '500000.00');

            app(DiscardDepositTicket::class)->handle(
                $descartado,
                'El comprobante era de otro expediente: lo trajo el empleador equivocado.',
                $this->contadora->id,
            );
        });

        /*
         * El arqueo que no cuadra. Falta plata contra el libro, y el
         * contador la imputa contra `CASH_DIFFERENCE`: es la operación más
         * delicada del circuito y deja su autorización escrita.
         */
        $this->jornada('2026-08-25', function (): void {
            $this->cobrarEnMostrador('nieva-2', '72306');
        }, [
            'faltante' => '5000.00',
            'explicacion' => 'Faltan cinco mil contra el libro. Se revisa el vuelto del último pago.',
        ], ajustar: true);

        // Un día cerrado que después hubo que reabrir.
        $this->jornada('2026-08-27', function (): void {
            $this->entregarEnMostrador('vera-2', '76412');
        });

        $this->reabrir('2026-08-27', 'Faltaba cargar un comprobante que el área encontró al día siguiente.');

        $this->cerrarMes('2026-08-31');
    }

    /*
    |--------------------------------------------------------------------------
    | Septiembre — el mes vivo
    |--------------------------------------------------------------------------
    */

    /**
     * El mes en curso, deliberadamente **sin cerrar del todo**.
     *
     * Es lo que hace útil al calendario: un mes donde todo está cerrado no
     * muestra nada, y lo que el operador busca son los días que quedaron
     * pendientes. Hoy es el 4, así que el 1 queda cerrado, el 2 arqueado
     * pero sin cerrar, y el 3 con movimientos y sin arqueo.
     */
    private function septiembre(): void
    {
        $frigo2 = $this->expediente('EXP-4402/2026', '4402-2026', 'frigorifico', 'Frigorífico Salta — segunda liquidación', '600000.00', '2026-09-01');

        $haberQuiroga = $this->haber($frigo2, 'quiroga', 'Haberes adeudados julio', '600000.00', 2);
        $this->cuotas['quiroga-1'] = $this->cuota($haberQuiroga, 1, '300000.00', ExpectedMedium::Cash);
        $this->cuotas['quiroga-2'] = $this->cuota($haberQuiroga, 2, '300000.00', ExpectedMedium::Cash);

        $this->jornada('2026-09-01', function (): void {
            $this->cobrarEnMostrador('quiroga-1', '72307');
        });

        // Arqueado pero sin cerrar: el cierre quedó para el día siguiente.
        $this->jornada('2026-09-02', function (): void {
            $this->cobrarEnMostrador('quiroga-2', '72308');
        }, cerrar: false);

        // Con movimientos, sin arqueo y sin cierre: el día que el calendario grita.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03')->setTime(11, 0));
        $this->entregarEnMostrador('quiroga-1', '76413');

        /*
         * Un expediente que llegó mal y se anula sin haber movido un peso.
         * Es el estado que la pantalla de expedientes tiene que saber
         * mostrar sin confundirlo con uno vigente.
         */
        $anulado = $this->expediente('EXP-4415/2026', '4415-2026', 'transporte', 'Transporte Andino — presentación duplicada', '250000.00', '2026-09-02');

        app(CancelExpediente::class)->handle(
            $anulado,
            'Duplicado: el mismo reclamo ya había entrado como EXP-4260/2026.',
        );
    }

    /**
     * Un comprobante de depósito traído en papel.
     *
     * Queda pendiente de cruzar contra el extracto, que es el estado en el
     * que el área los carga: el papel llega antes que el movimiento.
     */
    private function cargarComprobante(
        string $clave,
        string $operacion,
        string $fecha,
        string $importe,
    ): DepositTicket {
        $cuota = $this->cuotas[$clave]->refresh();

        return app(RegisterDepositTicket::class)->handle(
            datos: [
                'expedienteId' => $cuota->haber->expediente_id,
                'haberId' => $cuota->haber_id,
                'installmentId' => $cuota->id,
                'bankAccountId' => $this->cuenta->id,
                'depositedAt' => $fecha,
                'depositedTime' => '09:45:00',
                'amount' => $importe,
                'operationNumber' => $operacion,
                'depositKind' => DepositKind::Transfer,
                'notes' => 'Comprobante traído por el representante del empleador.',
            ],
            foto: $this->fotoDelTicket(),
            userId: $this->cajero->id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Los actos
    |--------------------------------------------------------------------------
    */

    /**
     * Una jornada: lo que pasó, el arqueo y el cierre del día.
     *
     * El arqueo se cuenta contra el libro —se lee el saldo y se desglosa en
     * billetes— así que cuadra siempre, salvo donde se pida lo contrario.
     *
     * @param  array{sinRecontar?: string, motivo?: string, faltante?: string, explicacion?: string}  $arqueo
     */
    private function jornada(string $fecha, callable $actos, array $arqueo = [], bool $cerrar = true, bool $ajustar = false): void
    {
        $dia = CarbonImmutable::parse($fecha);

        CarbonImmutable::setTestNow($dia->setTime(10, 0));
        $actos();

        CarbonImmutable::setTestNow($dia->setTime(17, 30));
        $this->arquear($dia, $arqueo, $ajustar);

        if ($cerrar) {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja,
                date: $dia,
                actorId: $this->contadora->id,
            );
        }
    }

    /** @param  array{sinRecontar?: string, motivo?: string, faltante?: string, explicacion?: string}  $receta */
    private function arquear(CarbonImmutable $fecha, array $receta, bool $ajustar = false): void
    {
        $enCaja = app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja, upTo: $fecha);

        $sinRecontar = $receta['sinRecontar'] ?? '0.00';
        $faltante = $receta['faltante'] ?? '0.00';

        $aContar = Decimal::sub(Decimal::sub($enCaja, $sinRecontar), $faltante);

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja,
            countedOn: $fecha,
            denominations: $this->desglosar($aContar),
            actorId: $this->cajero->id,
            uncountedAmount: $sinRecontar,
            uncountedReason: $receta['motivo'] ?? null,
            explanation: $receta['explicacion'] ?? null,
        );

        app(ReviewCashCount::class)->handle($arqueo, $this->contadora->id);

        if ($ajustar) {
            app(AdjustCashDifference::class)->handle(
                $arqueo->refresh(),
                $this->contadora->id,
                'Diferencia imputada por la contadora tras revisar el movimiento del día.',
            );
        }
    }

    /** @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque */
    private function cobrarEnMostrador(string $clave, string $talonario, ?array $cheque = null): void
    {
        app(CollectAndIssueReceipt::class)->handle(
            installment: $this->cuotas[$clave],
            /*
             * La clave lleva el número de talonario y no solo la cuota: una
             * cuota puede cobrarse dos veces —anulada la primera— y con la
             * clave repetida el segundo cobro devolvería el evento viejo en
             * vez de mover dinero.
             */
            idempotencyKey: 'demo:operacion:cobro:'.$clave.':'.$talonario,
            actorId: $this->cajero->id,
            talonarioNumber: $talonario,
            printsTalonarioNumber: true,
            receivedDate: CarbonImmutable::now(),
            cheque: $cheque,
        );
    }

    /** Un crédito del extracto que se recibe, se imputa y emite su recibo. */
    private function recibirDelBanco(string $clave, string $fecha, string $importe, string $empleador): void
    {
        $movimiento = $this->movimiento($fecha, $importe);

        $recepcion = app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'demo:operacion:recepcion:'.$movimiento->id,
            cashBoxId: $this->caja,
            depositorId: $this->personas[$empleador],
            actorId: $this->cajero->id,
            notes: 'Transferencia del empleador, identificada por CUIT.',
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $this->cuotas[$clave],
            amount: $importe,
            idempotencyKey: 'demo:operacion:imputacion:'.$clave,
            actorId: $this->cajero->id,
        );

        app(IssueIncomeReceipt::class)->handle(
            installment: $this->cuotas[$clave]->refresh(),
            actorId: $this->cajero->id,
        );
    }

    /** La entrega en mano: el dinero sale del cajón hacia su dueño. */
    private function entregarEnMostrador(string $clave, string $talonario): void
    {
        app(DeliverAndIssueExpenseReceipt::class)->handle(
            installment: $this->cuotas[$clave]->refresh(),
            idempotencyKey: 'demo:operacion:egreso:'.$clave,
            actorId: $this->cajero->id,
            talonarioNumber: $talonario,
            printsTalonarioNumber: true,
            paymentDate: CarbonImmutable::now(),
        );
    }

    /**
     * La Orden de Pago y su Pase.
     *
     * Exige que el beneficiario tenga un CBU **verificado**: sin eso no hay
     * a dónde transferir, y el Action lo rechaza. La verificación va por su
     * Action, que deja el rastro de quién coteja la foja del expediente.
     */
    private function emitirOrden(string $clave, string $beneficiario): void
    {
        $cuenta = PersonBankAccount::query()->firstOrCreate(
            ['person_id' => $this->personas[$beneficiario]],
            [
                'cbu' => '2850000300000000000017',
                'bank_name' => 'Banco Macro',
                'holder_name' => Person::query()->findOrFail($this->personas[$beneficiario])->name,
                'verification_status' => 'unverified',
                'is_active' => true,
            ],
        );

        if ($cuenta->verification_status !== 'verified') {
            app(VerifyPersonBankAccount::class)->handle($cuenta, $this->contadora->id);
        }

        app(IssuePaymentOrder::class)->handle(
            installment: $this->cuotas[$clave]->refresh(),
            data: new IssuePaymentOrderData(
                beneficiaryBankAccountId: (int) $cuenta->refresh()->id,
                cbuFolio: '14',
                incomeReceiptNumberSource: ReceiptNumberSource::System,
                paseDestination: IssuePaymentOrderData::DEFAULT_DESTINATION,
                treasurerId: null,
            ),
            actorId: $this->contadora->id,
        );
    }

    /**
     * Los tres actos del egreso por transferencia.
     *
     * El organismo informa que transfirió, el débito aparece en el extracto
     * y el contador coteja los dos contra la Orden. Recién esa validación
     * postea el asiento y da por pagada la cuota.
     */
    private function pagarPorTransferencia(string $clave, string $fecha, string $importe): void
    {
        $cuota = $this->cuotas[$clave]->refresh();

        app(RegisterTransferReport::class)->handle(
            installment: $cuota,
            reportedAt: CarbonImmutable::now(),
            reference: 'Nota SAF 1188/2026',
            actorId: $this->contadora->id,
        );

        app(LinkTransferDebit::class)->handle(
            installment: $cuota->refresh(),
            transaction: $this->movimiento($fecha, $importe, TransactionDirection::Debit),
            actorId: $this->contadora->id,
        );

        app(ValidateTransferDisbursement::class)->handle(
            disbursement: Disbursement::query()
                ->where('beneficiary_installment_id', $cuota->id)
                ->latest('id')
                ->firstOrFail(),
            actorId: $this->contadora->id,
        );
    }

    /** El crédito con el que el banco acredita el efectivo depositado. */
    private function acreditarTraslado(string $fecha, string $importe): void
    {
        $traslado = CashToBankTransfer::query()->orderByDesc('id')->firstOrFail();

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->movimiento($fecha, $importe),
            idempotencyKey: 'demo:operacion:acreditacion:'.$traslado->id,
            actorId: $this->cajero->id,
        );
    }

    /** Reabre un cierre diario, con su motivo. */
    private function reabrir(string $fecha, string $motivo): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($fecha)->addDay()->setTime(9, 0));

        $cierre = PeriodClosing::query()
            ->where('cash_box_id', $this->caja)
            ->where('period_type', PeriodType::Daily)
            ->whereDate('period_from', $fecha)
            ->firstOrFail();

        app(ReopenPeriod::class)->handle($cierre, $this->contadora->id, $motivo);
    }

    private function escribirFoto(): string
    {
        $archivo = tempnam(sys_get_temp_dir(), 'sacst');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo reservar el archivo temporal del ticket.');
        }

        $contenido = base64_decode(self::PIXEL, true);

        if ($contenido === false || file_put_contents($archivo, $contenido) === false) {
            @unlink($archivo);
            throw new RuntimeException('No se pudo escribir la foto del ticket.');
        }

        return $archivo;
    }

    private function fotoDelTicket(): UploadedFile
    {
        return new UploadedFile($this->foto, 'ticket-cajero.jpg', 'image/jpeg', null, true);
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:float}> */
    private function movimientosDeAgosto(): array
    {
        return [
            ['05/08/2026', '1146588241', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 250000.00],
            ['11/08/2026', '1146903557', '3310', 'TRF MO CCDO SAF GOBIERNO 30222222229 HABERES', -600000.00],
            ['20/08/2026', '995979830', '2201', '995979830 - Numero de Operacion', 450000.00],
            ['21/08/2026', '1147011460', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 500000.00],
            ['26/08/2026', '1147388021', '0512', 'Comision Trf. MacrOL E-set', -9200.75],
        ];
    }

    private function cerrarMes(string $fecha): void
    {
        $dia = CarbonImmutable::parse($fecha);

        CarbonImmutable::setTestNow($dia->setTime(19, 0));

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja,
            date: $dia,
            type: PeriodType::Monthly,
            actorId: $this->contadora->id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | El extracto
    |--------------------------------------------------------------------------
    */

    /** @return list<array{0:string,1:string,2:string,3:string,4:float}> */
    private function movimientosDeJulio(): array
    {
        return [
            ['08/07/2026', '1145832449', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 600000.00],
            ['09/07/2026', '1145901882', '0512', 'Comision Trf. MacrOL E-set', -8500.50],
            ['16/07/2026', '1146077310', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 600000.00],
            ['23/07/2026', '1146214905', '4397', 'ING TRANSF:TRANSPORTE ANDINO DEL V-30715558889', 750000.00],
        ];
    }

    /** @param  list<array{0:string,1:string,2:string,3:string,4:float}>  $movimientos */
    private function importarExtracto(string $cuando, string $nombre, array $movimientos): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($cuando)->setTime(8, 30));

        $archivo = $this->escribirExtracto($nombre, $movimientos);

        app(ImportBankStatement::class)->handle(
            file: new UploadedFile($archivo, $nombre, null, null, true),
            account: $this->cuenta,
            userId: $this->cajero->id,
        );
    }

    /**
     * Escribe el `.xlsx` con el formato que exporta MacroOnline.
     *
     * Las posiciones no son un orden ideal: la hoja del banco usa celdas
     * combinadas y deja huecos, y `MacroExcelParser` lee por posición.
     *
     * @param  list<array{0:string,1:string,2:string,3:string,4:float}>  $movimientos
     */
    private function escribirExtracto(string $nombre, array $movimientos): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();

        $texto = function (string $celda, string $valor) use ($hoja): void {
            $hoja->setCellValueExplicit($celda, $valor, DataType::TYPE_STRING);
        };

        $texto('A1', 'Tipo');
        $texto('C1', 'Cuenta Corriente');
        $texto('A2', 'Número');
        $texto('C2', self::CUENTA);
        $texto('A3', 'Moneda');
        $texto('C3', 'PESOS');

        $texto('A4', 'Fecha');
        $texto('D4', 'Nro. de Referencia');
        $texto('E4', 'Causal');
        $texto('F4', 'Concepto');
        $texto('G4', 'Importe');
        $texto('K4', 'Saldo');

        $saldo = 17692768.55;
        $fila = 5;

        foreach ($movimientos as [$fecha, $referencia, $causal, $concepto, $importe]) {
            $saldo = round($saldo + $importe, 2);

            $texto('A'.$fila, $fecha);
            $texto('D'.$fila, $referencia);
            $texto('E'.$fila, $causal);
            $texto('F'.$fila, $concepto);
            $hoja->setCellValue('G'.$fila, $importe);
            $hoja->setCellValue('K'.$fila, $saldo);

            $fila++;
        }

        $texto('A'.$fila, 'Fecha de descarga: '.CarbonImmutable::now()->format('d/m/Y H:i:s'));
        $texto('A'.($fila + 1), 'Empresa: 30222222229 - MINISTERIO DE GOBIERNO, DERECHOS HUMANOS Y TRABAJO');
        $texto('A'.($fila + 2), 'Operador: ANA MARIA GONZALEZ');

        $carpeta = storage_path('app/demo');

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0o775, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}.");
        }

        $archivo = $carpeta.DIRECTORY_SEPARATOR.$nombre;

        (new Xlsx($libro))->save($archivo);

        return $archivo;
    }

    /**
     * El movimiento importado de esa fecha y ese importe.
     *
     * El importe se busca **en positivo**: el extracto trae el signo, pero
     * el parser lo guarda en `amount` sin signo y pone el sentido en
     * `direction`. Buscar por «-600000» no encontraría nada.
     */
    private function movimiento(
        string $fecha,
        string $importe,
        TransactionDirection $direccion = TransactionDirection::Credit,
    ): BankTransaction {
        return BankTransaction::query()
            ->where('bank_account_id', $this->cuenta->id)
            ->whereDate('transaction_date', $fecha)
            ->where('amount', $importe)
            ->where('direction', $direccion)
            ->orderBy('id')
            ->firstOrFail();
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function expediente(
        string $display,
        string $canonical,
        string $empleador,
        string $asunto,
        string $total,
        string $recibido,
    ): Expediente {
        return Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => $canonical,
            'display_number' => $display,
            'year' => (int) substr($display, -4),
            'received_date' => $recibido,
            'employer_id' => $this->personas[$empleador],
            'employer_role' => 'employer',
            'subject' => $asunto,
            'declared_total_amount' => $total,
            'status' => ExpedienteStatus::Active,
        ]);
    }

    private function haber(
        Expediente $expediente,
        string $beneficiario,
        string $concepto,
        string $importe,
        int $cuotas,
    ): Haber {
        return $expediente->haberes()->create([
            'concept' => $concepto,
            'beneficiary_id' => $this->personas[$beneficiario],
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => $importe,
            'expected_installment_count' => $cuotas,
            'payment_terms' => $cuotas > 1 ? PaymentTerms::Installments : PaymentTerms::Single,
            'workflow_status' => HaberWorkflowStatus::Active,
        ]);
    }

    private function cuota(
        Haber $haber,
        int $numero,
        string $importe,
        ExpectedMedium $medio,
    ): BeneficiaryInstallment {
        return $haber->installments()->create([
            'installment_number' => $numero,
            'expected_amount' => $importe,
            'expected_medium' => $medio,
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);
    }

    /** @return array<int, int> */
    private function desglosar(string $importe): array
    {
        $restante = (int) round((float) $importe);
        $desglose = [];

        foreach (self::BILLETES as $billete) {
            $cantidad = intdiv($restante, $billete);

            if ($cantidad > 0) {
                $desglose[$billete] = $cantidad;
                $restante -= $cantidad * $billete;
            }
        }

        if ($restante !== 0) {
            throw new RuntimeException(
                "El importe {$importe} no se forma con los billetes del área: sobran {$restante}."
            );
        }

        return $desglose;
    }

    private function informe(): void
    {
        $cierres = DB::table('period_closings')->count();
        $eventos = DB::table('financial_events')->count();
        $recibos = DB::table('receipts')->count();

        $this->command->info("Cierres: {$cierres} · Asientos: {$eventos} · Comprobantes: {$recibos}");
    }
}
