<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Actions\ConsolidateFineImportRow;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\StartFineImport;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use Database\Seeders\CatalogosSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Dos lotes que consolidan la misma multa a la vez, aunque no tenga pagos: el
 * segundo espera y choca con la identidad ya consolidada. Sin `RefreshDatabase`:
 * el otro proceso necesita ver lo confirmado.
 */
#[Group('serial')]
class ImportacionConcurrenteDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);
        Storage::fake('local');
        config(['multas.historical_import_enabled' => true]);
        $this->abrirMultas($this->cuenta(), '2026-06-01');
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            RefreshDatabaseState::$migrated = false;
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_batches_consolidating_the_same_fine_collide(): void
    {
        // El expediente ya existe, con otra multa: los dos lotes lo reusan y solo los frena la identidad de la fila.
        app(RegisterFine::class)->handle([
            'caseNumber' => '221681/15-0',
            'resolution' => ['text' => 'N°0999/18', 'issuedOn' => '2018-03-01'],
            'acts' => [['actType' => 'LAB', 'text' => 'N°1/18', 'issuedOn' => null]],
            'caption' => 'MIRANDA ANIBAL',
            'registeredOn' => '2018-03-01',
            'amount' => '1000.00',
        ], $this->quienAbre());

        $primero = $this->subir();
        $segundo = $this->subir();

        foreach ([$primero, $segundo] as $lote) {
            DB::table('fine_import_batches')->where('id', $lote->id)->update(['status' => 'consolidating']);
        }

        $filaA = FineImportRow::query()->where('fine_import_batch_id', $primero->id)->where('kind', 'fine')->sole();
        $filaB = FineImportRow::query()->where('fine_import_batch_id', $segundo->id)->where('kind', 'fine')->sole();

        DB::beginTransaction();
        app(ConsolidateFineImportRow::class)->handle($filaA, $this->quienAbre());

        $worker = $this->esperando(['mode' => 'consolidate-import-row', 'row' => $filaB->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and((string) $resultado['message'])->toContain('fine_import_rows_one_fine_per_identity')
            ->and(Fine::query()->count())->toBe(2)
            ->and($filaB->refresh()->fine_id)->toBeNull();
    }

    private function subir(): FineImportBatch
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('2018');

        $celdas = [
            'A1' => 'FECHA', 'B1' => 'EXPTE.', 'C1' => 'RESOL.', 'E1' => 'CARATULA', 'F1' => 'IMPORTE', 'H1' => 'TOTAL PAGADO', 'I1' => 'SALDO',
            'B2' => '221681/15-0', 'C2' => '0029/18', 'D2' => 'LAB Nº 24442/15', 'E2' => 'MIRANDA ANIBAL', 'F2' => 3000, 'H2' => 0, 'I2' => 3000,
        ];

        foreach ($celdas as $celda => $valor) {
            $hoja->setCellValue($celda, $valor);
        }

        foreach (['G1' => '2018-01-01', 'A2' => '2018-01-08'] as $celda => $fecha) {
            $hoja->setCellValue($celda, Date::PHPToExcel(new DateTimeImmutable($fecha)));
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('yyyy-mm-dd');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'planilla-').'.xls';
        (new Xls($libro))->save($ruta);

        return app(StartFineImport::class)->handle(new UploadedFile($ruta, 'cuenta-corriente.xls', 'application/vnd.ms-excel', null, true), $this->quienAbre());
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'importacion-'.Str::uuid();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/transfer-worker.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput(json_encode(['database' => config('database.connections.pgsql'), 'tag' => $tag, ...$datos], JSON_THROW_ON_ERROR));
        $this->workers[] = $worker;
        $worker->start();

        $deadline = microtime(true) + 10;

        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $espera = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag]);

            if ($espera?->wait_event_type === 'Lock') {
                return $worker;
            }

            if (! $worker->isRunning()) {
                $this->fail('El worker terminó sin esperar el bloqueo: '.$worker->getOutput().$worker->getErrorOutput());
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail('No se observó la espera real en PostgreSQL: '.$worker->getOutput().$worker->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function resultado(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $lineas = explode("\n", trim($worker->getOutput()));

        return json_decode((string) end($lineas), true, 512, JSON_THROW_ON_ERROR);
    }
}
