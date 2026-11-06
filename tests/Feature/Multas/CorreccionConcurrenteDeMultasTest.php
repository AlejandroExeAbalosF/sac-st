<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\CorrectVerifiedFinePayment;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Actions\RecordFineChequeMovements;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\RejectFineCheque;
use App\Modules\Multas\Actions\ReplaceFineCheque;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\VerifyFineBankPayment;
use App\Modules\Multas\Actions\VoidFineCollection;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentCorrection;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Dos personas sobre el mismo pago, el mismo crédito o el mismo cheque: el segundo
 * espera y encuentra lo que hizo el primero, en los dos órdenes.
 *
 * Sin `RefreshDatabase`, como `AnulacionConcurrenteDeMultasTest`: el segundo
 * proceso necesita ver los datos confirmados.
 */
#[Group('serial')]
class CorreccionConcurrenteDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    private BankAccount $laCuenta;

    private Fine $multa;

    private Fine $otra;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');
        $this->multa = $this->registrar('169607/24-0');
        $this->otra = $this->registrar('169608/24-0');
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

    // ── Dos correcciones del mismo pago ─────────────────────────────────

    /** La segunda espera y devuelve la que ya se hizo: no hay dos pagos nuevos. */
    public function test_two_corrections_of_the_same_payment_make_one(): void
    {
        [$pago, $credito] = $this->verificado('400000.00');

        DB::beginTransaction();
        $correccion = $this->corregir($pago, $this->otra, $credito);

        $worker = $this->esperando([
            'mode' => 'correct', 'payment' => $pago->id, 'fine' => $this->otra->id, 'credit' => $credito->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and($resultado['correction'])->toBe($correccion->id)
            ->and(FinePaymentCorrection::query()->count())->toBe(1);
    }

    // ── Corrección contra verificación del mismo crédito ────────────────

    /** Mientras una corrección toma el crédito, verificar espera y lo encuentra ocupado. */
    public function test_verifying_while_a_correction_takes_the_credit_is_refused(): void
    {
        [$pago] = $this->verificado('400000.00');
        $libre = $this->credito('300000.00');
        $informado = $this->informar($this->otra, '300000.00');

        DB::beginTransaction();
        $this->corregir($pago, $this->multa, $libre);

        $worker = $this->esperando(['mode' => 'verify', 'payment' => $informado->id, 'credit' => $libre->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('bankTransactionId')
            ->and($informado->refresh()->status)->toBe(FinePaymentStatus::Reported)
            ->and(FinePaymentCorrection::query()->count())->toBe(1);
    }

    /** Mientras se verifica con ese crédito, corregir espera y retrocede entero. */
    public function test_correcting_while_the_credit_is_being_verified_rolls_back(): void
    {
        [$pago] = $this->verificado('400000.00');
        $libre = $this->credito('300000.00');
        $informado = $this->informar($this->otra, '300000.00');

        DB::beginTransaction();
        app(VerifyFineBankPayment::class)->handle($informado, $libre->id, $this->quienAbre());

        $worker = $this->esperando([
            'mode' => 'correct', 'payment' => $pago->id, 'fine' => $this->multa->id, 'credit' => $libre->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('bankTransactionId')
            ->and($pago->refresh()->status)->toBe(FinePaymentStatus::Applied)
            ->and(FinePaymentCorrection::query()->count())->toBe(0)
            ->and(DB::table('fine_payment_voids')->count())->toBe(0);
    }

    // ── Anular un cheque contra recibir su reemplazo ────────────────────

    /** Mientras se anula, recibir el reemplazo espera y lo encuentra anulado. */
    public function test_replacing_while_the_cheque_is_being_voided_is_refused(): void
    {
        $pago = $this->chequeRechazado();

        DB::beginTransaction();
        $this->anular($pago);

        $worker = $this->esperando(['mode' => 'fine-replace', 'rejection' => ChequeRejection::query()->sole()->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('chequeNumber')
            ->and(ChequeRejection::query()->sole()->replacement_fund_receipt_id)->toBeNull();
    }

    /** Mientras se recibe el reemplazo, anular espera y lo encuentra en curso. */
    public function test_voiding_while_the_replacement_arrives_is_refused(): void
    {
        $pago = $this->chequeRechazado();

        DB::beginTransaction();
        app(ReplaceFineCheque::class)->handle(
            ChequeRejection::query()->sole(), ['number' => 'B-1', 'bank' => null, 'issueDate' => null], (string) Str::uuid(), $this->quienAbre(),
        );

        $worker = $this->esperando(['mode' => 'void', 'payment' => $pago->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and(implode(' ', array_merge(...array_values($resultado['errors']))))->toContain('sigue en curso')
            ->and($pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    // ── Anular contra una acreditación tardía ───────────────────────────

    /** Mientras se anula, la acreditación espera y se registra sobre el rechazo: el pago sigue anulado. */
    public function test_a_late_credit_while_voiding_is_recorded_without_reviving_the_payment(): void
    {
        $pago = $this->chequeDevuelto();
        $credito = $this->credito('400000.00');

        DB::beginTransaction();
        $this->anular($pago);

        $worker = $this->esperando(['mode' => 'cheque-credit', 'rejection' => ChequeRejection::query()->sole()->id, 'credit' => $credito->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and($resultado['bank'])->toBe('debit_pending')
            ->and($pago->refresh()->status)->toBe(FinePaymentStatus::Voided);
    }

    /** Mientras se registra la acreditación, anular espera y la encuentra: ya no se anula. */
    public function test_voiding_while_a_late_credit_arrives_is_refused(): void
    {
        config(['multas.cheque_bank_followup_enabled' => true]);
        $pago = $this->chequeDevuelto();

        DB::beginTransaction();
        app(RecordFineChequeMovements::class)->handle(
            ChequeRejection::query()->sole(), $this->credito('400000.00'), null, (string) Str::uuid(), $this->quienAbre(),
        );

        $worker = $this->esperando(['mode' => 'void', 'payment' => $pago->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and(implode(' ', array_merge(...array_values($resultado['errors']))))->toContain('El banco acreditó')
            ->and($pago->refresh()->status)->toBe(FinePaymentStatus::Applied)
            ->and(ChequeRejection::query()->sole()->bank_status)->toBe(ChequeRejectionBankStatus::DebitPending);
    }

    // ── Escenario ───────────────────────────────────────────────────────

    private function registrar(string $expediente): Fine
    {
        return app(RegisterFine::class)->handle([
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°'.random_int(1000, 9999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°'.random_int(100000, 999999), 'issuedOn' => null]],
            'caption' => 'EMPRESA '.$expediente,
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ], $this->quienAbre());
    }

    private function credito(string $importe): BankTransaction
    {
        return $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), $importe);
    }

    private function informar(Fine $multa, string $importe): FinePayment
    {
        return app(ReportFineBankPayment::class)->handle($multa, FinePaymentMedium::BankTransfer, $importe, (string) Str::uuid(), $this->quienAbre());
    }

    /** @return array{FinePayment, BankTransaction} */
    private function verificado(string $importe): array
    {
        $credito = $this->credito($importe);

        return [app(VerifyFineBankPayment::class)->handle($this->informar($this->multa, $importe), $credito->id, $this->quienAbre()), $credito];
    }

    private function corregir(FinePayment $pago, Fine $destino, BankTransaction $credito): FinePaymentCorrection
    {
        return app(CorrectVerifiedFinePayment::class)->handle(
            $pago, $destino, $credito->id, new Reason(FineReasonCode::DataEntryError, 'Se identificó mal'), null, null, $this->quienAbre(),
        );
    }

    private function cheque(): FinePayment
    {
        return app(CollectFinePayment::class)->handle(
            $this->multa, FinePaymentMedium::Cheque, '400000.00', (string) Str::uuid(), $this->quienAbre(),
            cheque: ['number' => 'A-0001', 'bank' => null, 'issueDate' => null],
        );
    }

    private function rechazar(FinePayment $pago): void
    {
        app(RejectFineCheque::class)->handle(
            $pago, BusinessDate::today(), 'Sin fondos', UploadedFile::fake()->image('aviso.jpg'), null, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function chequeRechazado(): FinePayment
    {
        $pago = $this->cheque();
        $this->rechazar($pago);

        return $pago;
    }

    private function chequeDevuelto(): FinePayment
    {
        $pago = $this->cheque();
        app(DepositFineCollection::class)->handle(
            $pago, ['depositDate' => BusinessDate::today()], UploadedFile::fake()->image('ticket.jpg'), (string) Str::uuid(), $this->quienAbre(),
        );
        $this->rechazar($pago);

        return $pago;
    }

    private function anular(FinePayment $pago): void
    {
        app(VoidFineCollection::class)->handle($pago, new Reason(FineReasonCode::MediumChange, 'Paga en efectivo'), $this->quienAbre());
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'correccion-'.Str::uuid();
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
