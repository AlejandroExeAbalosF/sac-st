<?php

declare(strict_types=1);
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    $traslado = CashToBankTransfer::query()->findOrFail($input['transfer']);
    if ($input['mode'] === 'cancel') {
        app(CancelCashToBankTransfer::class)->handle($traslado, 'Cancelado por el worker', 'worker-cancel-'.$input['tag'], $input['actor']);
    } else {
        $credito = BankTransaction::query()->findOrFail($input['transaction']);
        app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, 'worker-confirm-'.$input['tag'], $input['actor']);
    }
    $result = ['status' => 'done', 'transfer' => CashToBankTransfer::query()->findOrFail($input['transfer'])->status->value];
} catch (ValidationException $exception) {
    $result = ['status' => 'validation', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
