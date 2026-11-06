<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CreateFeeCharge;
use App\Modules\Aranceles\Actions\IdentifyFeeCredits;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Concerns\CollectsInstallments;
use Tests\Concerns\LoadsFeeChargeProof;
use Tests\TestCase;

/**
 * Traspasar, anular y cancelar a la vez dejan un solo resultado.
 *
 * Sin `RefreshDatabase`: el segundo proceso necesita ver los datos confirmados.
 */
#[Group('serial')]
class DepositoConcurrenteDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;
    use CollectsInstallments {
        cuota as cuotaDeHaberes;
        cobrar as cobrarCuota;
    }
    use LoadsFeeChargeProof;

    /** @var list<Process> */
    private array $workers = [];

    private User $admin;

    private int $caja;

    private BankAccount $banco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->admin = $this->operador('administrador');
        $this->caja = $this->cajaDe(CashBox::ARANCELES);
        $this->banco = $this->cuenta();

        app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today()->subDays(10),
            bankAccountId: $this->banco->id,
            balances: [],
            denominations: [],
            notes: null,
            declaredEmpty: true,
            actorId: $this->admin->id,
        );
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

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    private function cobrarArancel(): FeeCharge
    {
        return app(CollectFeeInCash::class)->handle(
            'AG Max SRL', null, [['feeTypeId' => FeeType::query()->where('code', 14)->value('id'), 'quantity' => 1]],
            (string) Str::uuid(), $this->admin->id, null, null, null, true,
        );
    }

    private function traspasar(string $importe): CashToBankTransfer
    {
        return app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, $importe, BusinessDate::today(), null, null, (string) Str::uuid(), $this->admin->id,
        );
    }

    /** Mientras uno traspasa, otro espera y encuentra que ese efectivo ya no está. */
    public function test_two_transfers_of_the_same_cash_leave_one(): void
    {
        $this->cobrarArancel();

        DB::beginTransaction();
        $this->traspasar('120');

        $worker = $this->esperando(['mode' => 'fee-transfer', 'box' => $this->caja, 'amount' => '120', 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('amount')
            ->and(CashToBankTransfer::query()->count())->toBe(1);
    }

    /**
     * El mismo formulario enviado dos veces a la vez: el segundo espera y devuelve
     * el traspaso del primero, se haya llevado una parte o todo el efectivo.
     */
    #[DataProvider('importesDelMismoEnvio')]
    public function test_the_same_form_sent_twice_at_once_returns_the_same_transfer(string $importe): void
    {
        $this->cobrarArancel();
        $clave = (string) Str::uuid();

        DB::beginTransaction();
        $primero = app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, $importe, BusinessDate::today(), null, null, $clave, $this->admin->id,
        );

        $worker = $this->esperando(['mode' => 'fee-transfer', 'box' => $this->caja, 'amount' => $importe, 'key' => $clave, 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and($resultado['transfer'])->toBe($primero->id)
            ->and(CashToBankTransfer::query()->count())->toBe(1);
    }

    /** @return array<string, array{string}> */
    public static function importesDelMismoEnvio(): array
    {
        return ['una parte' => ['50'], 'todo el efectivo' => ['120']];
    }

    private function pendiente(): FeeCharge
    {
        return $this->conComprobante(app(CreateFeeCharge::class)->handle(
            'AG Max SRL', null, [['feeTypeId' => FeeType::query()->where('code', 14)->value('id'), 'quantity' => 1]],
            (string) Str::uuid(), $this->admin->id, true,
        ), $this->admin->id);
    }

    /** Dos cobros y un solo crédito: el segundo espera y solo toma lo que quedó libre. */
    public function test_two_charges_share_a_credit_without_using_it_twice(): void
    {
        $credito = $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '200');
        $primero = $this->pendiente();
        $segundo = $this->pendiente();

        DB::beginTransaction();
        app(IdentifyFeeCredits::class)->handle($primero, [$credito->id], (string) Str::uuid(), null, $this->admin->id);

        $worker = $this->esperando(['mode' => 'fee-identify', 'charge' => $segundo->id, 'credits' => [$credito->id], 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and($resultado['charge'])->toBe('pending')
            ->and($primero->refresh()->status)->toBe(FeeChargeStatus::Paid)
            ->and(app(AllocatableAmount::class)->for($credito->refresh()))->toBe('0.00');
    }

    /** Mientras se anula un pendiente, identificarlo espera y lo encuentra anulado. */
    public function test_identifying_while_it_is_being_voided_is_refused(): void
    {
        $credito = $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '120');
        $cobro = $this->pendiente();

        DB::beginTransaction();
        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $worker = $this->esperando(['mode' => 'fee-identify', 'charge' => $cobro->id, 'credits' => [$credito->id], 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('credits')
            ->and(app(AllocatableAmount::class)->for($credito->refresh()))->toBe('120.00');
    }

    /** Mientras se identifica, anular espera y revierte lo que entró. */
    public function test_voiding_while_it_is_being_identified_frees_the_credit(): void
    {
        $credito = $this->movimiento($this->banco, BusinessDate::today()->toDateString(), '100');
        $cobro = $this->pendiente();

        DB::beginTransaction();
        app(IdentifyFeeCredits::class)->handle($cobro, [$credito->id], (string) Str::uuid(), null, $this->admin->id);

        $worker = $this->esperando(['mode' => 'fee-void', 'charge' => $cobro->id, 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and($cobro->refresh()->status)->toBe(FeeChargeStatus::Voided)
            ->and(app(AllocatableAmount::class)->for($credito->refresh()))->toBe('100.00');
    }

    /** Mientras se traspasa, anular espera y lo encuentra afuera de la caja. */
    public function test_voiding_while_it_is_being_transferred_is_refused(): void
    {
        $cobro = $this->cobrarArancel();

        DB::beginTransaction();
        $this->traspasar('120');

        $worker = $this->esperando(['mode' => 'fee-void', 'charge' => $cobro->id, 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('reason')
            ->and($cobro->refresh()->status)->toBe(FeeChargeStatus::Paid);
    }

    /** Mientras se anula, traspasar espera y encuentra que ese efectivo nunca entró. */
    public function test_transferring_while_it_is_being_voided_is_refused(): void
    {
        $cobro = $this->cobrarArancel();

        DB::beginTransaction();
        app(VoidFeeCollection::class)->handle($cobro, new Reason(FeeReasonCode::DataEntryError, null), $this->admin->id);

        $worker = $this->esperando(['mode' => 'fee-transfer', 'box' => $this->caja, 'amount' => '120', 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('amount')
            ->and(CashToBankTransfer::query()->count())->toBe(0);
    }

    /** Mientras se cancela, cargar el comprobante espera y lo encuentra cancelado. */
    public function test_loading_the_ticket_while_it_is_being_cancelled_is_refused(): void
    {
        $this->cobrarArancel();
        $traslado = $this->traspasar('120');

        DB::beginTransaction();
        app(CancelCashToBankTransfer::class)->handle($traslado, 'Se cargó por error', (string) Str::uuid(), $this->admin->id);

        $worker = $this->esperando(['mode' => 'fee-ticket', 'transfer' => $traslado->id, 'actor' => $this->admin->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('transferId')
            ->and($traslado->refresh()->status)->toBe(CashTransferStatus::Cancelled);
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'transfer-'.Str::uuid();
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
