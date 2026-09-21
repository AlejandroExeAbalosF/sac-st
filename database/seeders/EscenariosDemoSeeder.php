<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Banking\Actions\ImportBankStatement;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\RegisterDepositTicket;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Shared\Models\Person;
use Database\Seeders\Concerns\SplitsPersonName;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Tres expedientes que recorren el circuito entero, con sus bordes.
 *
 * **Reemplaza todo lo que había.** No es acumulativo: vacía expedientes,
 * comprobantes, libro, tickets y extractos, y vuelve a construir desde
 * cero. Es para desarrollo, y por eso puede permitirse borrar.
 *
 * **Los estados se producen corriendo los Actions reales**, no insertando
 * filas a mano. Una cuota «cobrada» escrita directamente dejaría el libro
 * sin sus asientos: serviría para mirar la pantalla, no para probar nada
 * —el primer arqueo o la primera reversión encontrarían un estado que el
 * sistema nunca podría haber producido—.
 *
 * Por la misma razón el extracto bancario es un **archivo de verdad**: se
 * escribe un `.xlsx` con el formato de MacroOnline y se importa por el
 * Action de siempre, con su parser, su huella y su deduplicación. Queda en
 * `storage/app/demo/` para volver a subirlo desde la pantalla.
 *
 * Los tres casos, y en qué estado queda cada cuota:
 *
 * | Expediente | Cuota | Queda |
 * |---|---|---|
 * | Cardozo — banco | 1 | acreditada e imputada, con recibo emitido |
 * | | 2 | ticket cargado, esperando su crédito en el extracto |
 * | | 3 | sin comprobante: el crédito está en el extracto, sin imputar |
 * | Vera — mostrador | 1 | cobrada y depositada: efectivo en tránsito |
 * | | 2 | recibo anulado y vuelto a emitir |
 * | | 3 | cheque en custodia, con recibo |
 * | Nieva / Paz — bordes | 1 | cobrada y después el importe bajó: sobra plata |
 * | | 2 | cobrada y después el importe subió: falta plata |
 * | | 3 | haber bloqueado, con su crédito esperando en el extracto |
 */
final class EscenariosDemoSeeder extends Seeder
{
    use SplitsPersonName;

    private const CUENTA = '310000123456789';

    private const ARCHIVO = 'extracto-macro-2026-08.xlsx';

    /** JPEG de 1x1 que hace de foto en todos los tickets. */
    private const PIXEL = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw'
        .'8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMj'
        .'IyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBg'
        .'cICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NT'
        .'Y3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8'
        .'jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBA'
        .'cFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVl'
        .'dYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5e'
        .'bn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD3+iiigD//2Q==';

    private User $operador;

    private BankAccount $cuenta;

    private int $caja;

    /**
     * Apodo → id, para la gente del caso.
     *
     * El apodo y no el documento: PHP convierte a entero toda clave de
     * arreglo que parezca un número, y un CUIT lo parece.
     *
     * @var array<string, int>
     */
    private array $personas = [];

    private string $foto;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'EscenariosDemoSeeder reemplaza todos los datos del circuito y solo puede ejecutarse en local o testing.'
            );
        }

        // Las cuotas de los escenarios se etiquetan por código: sin el
        // catálogo maestro quedarían todas sin etiqueta y en silencio.
        $this->call(HaberManagementLabelSeeder::class);

        $this->operador = User::query()->orderBy('id')->firstOrFail();
        $caja = DB::table('cash_boxes')->where('code', 'haberes')->value('id');

        if ($caja === null) {
            throw new RuntimeException('No existe la caja de Haberes. Ejecutá CashBoxSeeder antes de los escenarios demo.');
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

            $banco = $this->expedienteBancario();
            $mostrador = $this->expedienteMostrador();
            $bordes = $this->expedienteDeBordes();

            /*
             * El extracto entra **antes** de tejer los estados: la primera
             * cuota se acredita contra un movimiento importado, y ese
             * movimiento tiene que existir.
             */
            $archivo = $this->escribirExtracto();
            $this->importar($archivo);

            $this->tejerBancario($banco);
            $this->tejerMostrador($mostrador);
            $this->tejerBordes($bordes);

            $this->command->info('Tres expedientes y un extracto de nueve movimientos.');
            $this->command->info('El archivo quedó en '.$archivo);
        } finally {
            @unlink($this->foto);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Vaciar
    |--------------------------------------------------------------------------
    */

    /**
     * Deja la base sin nada del circuito.
     *
     * Los triggers append-only se apagan y se vuelven a prender en el
     * `finally`: son los que impiden borrar hechos monetarios, y acá se
     * está tirando todo a propósito. Fuera de desarrollo esto no existe.
     */
    private function vaciar(): void
    {
        $tablas = [
            'cash_to_bank_transfer_items',
            'cash_to_bank_transfers',
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

        // Sin comprobantes emitidos, la numeración vuelve a empezar.
        DB::table('document_series')->update(['next_number' => 1]);
    }

    /*
    |--------------------------------------------------------------------------
    | Personas
    |--------------------------------------------------------------------------
    */

    /**
     * Gente propia del caso, no la del set anterior.
     *
     * Los CUIT están **calculados con su dígito verificador**, y no es
     * prolijidad: `Counterparty` solo reconoce a la contraparte de un
     * movimiento si el CUIT que aparece en el concepto valida por módulo
     * 11. Con uno inventado, el extracto importa sin identificar a nadie y
     * el cruce no se puede probar.
     */
    private function personas(): void
    {
        $gente = [
            ['metalurgica', 'company', 'Metalúrgica del Norte SRL', '30711234566', 'employer'],
            ['frigorifico', 'company', 'Frigorífico Salta SA', '30709876542', 'employer'],
            ['transporte', 'company', 'Transporte Andino del Valle SRL', '30715558889', 'employer'],
            ['cardozo', 'individual', 'Cardozo, Ramón Alberto', '20144887', 'beneficiary'],
            ['vera', 'individual', 'Vera, Elena Beatriz', '27655012', 'beneficiary'],
            ['nieva', 'individual', 'Nieva, Nora Cristina', '31288455', 'beneficiary'],
            ['paz', 'individual', 'Paz, Julio César', '24900713', 'beneficiary'],
        ];

        foreach ($gente as [$apodo, $tipo, $nombre, $documento, $rol]) {
            $persona = Person::query()->firstOrCreate(
                ['document' => $documento],
                ['type' => $tipo, ...$this->nameColumns($tipo, $nombre), 'is_active' => true],
            );

            /*
             * El rol es una fila aparte porque la FK compuesta de
             * `expedientes` y `haberes` apunta ahí: es lo que impide
             * cargar a un beneficiario en el lugar del empleador.
             */
            DB::table('person_roles')->insertOrIgnore([
                'person_id' => $persona->id,
                'role' => $rol,
                'person_type' => $tipo,
                'created_at' => now(),
            ]);

            $this->personas[$apodo] = $persona->id;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1 · Cardozo — el circuito bancario
    |--------------------------------------------------------------------------
    */

    /** @return list<BeneficiaryInstallment> */
    private function expedienteBancario(): array
    {
        $expediente = $this->expediente(
            display: '100234/2026',
            canonical: '0030064-100234/2026-0',
            empleador: 'metalurgica',
            asunto: 'Cardozo Ramón Alberto c/ Metalúrgica del Norte SRL s/ diferencias salariales',
            total: '850000.00',
            recibido: '2026-08-03',
            representante: 'Ferreyra, Silvina Noemí',
        );

        $haber = $this->haber($expediente, 'cardozo', 'Diferencias salariales homologadas', '850000.00', 3);

        return [
            $this->cuota($haber, 1, '300000.00', ExpectedMedium::Bank, 'T.CONOC.'),
            $this->cuota($haber, 2, '300000.00', ExpectedMedium::Bank, 'T.CONOC.'),
            $this->cuota($haber, 3, '250000.00', ExpectedMedium::Bank, 'PAGAR',
                'El estudio todavía no acercó el comprobante de la transferencia.'),
        ];
    }

    /** @param  list<BeneficiaryInstallment>  $cuotas */
    private function tejerBancario(array $cuotas): void
    {
        [$acreditada, $conTicket] = $cuotas;

        // ── Cuota 1: el crédito del 05/08 se recibe, se imputa y se emite.
        $movimiento = $this->movimiento('2026-08-05', '300000.00');

        $recepcion = app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: '300000.00',
            idempotencyKey: 'demo:recepcion:'.$movimiento->id,
            cashBoxId: $this->caja,
            depositorId: $this->personas['metalurgica'],
            actorId: $this->operador->id,
            notes: 'Transferencia del empleador, identificada por CUIT.',
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $acreditada,
            amount: '300000.00',
            idempotencyKey: 'demo:imputacion:'.$acreditada->id,
            actorId: $this->operador->id,
        );

        app(IssueIncomeReceipt::class)->handle(
            installment: $acreditada->refresh(),
            actorId: $this->operador->id,
        );

        // ── Cuota 2: el ticket está cargado; el crédito del 12/08 espera.
        app(RegisterDepositTicket::class)->handle(
            datos: [
                'expedienteId' => $conTicket->haber->expediente_id,
                'haberId' => $conTicket->haber_id,
                'installmentId' => $conTicket->id,
                'bankAccountId' => $this->cuenta->id,
                'depositedAt' => '2026-08-12',
                'depositedTime' => '11:20:00',
                'amount' => '300000.00',
                'operationNumber' => '1146077310',
                'depositKind' => DepositKind::Transfer,
                'notes' => 'Comprobante traído por el representante del empleador.',
            ],
            foto: $this->fotoDelTicket(),
            userId: $this->operador->id,
        );

        // La cuota 3 queda a propósito sin nada: su crédito está en el
        // extracto, sin ningún comprobante que lo anuncie.
    }

    /*
    |--------------------------------------------------------------------------
    | 2 · Vera — el mostrador, con todo lo que puede pasarle
    |--------------------------------------------------------------------------
    */

    /** @return list<BeneficiaryInstallment> */
    private function expedienteMostrador(): array
    {
        $expediente = $this->expediente(
            display: '100987/2026',
            canonical: '0030064-100987/2026-0',
            empleador: 'frigorifico',
            asunto: 'Vera Elena Beatriz c/ Frigorífico Salta SA s/ despido',
            total: '540500.00',
            recibido: '2026-08-06',
        );

        $haber = $this->haber($expediente, 'vera', 'Indemnización por despido', '540500.00', 3);

        return [
            $this->cuota($haber, 1, '180000.00', ExpectedMedium::Cash, 'PAGAR'),
            $this->cuota($haber, 2, '180500.00', ExpectedMedium::Cash, 'T.CONOC.'),
            $this->cuota($haber, 3, '180000.00', ExpectedMedium::Cheque, 'T.CONOC.'),
        ];
    }

    /** @param  list<BeneficiaryInstallment>  $cuotas */
    private function tejerMostrador(array $cuotas): void
    {
        [$depositada, $rehecha, $cheque] = $cuotas;

        /*
         * Cuota 1: cobrada y llevada al banco. Queda en tránsito, y su
         * crédito del 20/08 espera en el extracto para confirmarla.
         */
        $this->cobrar($depositada);

        app(DepositCashToBank::class)->handle(
            installment: $depositada->refresh(),
            ticket: [
                'bankAccountId' => $this->cuenta->id,
                'depositDate' => Carbon::parse('2026-08-20'),
                'depositTime' => '10:42:00',
                'operationNumber' => '995979830',
                'terminal' => 'T-014',
                'notes' => 'La beneficiaria no se presentó a retirarlo.',
            ],
            foto: $this->fotoDelTicket(),
            idempotencyKey: 'demo:traslado:'.$depositada->id,
            actorId: $this->operador->id,
        );

        /*
         * Cuota 2: se emitió, se anuló y se volvió a emitir. Deja la
         * cadena de numeración a la vista —un correlativo anulado y otro
         * vigente sobre la misma cuota—, que es lo que el operador va a
         * ver cuando se equivoque de verdad.
         */
        $this->cobrar($rehecha);

        app(VoidCashCollection::class)->handle(
            installment: $rehecha->refresh(),
            reason: 'El importe se tipeó con un dígito de más y el recibo salió por $ 1.805.000.',
            idempotencyKey: 'demo:anulacion:'.$rehecha->id,
            actorId: $this->operador->id,
        );

        $this->cobrar($rehecha->refresh());

        // Cuota 3: cheque en custodia.
        $this->cobrar($cheque, [
            'number' => '00214877',
            'bank' => 'Banco Macro',
            'issueDate' => '2026-08-18',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3 · Nieva y Paz — los bordes
    |--------------------------------------------------------------------------
    */

    /** @return list<BeneficiaryInstallment> */
    private function expedienteDeBordes(): array
    {
        $expediente = $this->expediente(
            display: '101445/2026',
            canonical: '0030064-101445/2026-0',
            empleador: 'transporte',
            asunto: 'Nieva Nora Cristina y otro c/ Transporte Andino del Valle SRL s/ accidente de trabajo',
            total: '1345000.00',
            recibido: '2026-08-11',
        );

        $nieva = $this->haber($expediente, 'nieva', 'Accidente de trabajo — acuerdo', '95000.00', 2);

        /*
         * El haber de Paz nace bloqueado y su crédito está en el extracto:
         * es el caso que prueba que la plata puede llegar antes de que el
         * expediente esté en condiciones de imputarla.
         */
        $paz = $this->haber(
            $expediente,
            'paz',
            'Accidente de trabajo — sentencia',
            '1250000.00',
            1,
            HaberWorkflowStatus::Blocked,
            'Falta la homologación firmada: no se imputa hasta que llegue.',
        );

        $this->cuota($paz, 1, '1250000.00', ExpectedMedium::Bank, 'P.P. HOMOL.');

        return [
            $this->cuota($nieva, 1, '47500.00', ExpectedMedium::Cash, 'PAGAR'),
            $this->cuota($nieva, 2, '47500.00', ExpectedMedium::Cash, 'PAGAR'),
        ];
    }

    /** @param  list<BeneficiaryInstallment>  $cuotas */
    private function tejerBordes(array $cuotas): void
    {
        [$sobra, $falta] = $cuotas;

        // Cobrada por $ 47.500 y después el importe bajó: sobran $ 1.500
        // imputados que la cuota ya no reclama.
        $this->cobrar($sobra);
        $this->corregirImporte($sobra, '46000.00');

        // Al revés: cobrada y después el importe subió. Faltan $ 1.500.
        $this->cobrar($falta);
        $this->corregirImporte($falta, '49000.00');
    }

    /*
    |--------------------------------------------------------------------------
    | El extracto
    |--------------------------------------------------------------------------
    */

    /**
     * Los movimientos, en el orden en que los trae el banco.
     *
     * Cada uno existe por una razón; ninguno es relleno:
     *
     * - **05/08 · 300.000** el crédito ya recibido e imputado.
     * - **07/08 · −8.500,50** un débito de comisión: nada que imputar.
     * - **12/08 · 300.000** el que corresponde al ticket cargado, esperando
     *   que alguien lo cruce.
     * - **14/08 · 250.000** llega sin comprobante que lo anuncie.
     * - **18/08 · 1.250.000** el del haber bloqueado: la plata llegó antes
     *   que la homologación.
     * - **20/08 · 180.000** un depósito por ventanilla, **sin contraparte**
     *   —es el traslado del efectivo, y el banco no informa de quién es—.
     * - **21/08 · 47.499** casi el importe de una cuota, con un peso de
     *   diferencia: el filtro exacto no lo encuentra y hay que abrir la
     *   lista amplia.
     * - **22/08 · 95.000** no corresponde a nada declarado.
     * - **26/08 · −120.000** una transferencia saliente.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: float}>
     */
    private function movimientos(): array
    {
        return [
            ['05/08/2026', '1145832449', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 300000.00],
            ['07/08/2026', '1145901882', '0512', 'Comision Trf. MacrOL E-set', -8500.50],
            ['12/08/2026', '1146077310', '4397', 'TRANSF METALURGICA DEL NORTE 30711234566 VAR VARIOS', 300000.00],
            ['14/08/2026', '1146214905', '4397', 'CREDIN:ORD6LEN87LXOLKJ42M1Y30-30711234566', 250000.00],
            ['18/08/2026', '1146588241', '4397', 'ING TRANSF:TRANSPORTE ANDINO DEL V-30715558889', 1250000.00],
            ['20/08/2026', '995979830', '2201', '995979830 - Numero de Operacion', 180000.00],
            ['21/08/2026', '1146903557', '4397', 'TEF DATANET PR TRANSPORTE ANDINO 30715558889', 47499.00],
            ['22/08/2026', '1147011460', '4397', 'CCERR F SALTA SA 30709876542 CIRC.CERRADO', 95000.00],
            ['26/08/2026', '1147388021', '3310', 'TRF MO CCDO MINISTERIO DE GOBIERNO 30222222229 MISMO', -120000.00],
        ];
    }

    /**
     * Escribe el `.xlsx` con el formato que exporta MacroOnline.
     *
     * Las posiciones no son un orden ideal: la hoja del banco usa celdas
     * combinadas y deja huecos, y `MacroExcelParser` lee por posición. Las
     * fechas van como texto —`dd/mm/aaaa`— y los importes como número con
     * signo, que es exactamente lo que trae el archivo real.
     */
    private function escribirExtracto(): string
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

        /*
         * La fila de encabezados es la que le avisa al parser dónde
         * empieza el cuerpo: `Fecha` en la primera columna y algo escrito
         * en la del importe.
         */
        $texto('A4', 'Fecha');
        $texto('D4', 'Nro. de Referencia');
        $texto('E4', 'Causal');
        $texto('F4', 'Concepto');
        $texto('G4', 'Importe');
        $texto('K4', 'Saldo');

        $saldo = 17692768.55;
        $fila = 5;

        foreach ($this->movimientos() as [$fecha, $referencia, $causal, $concepto, $importe]) {
            $saldo = round($saldo + $importe, 2);

            $texto('A'.$fila, $fecha);
            $texto('D'.$fila, $referencia);
            $texto('E'.$fila, $causal);
            $texto('F'.$fila, $concepto);
            $hoja->setCellValue('G'.$fila, $importe);
            $hoja->setCellValue('K'.$fila, $saldo);

            $fila++;
        }

        $texto('A'.$fila, 'Fecha de descarga: 27/08/2026 09:12:33');
        $texto('A'.($fila + 1), 'Empresa: 30222222229 - MINISTERIO DE GOBIERNO, DERECHOS HUMANOS Y TRABAJO');
        $texto('A'.($fila + 2), 'Operador: ANA MARIA GONZALEZ');

        $carpeta = storage_path('app/demo');

        if (! is_dir($carpeta) && ! mkdir($carpeta, 0o775, true) && ! is_dir($carpeta)) {
            throw new RuntimeException("No se pudo crear {$carpeta}.");
        }

        $archivo = $carpeta.DIRECTORY_SEPARATOR.self::ARCHIVO;

        (new Xlsx($libro))->save($archivo);

        return $archivo;
    }

    private function importar(string $archivo): void
    {
        app(ImportBankStatement::class)->handle(
            file: new UploadedFile($archivo, self::ARCHIVO, null, null, true),
            account: $this->cuenta,
            userId: $this->operador->id,
        );
    }

    /** El movimiento importado de esa fecha y ese importe. */
    private function movimiento(string $fecha, string $importe): BankTransaction
    {
        return BankTransaction::query()
            ->where('bank_account_id', $this->cuenta->id)
            ->whereDate('transaction_date', $fecha)
            ->where('amount', $importe)
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
        ?string $representante = null,
    ): Expediente {
        return Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => $canonical,
            'display_number' => $display,
            'year' => (int) substr($display, -4),
            'received_date' => $recibido,
            'employer_id' => $this->personas[$empleador],
            'employer_role' => 'employer',
            'employer_representative' => $representante,
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
        HaberWorkflowStatus $estado = HaberWorkflowStatus::Active,
        ?string $bloqueo = null,
    ): Haber {
        return $expediente->haberes()->create([
            'concept' => $concepto,
            'beneficiary_id' => $this->personas[$beneficiario],
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => $importe,
            'expected_installment_count' => $cuotas,
            'payment_terms' => $cuotas > 1 ? PaymentTerms::Installments : PaymentTerms::Single,
            'workflow_status' => $estado,
            'block_reason' => $bloqueo,
        ]);
    }

    private function cuota(
        Haber $haber,
        int $numero,
        string $importe,
        ExpectedMedium $medio,
        ?string $etiqueta = null,
        ?string $notas = null,
    ): BeneficiaryInstallment {
        return $haber->installments()->create([
            'installment_number' => $numero,
            'expected_amount' => $importe,
            'management_label_id' => $etiqueta === null
                ? null
                : HaberManagementLabel::query()->where('code', $etiqueta)->value('id'),
            'expected_medium' => $medio,
            'notes' => $notas,
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);
    }

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     */
    private function cobrar(BeneficiaryInstallment $cuota, ?array $cheque = null): void
    {
        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'demo:cobro:'.$cuota->id.':'.uniqid(),
            actorId: $this->operador->id,
            cheque: $cheque,
        );
    }

    /**
     * Cambia el importe sin pasar por `UpdateInstallment`, a propósito.
     *
     * Lo que interesa acá es dejar el **estado** —una cuota cuyo importe
     * dejó de coincidir con lo que se cobró— y el Action de edición
     * arrastra reglas del plan de cuotas que no vienen al caso para armar
     * una muestra. La pantalla que hay que probar es la que reacciona a
     * ese desencuentro, no la que lo produce.
     */
    private function corregirImporte(BeneficiaryInstallment $cuota, string $importe): void
    {
        DB::table('beneficiary_installments')
            ->where('id', $cuota->id)
            ->update(['expected_amount' => $importe, 'updated_at' => now()]);
    }

    /** Un JPEG mínimo en disco, que hace de foto en todos los tickets. */
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
}
