<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\RegisterTransferReport;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidPaymentOrder;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Support\Reason;
use Database\Seeders\CatalogosSeeder;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsTransferInstallment;
use Tests\TestCase;

/**
 * Dos operadores sobre la misma cuota a la vez, en dos procesos de verdad.
 *
 * - Entregar el dinero en mano y liberar los fondos de la cuota: los dos
 *   bloquean expediente → haber → cuota, y el segundo encuentra lo que dejó
 *   el primero. Antes la entrega decidía fuera de la transacción.
 * - Anular la Orden y registrar el informe de su transferencia: los dos
 *   toman la fila de la Orden, y nunca queda un egreso vivo atado a una
 *   Orden anulada.
 * - Anular una cuota y registrar su egreso, por SQL directo: los dos toman la
 *   fila de la cuota, y nunca queda una anulada con un egreso vivo.
 *
 * Sin `RefreshDatabase`, como `AnulacionConcurrenteDeMultasTest`: el segundo
 * proceso necesita ver los datos confirmados.
 */
#[Group('serial')]
class EgresoConcurrenteTest extends TestCase
{
    use BuildsTransferInstallment;

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

        $this->abrirLibrosSinSaldo(Currency::Ars);
        $this->seed(HaberesDemoSeeder::class);
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

    /** Mientras se entrega, liberar los fondos espera y después encuentra el egreso. */
    public function test_releasing_while_delivering_waits_and_is_refused(): void
    {
        $cuota = $this->cuotaCobrada();
        $asignacion = FundingAllocation::query()->where('beneficiary_installment_id', $cuota->id)->sole();
        $financiado = app(InstallmentFunding::class)->allocated($cuota);

        DB::beginTransaction();
        app(DeliverToBeneficiary::class)->handle($cuota, (string) Str::uuid(), $this->quienAbre());

        $worker = $this->esperando(['mode' => 'haberes-release', 'allocation' => $asignacion->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and(app(InstallmentFunding::class)->allocated($cuota->refresh()))->toBe($financiado)
            ->and(Disbursement::query()->confirmed()->where('beneficiary_installment_id', $cuota->id)->count())->toBe(1);
    }

    /** Mientras se liberan los fondos, entregar espera y después encuentra la cuota sin dinero. */
    public function test_delivering_while_releasing_waits_and_is_refused(): void
    {
        $cuota = $this->cuotaCobrada();
        $asignacion = FundingAllocation::query()->where('beneficiary_installment_id', $cuota->id)->sole();

        DB::beginTransaction();
        app(UnallocateFunds::class)->handle(
            $asignacion, $asignacion->amount, (string) Str::uuid(),
            new Reason(HaberesReasonCode::DataEntryError, null), $this->quienAbre(),
        );

        $worker = $this->esperando(['mode' => 'haberes-deliver', 'installment' => $cuota->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and(Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->count())->toBe(0);
    }

    /**
     * Mientras se anula la Orden, el informe espera y recibe el motivo.
     *
     * Un error de formulario, no de base: la base también lo frena
     * (`disbursements_order_active`), pero eso llegaba al operador como un 500.
     */
    public function test_reporting_while_the_order_is_voided_waits_and_is_refused(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = PaymentOrder::query()->active()->where('beneficiary_installment_id', $cuota->id)->sole();

        DB::beginTransaction();
        app(VoidPaymentOrder::class)->handle($orden, new Reason(HaberesReasonCode::WrongBeneficiaryData, null), $this->quienAbre());

        $worker = $this->esperando(['mode' => 'haberes-report', 'installment' => $cuota->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('installmentId')
            ->and(Disbursement::query()->live()->where('beneficiary_installment_id', $cuota->id)->count())->toBe(0)
            ->and($orden->refresh()->status)->toBe(PaymentOrderStatus::Voided);
    }

    /** Lo mismo cuando el débito llega antes que el informe: también crea el egreso. */
    public function test_linking_the_debit_while_the_order_is_voided_waits_and_is_refused(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = PaymentOrder::query()->active()->where('beneficiary_installment_id', $cuota->id)->sole();
        $debito = $this->debito($cuota);

        DB::beginTransaction();
        app(VoidPaymentOrder::class)->handle($orden, new Reason(HaberesReasonCode::WrongBeneficiaryData, null), $this->quienAbre());

        $worker = $this->esperando([
            'mode' => 'haberes-link-debit', 'installment' => $cuota->id, 'transaction' => $debito->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('installmentId')
            ->and(Disbursement::query()->live()->where('beneficiary_installment_id', $cuota->id)->count())->toBe(0)
            ->and($orden->refresh()->status)->toBe(PaymentOrderStatus::Voided);
    }

    /** Mientras se registra el informe, anular la Orden espera y encuentra la transferencia en curso. */
    public function test_voiding_the_order_while_reporting_waits_and_is_refused(): void
    {
        $cuota = $this->cuotaConOrden();
        $orden = PaymentOrder::query()->active()->where('beneficiary_installment_id', $cuota->id)->sole();

        DB::beginTransaction();
        app(RegisterTransferReport::class)->handle($cuota, null, '83690105', $this->quienAbre());

        $worker = $this->esperando(['mode' => 'haberes-void-order', 'order' => $orden->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('orderId')
            ->and($orden->refresh()->status)->toBe(PaymentOrderStatus::Draft);
    }

    /** Mientras se registra un egreso, anular la cuota espera y lo encuentra. */
    public function test_cancelling_the_installment_while_paying_waits_and_is_refused(): void
    {
        $cuota = BeneficiaryInstallment::query()->orderBy('id')->firstOrFail();

        DB::beginTransaction();
        DB::table('disbursements')->insert([
            'beneficiary_installment_id' => $cuota->id, 'method' => 'cash', 'amount' => $cuota->expected_amount, 'status' => 'pending',
        ]);

        $worker = $this->esperando(['mode' => 'raw-cancel-installment', 'installment' => $cuota->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('tiene un egreso registrado: no se puede anular')
            ->and($cuota->refresh()->workflow_status)->not->toBe(InstallmentWorkflowStatus::Cancelled);
    }

    /** Mientras se anula la cuota, registrar un egreso espera y la encuentra anulada. */
    public function test_paying_while_the_installment_is_cancelled_waits_and_is_refused(): void
    {
        $cuota = BeneficiaryInstallment::query()->orderBy('id')->firstOrFail();

        DB::beginTransaction();
        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['workflow_status' => 'cancelled']);

        $worker = $this->esperando(['mode' => 'raw-disbursement', 'installment' => $cuota->id, 'amount' => $cuota->expected_amount]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('está anulada: no admite un egreso')
            ->and(Disbursement::query()->where('beneficiary_installment_id', $cuota->id)->count())->toBe(0);
    }

    /** La migración espera la escritura en curso antes de revisar los datos. */
    public function test_the_migration_waits_for_a_pending_write_and_then_checks_it(): void
    {
        $cuota = BeneficiaryInstallment::query()->orderBy('id')->firstOrFail();
        $migracion = 'migrations/2026_11_25_000000_guard_installment_status_against_disbursements.php';
        (require database_path($migracion))->down();

        DB::beginTransaction();
        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['workflow_status' => 'paid']);

        $worker = $this->esperando(['mode' => 'migrate-up', 'migration' => $migracion]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('cuotas pagadas sin egreso confirmado');
    }

    /** @param  array<string, mixed>  $datos */
    private function esperando(array $datos): Process
    {
        $tag = 'egreso-'.Str::uuid();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/transfer-worker.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput(json_encode([
            'database' => config('database.connections.pgsql'), 'tag' => $tag, ...$datos,
        ], JSON_THROW_ON_ERROR));
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
