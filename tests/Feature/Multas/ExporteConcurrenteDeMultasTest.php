<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Support\FineListQuery;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El exporte lee una sola foto de los datos aunque otro corrija mientras baja.
 *
 * Sin `RefreshDatabase`: la corrección se hace desde otra conexión y esa
 * conexión solo ve datos confirmados. Las filas que quedan no se pueden
 * borrar —la obligación es append-only—, así que la base se recrea al
 * empezar y se marca para que el próximo test la vuelva a recrear.
 */
class ExporteConcurrenteDeMultasTest extends TestCase
{
    private const SIN_FILTROS = ['q' => '', 'estado' => '', 'anio' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh')->assertExitCode(0);

        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('pgsql_concurrente');
            RefreshDatabaseState::$migrated = false;
        } finally {
            parent::tearDown();
        }
    }

    public function test_a_date_corrected_during_the_export_neither_repeats_nor_drops_a_row(): void
    {
        $caso = $this->expediente();
        $a = $this->multa($caso, 'HISTÓRICA A', '2025-03-03');
        $b = $this->multa($caso, 'HISTÓRICA B', '2025-03-02');
        $c = $this->multa($caso, 'HISTÓRICA C', '2025-03-01');

        $bloques = app(FineListQuery::class)->chunks(self::SIN_FILTROS, 1);
        $exportadas = $this->ids($bloques->current());

        // Mientras baja, otro corrige: A queda detrás del cursor y C, delante.
        $this->corregirFecha($a, '2025-03-03', '2025-02-28');
        $this->corregirFecha($c, '2025-03-01', '2025-03-05');

        $fechas = [];
        foreach ($this->resto($bloques) as $multa) {
            $exportadas[] = $multa->id;
            $fechas[$multa->id] = $multa->registered_on?->toDateString();
        }

        expect($exportadas)->toBe([$a, $b, $c])
            ->and($fechas[$c])->toBe('2025-03-01')
            ->and(DB::transactionLevel())->toBe(0);

        // Terminado el exporte, la corrección está y se ve.
        expect(Fine::query()->findOrFail($a)->registered_on?->toDateString())->toBe('2025-02-28');
    }

    public function test_balances_and_filters_come_from_the_same_moment(): void
    {
        $caso = $this->expediente();
        $a = $this->multa($caso, 'CRISTOMAX A', '2025-03-03');
        $b = $this->multa($caso, 'CRISTOMAX B', '2025-03-02');

        $bloques = app(FineListQuery::class)->chunks([...self::SIN_FILTROS, 'q' => 'cristomax'], 1);
        $exportadas = $this->ids($bloques->current());

        // B deja de coincidir con la búsqueda y le bajan la deuda.
        $this->otra()->table('fine_identifier_revisions')->insert([
            'fine_id' => $b, 'kind' => 'correction', 'reason' => 'Carátula mal leída',
            'old_values' => json_encode(['caption' => 'CRISTOMAX B']),
            'new_values' => json_encode(['caption' => 'OTRA EMPRESA']),
        ]);
        $this->otra()->table('fine_debt_entries')->insert([
            'fine_id' => $b, 'kind' => 'reduction', 'amount' => '400.00', 'reason' => 'Recurso admitido',
            'fine_document_id' => $this->resolucionDe($b), 'effective_on' => '2026-06-01', 'effective_precision' => 'day',
        ]);

        $resto = $this->resto($bloques);

        expect($exportadas)->toBe([$a])
            ->and(array_map(fn (Fine $multa): int => $multa->id, $resto))->toBe([$b])
            ->and($resto[0]->caption)->toBe('CRISTOMAX B')
            ->and($resto[0]->balance?->debt)->toBe('1000.00');

        expect(DB::table('fine_balances')->where('fine_id', $b)->value('debt'))->toBe('600.00');
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /**
     * @param  Generator<int, Collection<int, Fine>>  $bloques
     * @return list<Fine>
     */
    private function resto(Generator $bloques): array
    {
        $filas = [];

        for ($bloques->next(); $bloques->valid(); $bloques->next()) {
            array_push($filas, ...$bloques->current()->all());
        }

        return $filas;
    }

    /**
     * @param  Collection<int, Fine>  $bloque
     * @return list<int>
     */
    private function ids($bloque): array
    {
        return array_values($bloque->map(fn (Fine $multa): int => $multa->id)->all());
    }

    private function otra(): Connection
    {
        return DB::connection('pgsql_concurrente');
    }

    private function corregirFecha(int $multa, string $antes, string $despues): void
    {
        $this->otra()->table('fine_identifier_revisions')->insert([
            'fine_id' => $multa, 'kind' => 'correction', 'reason' => 'Fecha mal leída',
            'old_values' => json_encode(['registered_on' => $antes, 'registered_precision' => 'day']),
            'new_values' => json_encode(['registered_on' => $despues, 'registered_precision' => 'day']),
        ]);
    }

    private function expediente(): int
    {
        return (int) DB::table('fine_cases')->insertGetId(['original_text' => '1/2020', 'number' => 1, 'year' => 2020]);
    }

    /** Una multa importada, confirmada: la otra conexión tiene que verla. */
    private function multa(int $caso, string $caratula, string $fecha): int
    {
        return DB::transaction(function () use ($caso, $caratula, $fecha): int {
            $numero = random_int(1, 999999);
            $resolucion = (int) DB::table('fine_documents')->insertGetId([
                'kind' => 'resolution', 'number' => $numero, 'year' => 2025,
                'original_text' => "N°{$numero}/25", 'issued_on' => null, 'issued_precision' => 'unknown',
            ]);
            DB::table('fine_case_documents')->insert(['fine_case_id' => $caso, 'fine_document_id' => $resolucion]);

            $multa = (int) DB::table('fines')->insertGetId([
                'fine_case_id' => $caso, 'caption_original' => $caratula, 'caption' => $caratula,
                'provenance' => 'import', 'registered_on' => $fecha, 'registered_precision' => 'day',
            ]);
            DB::table('fine_document_links')->insert([
                'fine_id' => $multa, 'fine_case_id' => $caso, 'fine_document_id' => $resolucion, 'role' => 'origin',
            ]);
            DB::table('fine_debt_entries')->insert([
                'fine_id' => $multa, 'kind' => 'constitution', 'amount' => '1000.00', 'fine_document_id' => $resolucion,
                'effective_on' => null, 'effective_precision' => 'unknown', 'provenance' => 'import',
            ]);

            return $multa;
        });
    }

    private function resolucionDe(int $multa): int
    {
        return (int) DB::table('fine_document_links')->where('fine_id', $multa)->value('fine_document_id');
    }
}
