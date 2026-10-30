<?php

declare(strict_types=1);
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\LinkBankAccountToCashBox;
use App\Modules\Banking\Actions\RejectCheque;
use App\Modules\Banking\Actions\ReplaceRejectedCheque;
use App\Modules\Banking\Actions\RetireCashBoxBankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\RawCashDeposit;

// Proceso independiente para concurrencia real sobre un traslado; recibe credenciales solo por stdin.
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (($input['database']['database'] ?? null) !== 'sacst_test') {
    throw new RuntimeException('El worker solo admite sacst_test.');
}
foreach (['APP_ENV' => 'testing', 'APP_TIMEZONE' => 'UTC', 'DB_TIMEZONE' => 'UTC', 'AUDIT_LOG_CONSOLE' => 'false'] as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key.'='.$value);
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'pgsql', 'database.connections.pgsql' => $input['database'], 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('pgsql');
DB::select("SELECT set_config('application_name', ?, false)", [$input['tag']]);
DB::statement("SET lock_timeout = '15s'");
DB::statement("SET statement_timeout = '20s'");
echo json_encode(['ready' => DB::scalar('SELECT pg_backend_pid()')]).PHP_EOL;
flush();
try {
    if ($input['mode'] === 'reject') {
        $rechazo = app(RejectCheque::class)->handle(
            FundReceipt::query()->findOrFail($input['receipt']), BusinessDate::today(), 'Rechazado por el worker',
            UploadedFile::fake()->image('aviso.jpg'), null, 'worker-reject-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'bank' => $rechazo->refresh()->bank_status->value];
    } elseif ($input['mode'] === 'replace') {
        $reemplazo = app(ReplaceRejectedCheque::class)->handle(
            ChequeRejection::query()->findOrFail($input['rejection']),
            ['number' => 'W-'.$input['tag'], 'bank' => null, 'issueDate' => null],
            'worker-replace-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'replacement' => $reemplazo->id];
    } elseif ($input['mode'] === 'link') {
        // Vincular una cuenta a una caja mientras otro le cambia la moneda.
        $vinculo = app(LinkBankAccountToCashBox::class)->handle($input['box'], $input['account'], null, false, $input['actor']);
        $result = ['status' => 'done', 'currency' => $vinculo->currency];
    } elseif ($input['mode'] === 'raw-link') {
        // El mismo vínculo escrito directo: la base sola tiene que frenarlo.
        DB::table('cash_box_bank_accounts')->insert([
            'cash_box_id' => $input['box'], 'bank_account_id' => $input['account'], 'currency' => 'ARS', 'is_default' => false,
        ]);
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'retire') {
        // Dar de baja una cuenta de la caja mientras otro la usa.
        $vinculo = app(RetireCashBoxBankAccount::class)->handle(
            CashBoxBankAccount::query()->findOrFail($input['link']), CarbonImmutable::parse($input['until']),
            $input['newDefault'], $input['actor'],
        );
        $result = ['status' => 'done', 'until' => $vinculo->valid_until?->toDateString()];
    } elseif ($input['mode'] === 'raw-deposit') {
        // Un escritor que no pasa por el Action: la base sola tiene que frenarlo.
        DB::transaction(function () use ($input): void {
            RawCashDeposit::write($input['receipt'], $input['amount'], $input['account'], $input['box'], $input['actor']);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        });
        $result = ['status' => 'done'];
    } else {
        $traslado = CashToBankTransfer::query()->findOrFail($input['transfer']);
        if ($input['mode'] === 'cancel') {
            app(CancelCashToBankTransfer::class)->handle($traslado, 'Cancelado por el worker', 'worker-cancel-'.$input['tag'], $input['actor']);
        } else {
            $credito = BankTransaction::query()->findOrFail($input['transaction']);
            app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, 'worker-confirm-'.$input['tag'], $input['actor']);
        }
        $result = ['status' => 'done', 'transfer' => CashToBankTransfer::query()->findOrFail($input['transfer'])->status->value];
    }
} catch (QueryException $exception) {
    $result = ['status' => 'database', 'message' => $exception->getMessage()];
} catch (ValidationException $exception) {
    $result = ['status' => 'validation', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
