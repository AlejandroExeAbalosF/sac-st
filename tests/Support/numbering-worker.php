<?php

declare(strict_types=1);
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Enums\DocumentType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\NumberingFixture;

// Proceso independiente para concurrencia real; recibe credenciales solo por stdin.
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
    $type = DocumentType::from($input['type']);
    if ($input['mode'] === 'configure') {
        $series = app(ConfigureDocumentNumbering::class)->handle($type, $input['settings'], $input['actor']);
        $result = ['status' => 'configured', 'series' => $series->id];
    } else {
        $fixture = new NumberingFixture($input['actor']);
        $document = $fixture->emit($type, BeneficiaryInstallment::query()->findOrFail($input['installment']));
        $result = ['status' => 'emitted', 'id' => $document->id, 'number' => $document->number, 'formatted' => $document->formatted_number, 'series' => $document->document_series_id];
    }
} catch (ValidationException $exception) {
    $result = ['status' => 'validation', 'errors' => $exception->errors()];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'class' => $exception::class, 'message' => $exception->getMessage()];
}
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
