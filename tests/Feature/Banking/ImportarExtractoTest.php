<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Actions\ImportBankStatement;
use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Importación de extractos, contra los archivos reales del área.
 *
 * Las fixtures de `tests/Fixtures/Banking` son los archivos que la
 * contadora descargó de MacroOnline el 6 de agosto de 2026, no un CSV
 * inventado: el doble comillado, el BIFF8 y las catorce filas son los que
 * el sistema va a encontrar en producción.
 */
class ImportarExtractoTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA = '310000123456789';

    public function test_el_csv_doble_comillado_se_interpreta_entero(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv')->assertSessionHasNoErrors();

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertSame(ImportStatus::Completed, $import->status);
        $this->assertSame(14, $import->rows_total);
        $this->assertSame(14, $import->rows_valid);
        $this->assertSame(0, $import->rows_rejected);
        $this->assertSame(14, $import->rows_new);
        $this->assertSame(14, BankTransaction::query()->count());
    }

    /**
     * Los importes llegan como decimal, con el separador argentino
     * resuelto: `473.191,20` es cuatrocientos setenta y tres mil.
     */
    public function test_los_importes_conservan_su_valor(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $credito = BankTransaction::query()
            ->where('operation_id', '1145832449')
            ->firstOrFail();

        $this->assertSame('473191.20', $credito->amount);
        $this->assertSame(TransactionDirection::Credit, $credito->direction);
        $this->assertSame('17692768.55', $credito->balance_after);
        $this->assertSame('4397', $credito->causal_code);

        $debito = BankTransaction::query()
            ->where('operation_id', '86972028')
            ->firstOrFail();

        $this->assertSame('1253491.05', $debito->amount);
        $this->assertSame(TransactionDirection::Debit, $debito->direction);
    }

    /** El Excel viene en Windows-1252 y no puede entrar roto a la base. */
    public function test_el_excel_conserva_los_acentos_y_la_cabecera(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-excel.xls')->assertSessionHasNoErrors();

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertSame(self::CUENTA, $import->account_number_in_file);
        $this->assertSame('ARS', $import->currency_in_file);
        $this->assertSame('ANA MARIA GONZALEZ', $import->operator_in_file);
        $this->assertSame('2026-08-06 11:24:11', $import->downloaded_at?->format('Y-m-d H:i:s'));
        $this->assertSame(14, $import->rows_new);
    }

    /**
     * El mismo período en los dos formatos produce los mismos movimientos.
     *
     * Es la prueba de que la huella no depende de cómo se descargó el
     * archivo. Sin esto, bajar el extracto en Excel después de haberlo
     * bajado en CSV duplicaría el mes entero.
     */
    public function test_el_mismo_periodo_en_los_dos_formatos_no_se_duplica(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv')->assertSessionHasNoErrors();
        $this->assertSame(14, BankTransaction::query()->count());

        $this->importar($cuenta, 'macro-excel.xls')->assertSessionHasNoErrors();

        $this->assertSame(14, BankTransaction::query()->count());

        $segunda = BankStatementImport::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $segunda->rows_new);
        $this->assertSame(14, $segunda->rows_duplicate);
        // Las filas sí se guardan las dos veces: son dos archivos.
        $this->assertSame(28, BankStatementRow::query()->count());
    }

    /**
     * El archivo se lee por su contenido, no por su extensión.
     *
     * Cuando el navegador sube un archivo, PHP lo escribe en un temporal
     * `phpXXXX.tmp` **sin extensión**, y ese es el nombre que ve el lector.
     * El resto de los tests no lo notaba porque `UploadedFile` en modo
     * prueba conserva la ruta original: la importación funcionaba en los
     * tests y fallaba en producción, que es la peor combinación posible.
     *
     * Se descubrió abriendo la pantalla en el navegador, no acá.
     */
    public function test_el_archivo_se_lee_aunque_el_temporal_no_tenga_extension(): void
    {
        $cuenta = $this->cuenta();

        foreach (['macro-online.csv', 'macro-excel.xls'] as $fixture) {
            $temporal = tempnam(sys_get_temp_dir(), 'php');
            $this->assertIsString($temporal);
            copy($this->fixture($fixture), $temporal);

            $this->actingAs($this->operador())
                ->post(route('banco.extractos.store'), [
                    'bankAccountId' => $cuenta->id,
                    // El nombre original conserva la extensión —es lo que
                    // manda el navegador—, la ruta temporal no.
                    'file' => new UploadedFile($temporal, $fixture, null, null, true),
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(14, BankTransaction::query()->count());
        $this->assertSame(2, BankStatementImport::query()->count());
    }

    public function test_el_mismo_archivo_no_se_importa_dos_veces(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv')->assertSessionHasNoErrors();
        $this->importar($cuenta, 'macro-online.csv')->assertSessionHasErrors('file');

        $this->assertSame(1, BankStatementImport::query()->count());
    }

    /**
     * La cadena de saldos cierra en el archivo real, y el sistema lo
     * comprueba en vez de confiar.
     */
    public function test_la_cadena_de_saldos_del_archivo_real_cierra(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertTrue($import->balance_chain_ok);
        $this->assertSame('17692768.55', $import->closing_balance);
        $this->assertSame('22656189.76', $import->opening_balance);
        $this->assertSame('2026-07-07', $import->period_from?->format('Y-m-d'));
        $this->assertSame('2026-08-05', $import->period_to?->format('Y-m-d'));
    }

    /**
     * Quitar una fila del medio rompe la cadena, y eso rechaza el archivo.
     *
     * Es el caso real que motiva el control: un extracto al que le falta
     * un movimiento no se puede conciliar nunca, y el faltante no se nota
     * hasta que el saldo no cuadra meses después.
     */
    public function test_un_extracto_con_una_fila_faltante_se_rechaza(): void
    {
        $cuenta = $this->cuenta();

        $lineas = file($this->fixture('macro-online.csv'), FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lineas);

        // Fuera la comisión del 04/08: la cadena deja de cerrar por $121.
        unset($lineas[3]);

        $mutilado = tempnam(sys_get_temp_dir(), 'ext').'.csv';
        file_put_contents($mutilado, implode("\n", $lineas));

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile($mutilado, 'recortado.csv', null, null, true),
            ]);

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertFalse($import->balance_chain_ok);
        $this->assertStringContainsString('no encadena', (string) $import->failure_reason);
        // Nada entró: ni movimientos ni filas.
        $this->assertSame(0, BankTransaction::query()->count());
        $this->assertSame(0, $import->rows()->count());
    }

    public function test_el_extracto_de_otra_cuenta_se_rechaza(): void
    {
        $otra = BankAccount::query()->create([
            'label' => 'Cta. Cte. 4321 — Otra',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000987654321',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $this->importar($otra, 'macro-excel.xls');

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('310000123456789', (string) $import->failure_reason);
        $this->assertSame(0, BankTransaction::query()->count());
    }

    /**
     * §4.4 del DER: nunca se suman importes de monedas distintas. El
     * control tiene que existir antes de que exista la cuenta en dólares.
     */
    public function test_un_extracto_en_pesos_no_entra_a_una_cuenta_en_dolares(): void
    {
        $dolares = BankAccount::query()->create([
            'label' => 'Cta. en dólares',
            'bank_name' => 'Banco Macro',
            'account_number' => self::CUENTA,
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->importar($dolares, 'macro-excel.xls');

        $import = BankStatementImport::query()->firstOrFail();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertStringContainsString('monedas', (string) $import->failure_reason);
    }

    /**
     * La referencia bancaria se repite: la transferencia y su comisión
     * comparten `86934222`. Es el hallazgo que cierra §4.3 del DER.
     */
    public function test_la_referencia_bancaria_puede_repetirse(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $repetidas = BankTransaction::query()
            ->where('operation_id', '86934222')
            ->get();

        $this->assertCount(2, $repetidas);
        $this->assertEqualsCanonicalizing(
            ['121.00', '891841.00'],
            $repetidas->pluck('amount')->all(),
        );
    }

    /** El CUIT del empleador sale del concepto, verificado con su dígito. */
    public function test_se_extrae_el_cuit_del_concepto(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $salomone = BankTransaction::query()->where('operation_id', '663545')->firstOrFail();
        $this->assertSame('20444444445', $salomone->counterparty_identifier);
        $this->assertSame('SANDOVAL', $salomone->counterparty_name);

        // Un depósito por ventanilla no trae CUIT ni nombre: el número que
        // aparece en el texto no es un CUIT y no se lo inventa.
        $ventanilla = BankTransaction::query()->where('operation_id', '1379263760')->firstOrFail();
        $this->assertNull($ventanilla->counterparty_identifier);
        $this->assertNull($ventanilla->counterparty_name);

        // Una comisión tampoco tiene contraparte.
        $comision = BankTransaction::query()->where('causal_code', '3914')->firstOrFail();
        $this->assertNull($comision->counterparty_name);
    }

    public function test_la_importacion_queda_auditada(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $evento = AuditEvent::query()
            ->where('subject_type', 'BankStatementImport')
            ->where('action', 'banco.extracto.importado')
            ->firstOrFail();

        $this->assertSame(14, $evento->new_values['rows_new']);
    }

    public function test_los_movimientos_nacen_sin_identificar(): void
    {
        $cuenta = $this->cuenta();

        $this->importar($cuenta, 'macro-online.csv');

        $this->assertSame(
            14,
            BankTransaction::query()
                ->where('reconciliation_status', ReconciliationStatus::Pending->value)
                ->count(),
        );
    }

    public function test_movimientos_iguales_sin_saldo_no_se_fusionan(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => $this->extractoSinSaldos(),
            ])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('result.rowsNew', 2)
                ->where('result.rowsDuplicate', 0)
                ->where('result.balanceChainOk', null),
            );

        $this->assertSame(2, BankTransaction::query()->count());
        $this->assertSame(2, BankTransaction::query()->whereNull('balance_after')->count());
    }

    public function test_un_fallo_de_base_no_deja_el_archivo_guardado(): void
    {
        $cuenta = $this->cuenta();
        $archivo = new UploadedFile(
            $this->fixture('macro-online.csv'),
            'macro-online.csv',
            null,
            null,
            true,
        );

        try {
            app(ImportBankStatement::class)->handle($archivo, $cuenta, PHP_INT_MAX);
            $this->fail('La importación debía fallar por el usuario inexistente.');
        } catch (QueryException) {
            $this->assertSame([], Storage::disk('local')->allFiles('bank-statements'));
        }
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => self::CUENTA,
            'currency' => 'ARS',
            'is_active' => true,
        ]);
    }

    private function importar(BankAccount $cuenta, string $fixture): TestResponse
    {
        return $this->actingAs($this->operador())
            ->post(route('banco.extractos.store'), [
                'bankAccountId' => $cuenta->id,
                'file' => new UploadedFile($this->fixture($fixture), $fixture, null, null, true),
            ]);
    }

    private function fixture(string $name): string
    {
        return base_path('tests/Fixtures/Banking/'.$name);
    }

    private function extractoSinSaldos(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'sin-saldos.csv',
            implode("\n", [
                'Fecha,Referencia,Codigo Causal,Concepto,Debito,Credito,Saldo',
                '"05/08/2026","123","4397","Transferencia repetible","","100,00",""',
                '"05/08/2026","123","4397","Transferencia repetible","","100,00",""',
            ]),
        );
    }
}
