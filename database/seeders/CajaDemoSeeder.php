<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\IssueExpenseReceipt;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\SplitsPersonName;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La primera semana de junio de 2026, tal como la registró el área.
 *
 * **Los importes no son inventados.** Salen de `CAJA HABERES EN
 * CONSIGNACION JUNIO 2026.xlsx`, con los números de talonario reales —72190
 * a 72258 los ingresos, 76396 a 76467 los egresos—. Eso permite abrir la
 * planilla del área y la que genera el sistema una al lado de la otra y
 * comparar renglón por renglón. Un juego de datos con números redondos
 * probaría que el código corre, no que reproduce el trabajo.
 *
 * **Los estados se producen corriendo los Actions reales**, nunca
 * insertando filas. Una cuota «cobrada» escrita a mano dejaría el libro sin
 * asientos, y el primer arqueo encontraría un saldo que el sistema no pudo
 * haber producido. Es el mismo criterio que `EscenariosDemoSeeder`.
 *
 * ─── El único desvío, y por qué ──────────────────────────────────────────
 *
 * El 01/06 la planilla real paga tres millones sin haber cobrado nada ese
 * día: ese dinero entró antes, cuando el sistema no existía. Lo mismo con
 * los cheques, que ya vienen en el saldo inicial.
 *
 * Reproducirlo exigiría `legacy_disbursement`, que está declarado y todavía
 * no tiene Action. Así que **el 01/06 es la costura**: ahí se cobran los
 * cuatro arrastres en efectivo y los cuatro cheques, con talonarios 72186 a
 * 72189 —por debajo del primero real— para que se distingan de un vistazo.
 * La apertura se calcula hacia atrás para que el día cierre en los
 * 6.852.300 que dice el papel.
 *
 * **Del 02/06 en adelante, cada renglón coincide con la planilla.**
 *
 * ─── Lo que queda para mirar ─────────────────────────────────────────────
 *
 * | Día | Qué muestra |
 * |---|---|
 * | 01/06 | La costura: apertura y arrastres |
 * | 02/06 | Nueve ingresos y nueve egresos, el día más movido |
 * | 03/06 | Solo ingresos: el saldo sube y no se paga nada |
 * | 04/06 | El día que más sale, con un arrastre entre medio |
 * | 05/06 | Arqueo **con diferencia real**, revisado y sin imputar |
 *
 * Y el 03/06 lleva un arqueo **parcial**: se cuentan siete millones y medio
 * en billetes y se declaran siete millones en fajos sin recontar. Es lo que
 * el área hace de verdad, y es el caso que `uncounted_amount` existe para
 * poder distinguir de un faltante.
 */
final class CajaDemoSeeder extends Seeder
{
    use SplitsPersonName;

    /** El efectivo que hay que declarar al 31/05 para que el 01/06 cierre donde dice el papel. */
    private const APERTURA_EFECTIVO = '3208250.00';

    /** Las denominaciones que el área usa, de mayor a menor. */
    private const BILLETES = [100_000, 50_000, 20_000, 10_000, 2_000, 1_000, 500, 200, 100, 50];

    private int $caja;

    private User $cajero;

    private User $contadora;

    /** @var array<string, int> */
    private array $personas = [];

    /** @var list<string> */
    private array $empleadores = [];

    /** @var list<string> */
    private array $beneficiarios = [];

    /**
     * Cada cuota indexada por su **número de recibo de ingreso**.
     *
     * Es como la planilla las identifica, y por eso el calendario de
     * jornadas se puede escribir leyéndolo del papel sin traducir nada.
     *
     * @var array<int, BeneficiaryInstallment>
     */
    private array $cuotas = [];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'CajaDemoSeeder reemplaza todos los datos del circuito y solo puede ejecutarse en local o testing.'
            );
        }

        $caja = CashBox::query()->where('code', CashBox::HABERES)->value('id');

        if ($caja === null) {
            throw new RuntimeException('No existe la caja de Haberes. Ejecutá CashBoxSeeder antes.');
        }

        $this->caja = (int) $caja;

        $this->vaciar();
        $this->usuarios();
        $this->personas();
        $this->expedientes();

        /*
         * El reloj viaja al día que se está registrando. Sin esto todos
         * los Actions fecharían sus eventos hoy y el circuito entero caería
         * fuera de junio: el arqueo compararía contra un saldo que no
         * corresponde y el cierre no encontraría un solo movimiento.
         */
        $this->abrirLibros();

        foreach ($this->jornadas() as $fecha => $jornada) {
            $this->jornada(CarbonImmutable::parse($fecha), $jornada);
        }

        CarbonImmutable::setTestNow();

        $this->informe();
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
     * `finally`: son los que impiden borrar hechos monetarios, arqueos
     * cerrados y períodos congelados. Acá se está tirando todo a propósito,
     * y fuera de desarrollo este método no se ejecuta.
     */
    private function vaciar(): void
    {
        $tablas = [
            'cash_count_lines',
            'cash_counts',
            'period_closings',
            'cash_to_bank_transfer_items',
            'cash_to_bank_transfers',
            'disbursements',
            'pases',
            'payment_order_funding_sources',
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

    /*
    |--------------------------------------------------------------------------
    | Gente
    |--------------------------------------------------------------------------
    */

    /**
     * Dos usuarios, y no es decoración.
     *
     * `ReviewCashCount` rechaza que revise quien contó: un arqueo que se
     * aprueba solo no controla nada. Con un único usuario el seeder no
     * podría producir un arqueo revisado, que es justamente el estado que
     * hace falta para cerrar el día.
     */
    private function usuarios(): void
    {
        $this->cajero = User::query()->orderBy('id')->firstOrFail();

        $this->contadora = User::query()->firstOrCreate(
            ['username' => 'demo.contadora'],
            [
                'first_name' => 'Contadora',
                'last_name' => 'De Prueba',
                'email' => 'contadora@example.test',
                'document_number' => '20987654',
                'password' => 'Contrasena.Segura.2026',
            ],
        );

        if (! $this->contadora->hasRole('contador')) {
            $this->contadora->assignRole('contador');
        }
    }

    /**
     * Cuatro empleadores y veintidós beneficiarios.
     *
     * Nombres anonimizados; los importes y los números de cheque son los de
     * la planilla. El **rol va en su propia fila** porque la FK compuesta de
     * `expedientes` y `haberes` apunta ahí: es lo que impide cargar a un
     * beneficiario en el lugar del empleador.
     */
    private function personas(): void
    {
        $empleadores = [
            ['Constructora del Norte SRL', '30711234567'],
            ['Servicios Integrales SA', '30698765432'],
            ['Agropecuaria San José SRL', '30655544433'],
            ['Transportes del Valle SA', '30612398745'],
        ];

        $beneficiarios = [
            ['Acosta, Ramón Elías', '28555111'],
            ['Benítez, Marisa Noemí', '30123456'],
            ['Cabrera, Julio César', '22333444'],
            ['Domínguez, Silvia Rosa', '32444555'],
            ['Escobar, Hugo Alberto', '26555666'],
            ['Figueroa, Nélida Beatriz', '28888999'],
            ['Gómez, Ricardo Ariel', '33444555'],
            ['Herrera, Ana Lucía', '35666777'],
            ['Ibarra, Miguel Ángel', '24111222'],
            ['Juárez, Patricia Elena', '31222333'],
            ['López, Sergio Daniel', '27888999'],
            ['Maidana, Claudia Vanesa', '33999000'],
            ['Núñez, Fabián Osvaldo', '25666777'],
            ['Ortiz, Graciela Mabel', '29555111'],
            ['Paz, Walter Rubén', '30111222'],
            ['Quiroga, Sandra Beatriz', '26777888'],
            ['Ramírez, Oscar Antonio', '31555666'],
            ['Sosa, Verónica Andrea', '34333444'],
            ['Torres, Néstor Fabián', '22999888'],
            ['Vera, Mónica Alejandra', '35888999'],
            ['Zárate, Diego Martín', '27222333'],
            ['Almirón, Rubén Darío', '23444555'],
        ];

        foreach ($empleadores as [$nombre, $documento]) {
            $this->persona($nombre, $documento, 'company', 'employer');
            $this->empleadores[] = $nombre;
        }

        foreach ($beneficiarios as [$nombre, $documento]) {
            $this->persona($nombre, $documento, 'individual', 'beneficiary');
            $this->beneficiarios[] = $nombre;
        }
    }

    private function persona(string $nombre, string $documento, string $tipo, string $rol): void
    {
        $persona = Person::query()->firstOrCreate(
            ['document' => $documento],
            ['type' => $tipo, ...$this->nameColumns($tipo, $nombre), 'is_active' => true],
        );

        DB::table('person_roles')->insertOrIgnore([
            'person_id' => $persona->id,
            'role' => $rol,
            'person_type' => $tipo,
            'created_at' => now(),
        ]);

        $this->personas[$nombre] = (int) $persona->id;
    }

    /*
    |--------------------------------------------------------------------------
    | El expediente de cada cuota
    |--------------------------------------------------------------------------
    */

    /**
     * Un expediente por empleador, con un haber por beneficiario.
     *
     * La clave de cada cuota es su **número de recibo de ingreso**, que es
     * como la planilla las identifica y lo que después permite escribir el
     * calendario de jornadas leyéndolo del papel sin traducir nada.
     */
    private function expedientes(): void
    {
        $empleadores = $this->empleadores;
        $beneficiarios = $this->beneficiarios;
        $expedientes = [];

        foreach ($empleadores as $i => $empleador) {
            $numero = sprintf('%d/2026', 131000 + $i);

            $expedientes[$empleador] = Expediente::query()->create([
                'source_system' => 'SiCE v3.0',
                'canonical_number' => str_replace('/', '', $numero),
                'display_number' => $numero,
                'year' => 2026,
                'received_date' => '2026-05-04',
                'employer_id' => $this->personas[$empleador],
                'employer_role' => 'employer',
                'subject' => 'Haberes en consignación',
                'status' => ExpedienteStatus::Active,
            ]);
        }

        $i = 0;

        foreach ($this->catalogo() as $recibo => [$importe, $medio]) {
            $empleador = $empleadores[$i % count($empleadores)];
            $beneficiario = $beneficiarios[$i % count($beneficiarios)];
            $i++;

            $haber = $expedientes[$empleador]->haberes()->create([
                'concept' => 'Indemnización',
                'beneficiary_id' => $this->personas[$beneficiario],
                'beneficiary_role' => 'beneficiary',
                'assigned_amount' => $importe,
                'expected_installment_count' => 1,
                'payment_terms' => PaymentTerms::Single,
                'workflow_status' => HaberWorkflowStatus::Active,
            ]);

            $this->cuotas[(int) $recibo] = $haber->installments()->create([
                'installment_number' => 1,
                'expected_amount' => $importe,
                'expected_medium' => $medio,
                'workflow_status' => InstallmentWorkflowStatus::Active,
            ]);
        }
    }

    /**
     * Cada recibo de ingreso con su importe y su medio.
     *
     * Los 72186 a 72189 son la costura del 01/06 —dinero que entró antes de
     * que el sistema existiera—; el resto sale de la planilla tal cual.
     *
     * @return array<int, array{0: string, 1: ExpectedMedium}>
     */
    private function catalogo(): array
    {
        return [
            // ── La costura del 01/06 ──
            72186 => ['3000000.00', ExpectedMedium::Cash],
            72187 => ['1000000.00', ExpectedMedium::Cash],
            72188 => ['210050.00', ExpectedMedium::Cash],
            72189 => ['2434000.00', ExpectedMedium::Cash],
            // Los cuatro cheques del reverso, con sus importes reales.
            72181 => ['50000.00', ExpectedMedium::Cheque],
            72182 => ['50000.00', ExpectedMedium::Cheque],
            72183 => ['16144.70', ExpectedMedium::Cheque],
            72184 => ['557660.00', ExpectedMedium::Cheque],

            // ── 02/06 ──
            72190 => ['500000.00', ExpectedMedium::Cash],
            72191 => ['618200.00', ExpectedMedium::Cash],
            72193 => ['1667000.00', ExpectedMedium::Cash],
            72194 => ['700000.00', ExpectedMedium::Cash],
            72195 => ['3833400.00', ExpectedMedium::Cash],
            72196 => ['5167000.00', ExpectedMedium::Cash],
            72197 => ['10500000.00', ExpectedMedium::Cash],
            72198 => ['2000000.00', ExpectedMedium::Cash],

            // ── 03/06 ──
            72199 => ['1400000.00', ExpectedMedium::Cash],
            72200 => ['1400000.00', ExpectedMedium::Cash],
            72251 => ['5000000.00', ExpectedMedium::Cash],

            // ── 04/06 ──
            72252 => ['1200000.00', ExpectedMedium::Cash],
            72253 => ['2000000.00', ExpectedMedium::Cash],
            72254 => ['900000.00', ExpectedMedium::Cash],
            72255 => ['9000000.00', ExpectedMedium::Cash],
            72256 => ['845000.00', ExpectedMedium::Cash],
            72257 => ['1500000.00', ExpectedMedium::Cash],

            // ── 05/06 ──
            72258 => ['300000.00', ExpectedMedium::Cash],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Las jornadas
    |--------------------------------------------------------------------------
    */

    /**
     * Qué pasa cada día: qué se cobra, qué se paga y cómo se arquea.
     *
     * `pagos` empareja el número de recibo de egreso con el de ingreso de
     * la cuota que salda. Es el cruce que la planilla no escribe y que hace
     * falta para reconstruirla: el papel muestra dos columnas de números
     * sin decir cuál paga a cuál.
     *
     * @return array<string, array{cobros: list<int>, pagos: array<int, int>, arqueo: array{sinRecontar?: numeric-string, motivo?: string, faltante?: numeric-string, explicacion?: string}}>
     */
    private function jornadas(): array
    {
        return [
            '2026-06-01' => [
                'cobros' => [72186, 72187, 72188, 72189, 72181, 72182, 72183, 72184],
                'pagos' => [76396 => 72186],
                'arqueo' => [],
            ],
            '2026-06-02' => [
                'cobros' => [72190, 72191, 72193, 72194, 72195, 72196, 72197, 72198],
                'pagos' => [
                    76397 => 72187,
                    76398 => 72194,
                    76399 => 72188,
                    76451 => 72196,
                    76452 => 72195,
                    76453 => 72197,
                    76454 => 72193,
                    76455 => 72198,
                ],
                'arqueo' => [],
            ],
            '2026-06-03' => [
                'cobros' => [72199, 72200, 72251],
                'pagos' => [],
                /*
                 * El arqueo parcial, que es el caso que justifica la
                 * columna: cuadra sin haberse contado entero.
                 */
                'arqueo' => [
                    'sinRecontar' => '7000000.00',
                    'motivo' => 'Fajos precintados del día anterior, no recontados.',
                ],
            ],
            '2026-06-04' => [
                'cobros' => [72252, 72253, 72254, 72255, 72256, 72257],
                'pagos' => [
                    76456 => 72189,
                    76457 => 72252,
                    76458 => 72199,
                    76459 => 72200,
                    76460 => 72251,
                    76461 => 72255,
                    76462 => 72256,
                    76463 => 72257,
                ],
                'arqueo' => [],
            ],
            '2026-06-05' => [
                'cobros' => [72258],
                'pagos' => [
                    76464 => 72258,
                    76465 => 72190,
                    76466 => 72191,
                    76467 => 72253,
                ],
                /*
                 * Y una diferencia de verdad, revisada y **sin imputar**:
                 * es el estado en el que el área decide qué hacer. Imputarla
                 * movería `CASH_ON_HAND` y el saldo dejaría de coincidir con
                 * el papel.
                 */
                'arqueo' => [
                    'faltante' => '100.00',
                    'explicacion' => 'Faltan cien pesos contra el libro. Se revisa el vuelto del último pago.',
                ],
            ],
        ];
    }

    /** @param  array{cobros: list<int>, pagos: array<int, int>, arqueo: array<string, string>}  $jornada */
    private function jornada(CarbonImmutable $fecha, array $jornada): void
    {
        CarbonImmutable::setTestNow($fecha->setTime(10, 0));

        foreach ($jornada['cobros'] as $recibo) {
            $this->cobrar($recibo, $fecha);
        }

        foreach ($jornada['pagos'] as $reciboEgreso => $reciboIngreso) {
            $this->pagar($reciboIngreso, $reciboEgreso, $fecha);
        }

        $this->arquear($fecha, $jornada['arqueo']);

        app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja,
            date: $fecha,
            actorId: $this->contadora->id,
        );
    }

    private function cobrar(int $recibo, CarbonImmutable $fecha): void
    {
        $cuota = $this->cuotas[$recibo];

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'demo:caja:cobro:'.$recibo,
            actorId: $this->cajero->id,
            talonarioNumber: (string) $recibo,
            printsTalonarioNumber: true,
            receivedDate: $fecha,
            cheque: $this->cheque($recibo),
        );
    }

    private function pagar(int $reciboIngreso, int $reciboEgreso, CarbonImmutable $fecha): void
    {
        $cuota = $this->cuotas[$reciboIngreso]->fresh();

        $egreso = app(DeliverToBeneficiary::class)->handle(
            installment: $cuota,
            idempotencyKey: 'demo:caja:egreso:'.$reciboEgreso,
            actorId: $this->cajero->id,
            paymentDate: $fecha,
        );

        app(IssueExpenseReceipt::class)->handle(
            installment: $cuota,
            disbursement: $egreso,
            actorId: $this->cajero->id,
            talonarioNumber: (string) $reciboEgreso,
            issueDate: $fecha,
            printsTalonarioNumber: true,
        );
    }

    /**
     * Los cuatro cheques del reverso de la planilla, con sus datos reales.
     *
     * @return array{number: string, bank: string|null, issueDate: string|null}|null
     */
    private function cheque(int $recibo): ?array
    {
        return match ($recibo) {
            72181 => ['number' => '61197673', 'bank' => 'CREDICOOP', 'issueDate' => '2023-06-06'],
            72182 => ['number' => '61197674', 'bank' => 'CREDICOOP', 'issueDate' => '2023-06-06'],
            72183 => ['number' => '61197677', 'bank' => 'CREDICOOP', 'issueDate' => '2023-06-06'],
            72184 => ['number' => '53732213', 'bank' => 'MACRO', 'issueDate' => '2024-02-12'],
            default => null,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Apertura y arqueo
    |--------------------------------------------------------------------------
    */

    /**
     * El saldo que ya estaba en el cajón cuando el sistema arrancó.
     *
     * Sin este asiento el saldo teórico empieza en cero y el primer arqueo
     * da una diferencia igual a todo el saldo histórico: un número que
     * nadie puede explicar porque no corresponde a ningún hecho.
     */
    private function abrirLibros(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-31')->setTime(9, 0));

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja,
            balances: [LedgerAccount::CashOnHand->value => self::APERTURA_EFECTIVO],
            // Los 3.208.250 de la apertura, desarmados como salió del cajón.
            denominations: [100_000 => 32, 2_000 => 4, 200 => 1, 50 => 1],
            date: CarbonImmutable::parse('2026-05-31'),
            actorId: $this->cajero->id,
            notes: 'Efectivo existente al arranque del sistema.',
        );
    }

    /** @param  array<string, string>  $receta */
    private function arquear(CarbonImmutable $fecha, array $receta): void
    {
        $enCaja = app(CashBalance::class)->of(
            LedgerAccount::CashOnHand,
            $this->caja,
            upTo: $fecha,
        );

        $sinRecontar = $receta['sinRecontar'] ?? '0.00';
        $faltante = $receta['faltante'] ?? '0.00';

        /*
         * Lo que se cuenta en billetes es el saldo menos lo que se declara
         * sin recontar, y menos el faltante si el día tiene uno. Así el
         * desglose por denominación siempre suma exactamente lo que la
         * cabecera dice, que es lo que el trigger diferido exige.
         */
        $aContar = Decimal::sub(Decimal::sub($enCaja, $sinRecontar), $faltante);

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja,
            countedOn: $fecha,
            denominations: $this->desglosar($aContar),
            actorId: $this->cajero->id,
            uncountedAmount: $sinRecontar,
            explanation: $receta['explicacion'] ?? null,
        );

        app(ReviewCashCount::class)->handle($arqueo, $this->contadora->id);
    }

    /**
     * Un importe repartido en billetes, de mayor a menor.
     *
     * Es lo que la contadora escribe en el reverso. El reparto codicioso
     * termina exacto porque todos los importes del área bajan hasta el
     * billete de cincuenta y ninguno lleva centavos sueltos en efectivo.
     *
     * @return array<int, int>
     */
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
                "El importe {$importe} no se puede formar con los billetes que el área usa: sobran {$restante}."
            );
        }

        return $desglose;
    }

    private function informe(): void
    {
        $cierres = DB::table('period_closings')
            ->where('cash_box_id', $this->caja)
            ->orderBy('period_from')
            ->get(['period_from', 'closing_cash', 'closing_cheques']);

        $this->command->info('Junio 2026 — primera semana, contra la planilla del área:');

        foreach ($cierres as $cierre) {
            $this->command->line(sprintf(
                '  %s  efectivo %14s   cheques %12s',
                $cierre->period_from,
                number_format((float) $cierre->closing_cash, 2, ',', '.'),
                number_format((float) $cierre->closing_cheques, 2, ',', '.'),
            ));
        }

        $this->command->info('Usuario contadora: demo.contadora / Contrasena.Segura.2026');
    }
}
