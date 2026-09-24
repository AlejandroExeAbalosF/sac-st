<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\User;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\ExpedienteStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CarryRecount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

/**
 * La planilla de caja, generada.
 *
 * Lo que se prueba no es que el archivo exista: es que **las celdas digan
 * lo que el papel del área dice**. Los tests abren el `.xlsx` producido y
 * leen las celdas, que es la única forma de saber que el Excel sirve — un
 * archivo que se genera sin errores y sale con los números en el lugar
 * equivocado pasa cualquier test que solo mire que haya un adjunto.
 */
class PlanillaDeCajaExcelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin series no se puede emitir un comprobante.
        Storage::fake('local');
    }

    public function test_el_anverso_reproduce_la_planilla_del_2_de_junio(): void
    {
        $adjunto = app(ExportCashSheet::class)->handle($this->cierreDel2DeJunio());

        $hoja = $this->abrir($adjunto->object_key)->getSheetByName('CAJA 020626');

        $this->assertNotNull($hoja, 'La hoja tiene que llamarse como la que el área ya usa.');

        $this->assertStringContainsString('02/06/2026', (string) $hoja->getCell('A1')->getValue());

        $this->assertSame('RECIBO Nº', $hoja->getCell('A4')->getValue());
        $this->assertSame('EFECTIVO', $hoja->getCell('B4')->getValue());
        $this->assertSame('CHEQUES', $hoja->getCell('C4')->getValue());
        $this->assertSame('DEPOSITOS DIRECTOS', $hoja->getCell('D4')->getValue());

        $this->assertSame('SALDO INICIAL', $hoja->getCell('A5')->getValue());
        $this->assertSame(6852300.0, (float) $hoja->getCell('B5')->getValue());
        $this->assertSame(673804.70, (float) $hoja->getCell('C5')->getValue());

        $filas = $this->rotulos($hoja);

        $this->assertSame(24985600.0, (float) $hoja->getCell('B'.$filas['INGRESOS'])->getValue());
        $this->assertSame(25077450.0, (float) $hoja->getCell('B'.$filas['EGRESOS'])->getValue());

        // El número que el área lee primero.
        $this->assertSame(6760450.0, (float) $hoja->getCell('B'.$filas['SALDO FINAL'])->getValue());
        $this->assertSame(673804.70, (float) $hoja->getCell('C'.$filas['SALDO FINAL'])->getValue());
    }

    /**
     * La apertura del mismo día va al SALDO INICIAL, no a los ingresos.
     *
     * **Es lo normal el día que el sistema arranca**: se abren los libros
     * y se opera esa misma jornada. El cierre calculaba la apertura como
     * el saldo del libro a la víspera —cero— y el asiento de apertura
     * caía en la fila de ingresos, así que la planilla salía con «SALDO
     * INICIAL $ 0,00» y ocho millones colgando de «INGRESOS».
     *
     * El saldo final no cambia: cambia de qué renglón sale.
     */
    public function test_una_apertura_del_mismo_dia_sale_como_saldo_inicial(): void
    {
        $this->abrirLibros(fecha: '2026-06-02');
        $this->cobrar('500000.00', '2026-06-02');

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        $this->assertSame('6852300.00', $cierre->opening_cash);
        $this->assertSame('673804.70', $cierre->opening_cheques);
        $this->assertSame('500000.00', $cierre->received_cash);
        $this->assertSame('7352300.00', $cierre->closing_cash);

        $adjunto = app(ExportCashSheet::class)->handle($cierre);
        $hoja = $this->abrir($adjunto->object_key)->getSheetByName('CAJA 020626');

        $this->assertNotNull($hoja);
        $this->assertSame('SALDO INICIAL', $hoja->getCell('A5')->getValue());
        $this->assertSame(6852300.0, (float) $hoja->getCell('B5')->getValue());
        $this->assertSame(673804.70, (float) $hoja->getCell('C5')->getValue());

        $filas = $this->rotulos($hoja);

        $this->assertSame(500000.0, (float) $hoja->getCell('B'.$filas['INGRESOS'])->getValue());
        $this->assertSame(7352300.0, (float) $hoja->getCell('B'.$filas['SALDO FINAL'])->getValue());
    }

    public function test_el_reverso_trae_el_conteo_por_denominacion(): void
    {
        $cierre = $this->cierreDel30DeJunioConArqueo();

        $adjunto = app(ExportCashSheet::class)->handle($cierre);

        $hoja = $this->abrir($adjunto->object_key)->getSheetByName('REVERSO 300626');

        $this->assertNotNull($hoja);
        $this->assertStringContainsString('RENDICION DEL EFECTIVO', (string) $hoja->getCell('A4')->getValue());
        $this->assertSame('CANTIDAD', $hoja->getCell('A5')->getValue());

        /*
         * Las diez denominaciones van siempre y en el mismo orden, haya
         * billetes o no: quien firma la rendición busca el renglón de los
         * diez mil en el mismo lugar que ayer. Por eso los 20.000 caen en
         * la fila 8 y no en la 6, aunque sean el primero que se contó.
         */
        $this->assertSame(100000.0, (float) $hoja->getCell('B6')->getValue());
        $this->assertSame('', (string) $hoja->getCell('A6')->getValue());
        $this->assertSame(0.0, (float) $hoja->getCell('C6')->getValue());

        $this->assertSame(101, (int) $hoja->getCell('A8')->getValue());
        $this->assertSame(20000.0, (float) $hoja->getCell('B8')->getValue());
        $this->assertSame(2020000.0, (float) $hoja->getCell('C8')->getValue());

        $this->assertSame(50.0, (float) $hoja->getCell('B15')->getValue());

        $filas = $this->rotulos($hoja);

        $this->assertSame(2034800.0, (float) $hoja->getCell('C'.$filas['RECAUDACION DEL DIA'])->getValue());

        /*
         * El renglón que el papel llama «SALDO DIA ANTERIOR» sale con ese
         * nombre y con el nuestro al lado. Es el punto entero de la
         * columna: el que lea la planilla sabe que esos 743.050 no se
         * contaron.
         */
        $rotulo = 'SALDO DIA ANTERIOR (no recontado)';

        $this->assertArrayHasKey($rotulo, $filas);
        $this->assertSame(743050.0, (float) $hoja->getCell('C'.$filas[$rotulo])->getValue());
    }

    /** El cuadro de billetes corresponde solo a la recaudación del día. */
    public function test_el_reverso_separa_del_cuadro_el_fajo_recontado(): void
    {
        $cierre = $this->cierreDel30DeJunioConFajoRecontado();

        $adjunto = app(ExportCashSheet::class)->handle($cierre);

        $hoja = $this->abrir($adjunto->object_key)->getSheetByName('REVERSO 300626');

        $this->assertNotNull($hoja);

        // Los 37 billetes del fajo no se mezclan con los 101 del día.
        $this->assertSame(101, (int) $hoja->getCell('A8')->getValue());
        $this->assertSame(2020000.0, (float) $hoja->getCell('C8')->getValue());

        // Lo mismo para los tres billetes de mil del saldo anterior.
        $this->assertSame(4, (int) $hoja->getCell('A11')->getValue());
        $this->assertSame(4000.0, (float) $hoja->getCell('C11')->getValue());

        // El billete de 50 estaba solo en el fajo y no aparece en el cuadro.
        $this->assertSame('', (string) $hoja->getCell('A15')->getValue());
        $this->assertSame(0.0, (float) $hoja->getCell('C15')->getValue());

        $filas = $this->rotulos($hoja);

        // Los importes conservan la misma separación que el cuadro.
        $this->assertSame(2034800.0, (float) $hoja->getCell('C'.$filas['RECAUDACION DEL DIA'])->getValue());
        $this->assertSame(
            743050.0,
            (float) $hoja->getCell('C'.$filas['SALDO DIA ANTERIOR (recontado)'])->getValue(),
        );
        $this->assertSame(
            2777850.0,
            (float) $hoja->getCell('C'.$filas['TOTAL CAJA HABERES EN CONSIGNACIÓN'])->getValue(),
        );
        $this->assertArrayNotHasKey('SALDO DIA ANTERIOR (no recontado)', $filas);
    }

    /**
     * El inventario de cheques, con las columnas que lo identifican.
     *
     * La primera versión unía el cheque con su recibo por el evento de
     * **recepción**, y el recibo cuelga del de **asignación**: el total
     * salía bien y las cuatro primeras columnas en blanco. Un reverso que
     * lista importes sin decir de quién es cada cheque no sirve para
     * inventariar nada, y ningún test lo notaba porque solo se miraba el
     * total.
     */
    public function test_el_reverso_identifica_cada_cheque_en_custodia(): void
    {
        $cuota = $this->cuotaConCheque();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota,
            idempotencyKey: 'test:cheque',
            talonarioNumber: '64685',
            printsTalonarioNumber: true,
            receivedDate: CarbonImmutable::parse('2026-06-02'),
            cheque: ['number' => '61197673', 'bank' => 'CREDICOOP', 'issueDate' => '2023-06-06'],
        );

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        $hoja = $this->abrir(app(ExportCashSheet::class)->handle($cierre)->object_key)
            ->getSheetByName('REVERSO 020626');

        $this->assertNotNull($hoja);

        $fila = $this->rotulos($hoja)['RECIBO'] + 1;

        $this->assertSame('64685', (string) $hoja->getCell('A'.$fila)->getValue());
        $this->assertSame('131010/2023', (string) $hoja->getCell('B'.$fila)->getValue());
        $this->assertStringContainsString('COBERTURA', (string) $hoja->getCell('C'.$fila)->getValue());
        $this->assertStringContainsString('Tintilay', (string) $hoja->getCell('D'.$fila)->getValue());
        $this->assertSame('61197673', (string) $hoja->getCell('E'.$fila)->getValue());
        $this->assertSame('CREDICOOP', (string) $hoja->getCell('F'.$fila)->getValue());
        $this->assertSame(50000.0, (float) $hoja->getCell('H'.$fila)->getValue());
    }

    /**
     * Un cheque cargado en la apertura también se identifica.
     *
     * No tiene recibo emitido ni cuota asignada —estaba en el cajón antes
     * del sistema—, así que sus tres columnas salen de lo que declaró
     * quien abrió los libros. Sin esto el reverso listaba un importe sin
     * decir de quién era, y un cheque puede quedar años en custodia: sería
     * así en todas las planillas hasta que se cobre.
     */
    public function test_el_reverso_identifica_un_cheque_cargado_en_la_apertura(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [
                LedgerAccount::CashOnHand->value => '6852300.00',
                LedgerAccount::ChequesInCustody->value => '607660.00',
            ],
            denominations: $this->billetesPara('6852300.00'),
            date: CarbonImmutable::parse('2026-06-01'),
            cheques: [
                [
                    'number' => '61197677',
                    'bank' => 'CREDICOOP',
                    'issueDate' => '2023-06-06',
                    'amount' => '50000.00',
                    'expediente' => '131010/2023',
                    'company' => 'COBERTURA DE SALUD SA',
                    'beneficiary' => 'TINTILAY TOLABA HECTOR',
                ],
                [
                    'number' => '53732213',
                    'bank' => 'MACRO',
                    'issueDate' => '2024-02-12',
                    'amount' => '557660.00',
                    'expediente' => '233663/2024',
                    'company' => 'FMF ARGENTINA SRL',
                    'beneficiary' => 'VALLEJOS BRIAN JOEL',
                ],
            ],
        );

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        $cierre = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );

        $hoja = $this->abrir(app(ExportCashSheet::class)->handle($cierre)->object_key)
            ->getSheetByName('REVERSO 020626');

        $this->assertNotNull($hoja);

        $fila = $this->rotulos($hoja)['RECIBO'] + 1;

        // Sin recibo emitido, la primera columna queda vacía a propósito.
        $this->assertSame('', (string) $hoja->getCell('A'.$fila)->getValue());
        $this->assertSame('131010/2023', (string) $hoja->getCell('B'.$fila)->getValue());
        $this->assertSame('COBERTURA DE SALUD SA', (string) $hoja->getCell('C'.$fila)->getValue());
        $this->assertSame('TINTILAY TOLABA HECTOR', (string) $hoja->getCell('D'.$fila)->getValue());
        $this->assertSame('61197677', (string) $hoja->getCell('E'.$fila)->getValue());
        $this->assertSame(50000.0, (float) $hoja->getCell('H'.$fila)->getValue());

        $this->assertSame('53732213', (string) $hoja->getCell('E'.($fila + 1))->getValue());
        $this->assertSame(557660.0, (float) $hoja->getCell('H'.($fila + 1))->getValue());
    }

    /** La cartera detallada tiene que sumar el saldo declarado. */
    public function test_la_apertura_rechaza_cheques_que_no_suman_su_saldo(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tienen que coincidir');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [
                LedgerAccount::CashOnHand->value => '6852300.00',
                LedgerAccount::ChequesInCustody->value => '673804.70',
            ],
            denominations: $this->billetesPara('6852300.00'),
            date: CarbonImmutable::parse('2026-06-01'),
            cheques: [[
                'number' => '61197677',
                'bank' => 'CREDICOOP',
                'issueDate' => '2023-06-06',
                'amount' => '50000.00',
            ]],
        );
    }

    public function test_la_planilla_queda_como_evidencia_y_no_se_regenera(): void
    {
        $cierre = $this->cierreDel2DeJunio();

        $primera = app(ExportCashSheet::class)->handle($cierre);
        $segunda = app(ExportCashSheet::class)->handle($cierre);

        $this->assertSame(
            $primera->id,
            $segunda->id,
            'Reexportar tiene que devolver el archivo que se firmó, no uno nuevo.'
        );

        $this->assertSame(AttachmentSubject::PeriodClosing, $primera->subject_type);
        $this->assertSame(AttachmentSource::Generated, $primera->source);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $primera->sha256);

        Storage::disk('local')->assertExists($primera->object_key);
    }

    public function test_volver_a_cerrar_emite_una_planilla_nueva_y_conserva_la_anterior(): void
    {
        $cierre = $this->cierreDel2DeJunio();
        $contador = User::factory()->create();

        $primera = app(ExportCashSheet::class)->handle($cierre);

        app(ReopenPeriod::class)->handle($cierre, $contador->id, 'Faltó el recibo 76399.');

        // Un movimiento que el primer cierre no vio.
        $this->cobrar('210050.00', '2026-06-02');

        try {
            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse('2026-06-02'),
                actorId: $contador->id,
            );
            $this->fail('El cierre anterior se reutilizó sin volver a contar el cajón.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period', $e->errors());
        }

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02', $contador);

        $recerrado = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
            actorId: $contador->id,
        );

        $segunda = app(ExportCashSheet::class)->handle($recerrado);

        $this->assertNotSame(
            $primera->id,
            $segunda->id,
            'El segundo cierre tiene otro snapshot: devolver la planilla vieja entregaría números que ya no son.'
        );

        // Y la primera sigue existiendo: pudo imprimirse y firmarse.
        $this->assertTrue($primera->exists());
        Storage::disk('local')->assertExists($primera->object_key);

        $hoja = $this->abrir($segunda->object_key)->getSheetByName('CAJA 020626');
        $this->assertNotNull($hoja);
        $this->assertSame(
            25195650.0,
            (float) $hoja->getCell('B'.$this->rotulos($hoja)['INGRESOS'])->getValue(),
        );
    }

    public function test_no_se_emite_la_planilla_de_un_periodo_que_no_esta_cerrado(): void
    {
        $this->abrirLibros();

        $cierre = new PeriodClosing([
            'cash_box_id' => $this->caja(),
            'period_type' => PeriodType::Daily,
            'period_from' => CarbonImmutable::parse('2026-06-02'),
            'period_to' => CarbonImmutable::parse('2026-06-02'),
        ]);

        $this->expectException(ValidationException::class);

        app(ExportCashSheet::class)->handle($cierre);
    }

    public function test_el_cierre_mensual_arma_el_libro_del_mes(): void
    {
        $this->abrirLibros(fecha: '2026-05-31');

        $this->cobrar('24985600.00', '2026-06-02');
        $this->pagar('25077450.00', '2026-06-02');

        /*
         * El 01/06 se cierra a propósito: su hoja se llamaría `CAJA 010626`
         * y el cierre mensual arranca el mismo día. Si el mensual no
         * llevara un nombre propio, el libro tendría dos hojas homónimas y
         * Excel no lo guardaría.
         */
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $dia) {
            $this->arqueoListoParaCerrar($this->caja(), $dia);

            app(ClosePeriod::class)->handle(
                cashBoxId: $this->caja(),
                date: CarbonImmutable::parse($dia),
            );
        }

        $mensual = app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-15'),
            type: PeriodType::Monthly,
        );

        $libro = $this->abrir(app(ExportCashSheet::class)->handle($mensual)->object_key);

        $hojas = $libro->getSheetNames();

        // Los tres días cerrados más la hoja del mes, en orden.
        $this->assertContains('CAJA 010626', $hojas);
        $this->assertContains('CAJA 020626', $hojas);
        $this->assertContains('CAJA 030626', $hojas);
        $this->assertSame('CAJA MES 0626', $hojas[array_key_last($hojas)]);

        $this->assertSame(
            count($hojas),
            count(array_unique($hojas)),
            'Dos hojas con el mismo nombre harían fallar el guardado del libro entero.'
        );

        // Y la hoja del mes acumula: los movimientos cayeron el 02/06.
        $mes = $libro->getSheetByName('CAJA MES 0626');
        $this->assertNotNull($mes);
        $this->assertSame(24985600.0, (float) $mes->getCell('B'.$this->rotulos($mes)['INGRESOS'])->getValue());
    }

    // ─────────────────────────── Andamiaje ───────────────────────────

    private function abrir(string $objectKey): Spreadsheet
    {
        return IOFactory::load(Storage::disk('local')->path($objectKey));
    }

    /**
     * Dónde quedó cada fila de totales.
     *
     * Los bloques de detalle crecen con los datos, así que la fila de
     * `SALDO FINAL` no está siempre en la 48 como en el papel preimpreso.
     * Buscarla por su rótulo es lo que hace que el test siga sirviendo
     * cuando un día tenga treinta recibos.
     *
     * @return array<string, int>
     */
    private function rotulos(Worksheet $hoja): array
    {
        $filas = [];

        for ($fila = 1; $fila <= $hoja->getHighestRow(); $fila++) {
            $rotulo = trim((string) $hoja->getCell('A'.$fila)->getValue());

            if ($rotulo !== '') {
                $filas[$rotulo] = $fila;
            }
        }

        return $filas;
    }

    /**
     * Un expediente con un haber cobrable por cheque.
     *
     * Los datos son los del reverso real: expediente 131010/2023,
     * Cobertura de Salud SA, cheque 61197673 del Credicoop por 50.000.
     */
    private function cuotaConCheque(): BeneficiaryInstallment
    {
        $empresa = Person::query()->create([
            'type' => 'company', 'legal_name' => 'COBERTURA DE SALUD SA',
            'document' => '30687654321', 'is_active' => true,
        ]);
        $trabajador = Person::query()->create([
            'type' => 'individual', 'first_name' => 'Héctor', 'last_name' => 'Tintilay Tolaba',
            'document' => '18455233', 'is_active' => true,
        ]);

        foreach ([[$empresa, 'employer', 'company'], [$trabajador, 'beneficiary', 'individual']] as [$p, $rol, $tipo]) {
            DB::table('person_roles')->insertOrIgnore([
                'person_id' => $p->id, 'role' => $rol, 'person_type' => $tipo, 'created_at' => now(),
            ]);
        }

        $expediente = Expediente::query()->create([
            'source_system' => 'SiCE v3.0',
            'canonical_number' => '1310102023',
            'display_number' => '131010/2023',
            'year' => 2023,
            'received_date' => '2023-06-01',
            'employer_id' => $empresa->id,
            'employer_role' => 'employer',
            'subject' => 'Haberes en consignación',
            'status' => ExpedienteStatus::Active,
        ]);

        $haber = $expediente->haberes()->create([
            'concept' => 'Indemnización',
            'beneficiary_id' => $trabajador->id,
            'beneficiary_role' => 'beneficiary',
            'assigned_amount' => '50000.00',
            'expected_installment_count' => 1,
            'payment_terms' => PaymentTerms::Single,
            'workflow_status' => HaberWorkflowStatus::Active,
        ]);

        return $haber->installments()->create([
            'installment_number' => 1,
            'expected_amount' => '50000.00',
            'expected_medium' => ExpectedMedium::Cheque,
            'workflow_status' => InstallmentWorkflowStatus::Active,
        ]);
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(string $fecha = '2026-06-01'): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [
                LedgerAccount::CashOnHand->value => '6852300.00',
                LedgerAccount::ChequesInCustody->value => '673804.70',
            ],
            denominations: $this->billetesPara('6852300.00'),
            date: CarbonImmutable::parse($fecha),
        );
    }

    private function cierreDel2DeJunio(): PeriodClosing
    {
        $this->abrirLibros();

        $this->cobrar('24985600.00', '2026-06-02');
        $this->pagar('25077450.00', '2026-06-02');

        $this->arqueoListoParaCerrar($this->caja(), '2026-06-02');

        return app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-02'),
        );
    }

    private function cierreDel30DeJunioConArqueo(): PeriodClosing
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '743050.00'],
            denominations: $this->billetesPara('743050.00'),
            date: CarbonImmutable::parse('2026-06-01'),
        );
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
        );

        app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        return app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
            actorId: $contador->id,
        );
    }

    /** El mismo 30/06, pero abriendo el fajo en vez de declararlo. */
    private function cierreDel30DeJunioConFajoRecontado(): PeriodClosing
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '743050.00'],
            denominations: $this->billetesPara('743050.00'),
            date: CarbonImmutable::parse('2026-06-01'),
        );
        $this->recibirEfectivo('2034800.00', '2026-06-30');

        $cajero = User::factory()->create();
        $contador = User::factory()->create();

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse('2026-06-30'),
            denominations: [20_000 => 101, 10_000 => 1, 1_000 => 4, 500 => 1, 200 => 1, 100 => 1],
            actorId: $cajero->id,
            carryRecount: new CarryRecount(
                reason: 'Verificación de cierre de mes.',
                denominations: [20_000 => 37, 1_000 => 3, 50 => 1],
            ),
        );

        app(ReviewCashCount::class)->handle($arqueo, $contador->id);

        return app(ClosePeriod::class)->handle(
            cashBoxId: $this->caja(),
            date: CarbonImmutable::parse('2026-06-30'),
            actorId: $contador->id,
        );
    }

    private function cobrar(string $importe, string $fecha): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, $importe)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::UnassignedFunds, $importe)->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: $this->caja(),
        );
    }

    /** Un ingreso por mostrador que integra la recaudación calculada del día. */
    private function recibirEfectivo(string $importe, string $fecha): void
    {
        app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(12),
            cashBoxId: $this->caja(),
            receivedDate: CarbonImmutable::parse($fecha),
        );
    }

    private function pagar(string $importe, string $fecha): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashDisbursement,
            idempotencyKey: 'egreso-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, $importe)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::CashOnHand, $importe)->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: $this->caja(),
        );
    }
}
