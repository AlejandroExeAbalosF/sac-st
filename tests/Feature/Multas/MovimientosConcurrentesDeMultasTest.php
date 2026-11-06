<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Models\Fine;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El informe del mes lee una sola foto aunque otro cobre mientras se arma: un cobro
 * no puede figurar en las aplicaciones y faltar en los cobros de la misma consulta.
 *
 * Sin `RefreshDatabase`: el cobro entra por otra conexión, que solo ve lo confirmado.
 */
#[Group('serial')]
class MovimientosConcurrentesDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    private User $admin;

    private Fine $multa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);

        $this->admin = $this->operador('administrador');
        $this->abrirMultas($this->cuenta(), '2026-06-01');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->actingAs($this->admin)->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-09-01',
            'amount' => '100000.00',
        ])->assertSessionHasNoErrors();
        $this->multa = Fine::query()->latest('id')->firstOrFail();

        $this->cobrar('primero');
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

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    private function cobrar(string $clave): void
    {
        app(CollectFinePayment::class)->handle($this->multa, FinePaymentMedium::Cash, '5000.00', $clave, $this->admin->id);
    }

    /** Apenas se lee la primera sección, otro cobra y confirma por su propia conexión. */
    private function cobrarDuranteLaLectura(): void
    {
        $cobrado = false;

        DB::listen(function (QueryExecuted $consulta) use (&$cobrado): void {
            if ($cobrado || ! str_contains($consulta->sql, 'fund_receipts')) {
                return;
            }

            $cobrado = true;
            $principal = DB::getDefaultConnection();
            DB::setDefaultConnection('pgsql_concurrente');

            try {
                $this->cobrar('durante-la-lectura');
            } finally {
                DB::setDefaultConnection($principal);
            }
        });
    }

    public function test_the_screen_reads_every_section_from_the_same_moment(): void
    {
        $this->cobrarDuranteLaLectura();

        $this->actingAs($this->admin)->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['cobros']['total'])->toBe('5000.00')
                    ->and($secciones['aplicaciones']['total'])->toBe('5000.00');
            });

        // Terminada la lectura, el cobro está y se ve.
        expect(DB::table('fine_payment_applications')->count())->toBe(2)
            ->and(DB::transactionLevel())->toBe(0);
    }

    public function test_the_export_reads_every_section_from_the_same_moment(): void
    {
        $this->cobrarDuranteLaLectura();

        $contenido = $this->actingAs($this->admin)
            ->get(route('multas.movimientos.exportar', ['mes' => '2026-09']))
            ->assertOk()
            ->streamedContent();
        $archivo = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        file_put_contents($archivo, $contenido);
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, false, false);
        @unlink($archivo);

        $total = fn (string $seccion): mixed => collect($filas)->first(fn (array $f): bool => $f[0] === $seccion && $f[1] === 'Total de la sección')[7] ?? null;

        expect($total('Cobros en caja'))->toBe('5.000,00')
            ->and($total('Aplicaciones a la deuda'))->toBe('5.000,00')
            ->and(DB::transactionLevel())->toBe(0);
    }
}
