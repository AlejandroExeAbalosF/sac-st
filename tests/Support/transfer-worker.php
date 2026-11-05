<?php

declare(strict_types=1);
use App\Modules\Aranceles\Actions\IdentifyFeeCredits;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\LinkBankAccountToCashBox;
use App\Modules\Banking\Actions\RecordTransferTicket;
use App\Modules\Banking\Actions\RejectCheque;
use App\Modules\Banking\Actions\ReplaceRejectedCheque;
use App\Modules\Banking\Actions\RetireCashBoxBankAccount;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\CorrectVerifiedFinePayment;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Actions\RecordFineChequeMovements;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ReplaceFineCheque;
use App\Modules\Multas\Actions\VerifyFineBankPayment;
use App\Modules\Multas\Actions\VoidFineCollection;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    } elseif ($input['mode'] === 'appeal') {
        // Registrar el recurso de una multa mientras otro la cobra.
        $pago = app(RegisterFineAppeal::class)->handle(
            Fine::query()->findOrFail($input['fine']), AppealKind::Hierarchical, FinePaymentMedium::Cash, null,
            $input['amount'], 'worker-appeal-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'appeal' => $pago->fine_appeal_id];
    } elseif ($input['mode'] === 'raw-appeal') {
        // El mismo trámite abierto directo: la base sola tiene que frenarlo.
        DB::table('fine_appeals')->insert(['fine_id' => $input['fine'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'collect') {
        // Cobrar en la caja una multa mientras otro registra su recurso.
        $pago = app(CollectFinePayment::class)->handle(
            Fine::query()->findOrFail($input['fine']), FinePaymentMedium::Cash, $input['amount'], 'worker-collect-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'payment' => $pago->id];
    } elseif ($input['mode'] === 'correct') {
        // Corregir un pago verificado mientras otro lo corrige o usa ese crédito.
        $correccion = app(CorrectVerifiedFinePayment::class)->handle(
            FinePayment::query()->findOrFail($input['payment']), Fine::query()->findOrFail($input['fine']), $input['credit'],
            new Reason(FineReasonCode::DataEntryError, 'Worker'), null, null, $input['actor'],
        );
        $result = ['status' => 'done', 'correction' => $correccion->id];
    } elseif ($input['mode'] === 'verify') {
        // Verificar un pago con un crédito mientras una corrección lo toma.
        app(VerifyFineBankPayment::class)->handle(FinePayment::query()->findOrFail($input['payment']), $input['credit'], $input['actor']);
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'fine-replace') {
        // Recibir el reemplazo mientras otro anula el cobro.
        $reemplazo = app(ReplaceFineCheque::class)->handle(
            ChequeRejection::query()->findOrFail($input['rejection']),
            ['number' => 'W-'.$input['tag'], 'bank' => null, 'issueDate' => null],
            'worker-fine-replace-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'replacement' => $reemplazo->id];
    } elseif ($input['mode'] === 'cheque-credit') {
        // Registrar la acreditación tardía mientras otro anula el cobro.
        config(['multas.cheque_bank_followup_enabled' => true]);
        $rechazo = app(RecordFineChequeMovements::class)->handle(
            ChequeRejection::query()->findOrFail($input['rejection']), BankTransaction::query()->findOrFail($input['credit']), null,
            'worker-cheque-credit-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'bank' => $rechazo->bank_status->value];
    } elseif ($input['mode'] === 'void') {
        // Anular un cobro mientras otro lo deposita.
        app(VoidFineCollection::class)->handle(
            FinePayment::query()->findOrFail($input['payment']), new Reason(FineReasonCode::DataEntryError, 'Worker'), $input['actor'],
        );
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'deposit') {
        // Depositar un cobro mientras otro lo anula.
        app(DepositFineCollection::class)->handle(
            FinePayment::query()->findOrFail($input['payment']), ['depositDate' => BusinessDate::today()],
            UploadedFile::fake()->image('ticket.jpg'), 'worker-deposit-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'fee-transfer') {
        // Traspasar el efectivo de Aranceles mientras otro lo traspasa o lo anula.
        $traslado = app(StartCashTransfer::class)->handle(
            $input['box'], Currency::Ars, $input['amount'], BusinessDate::today(), null, null, $input['key'] ?? 'worker-fee-transfer-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done', 'transfer' => $traslado->id];
    } elseif ($input['mode'] === 'fee-identify') {
        // Identificar un crédito mientras otro lo identifica o anula el cobro.
        $cobro = app(IdentifyFeeCredits::class)->handle(
            FeeCharge::query()->findOrFail($input['charge']), $input['credits'], 'worker-fee-identify-'.$input['tag'], null, $input['actor'],
        );
        $result = ['status' => 'done', 'charge' => $cobro->status->value];
    } elseif ($input['mode'] === 'fee-void') {
        // Anular un cobro de Aranceles mientras otro traspasa ese efectivo.
        app(VoidFeeCollection::class)->handle(
            FeeCharge::query()->findOrFail($input['charge']), new Reason(FeeReasonCode::DataEntryError, null), $input['actor'],
        );
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'fee-ticket') {
        // Cargar el comprobante mientras otro cancela el traspaso.
        $traslado = CashToBankTransfer::query()->findOrFail($input['transfer']);
        app(RecordTransferTicket::class)->handle(
            $traslado, $traslado->amount, ['depositDate' => BusinessDate::today()],
            UploadedFile::fake()->image('comprobante.jpg'), 'worker-fee-ticket-'.$input['tag'], $input['actor'],
        );
        $result = ['status' => 'done'];
    } elseif ($input['mode'] === 'raw-void') {
        // La fila de anulación sin pasar por el Action: la base sola tiene que frenarla.
        DB::transaction(function () use ($input): void {
            $pago = FinePayment::query()->findOrFail($input['payment']);
            $aplicacion = $pago->application()->firstOrFail();
            $recepcion = $aplicacion->fundReceipt()->firstOrFail();
            $revertir = fn (int $evento): int => (int) DB::table('financial_events')->insertGetId([
                'public_id' => (string) Str::ulid(), 'cash_box_id' => $recepcion->cash_box_id, 'event_type' => 'reversal',
                'event_date' => BusinessDate::today()->toDateString(), 'status' => 'posted', 'idempotency_key' => (string) Str::uuid(),
                'reversal_of_id' => $evento, 'reversal_reason' => 'Worker', 'created_by' => $input['actor'], 'posted_by' => $input['actor'],
                'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('fine_payment_voids')->insert([
                'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'data_entry_error',
                'application_reversal_event_id' => $revertir($aplicacion->application_event_id),
                'funds_exit_event_id' => $revertir($recepcion->financial_event_id),
            ]);
        });
        $result = ['status' => 'done'];
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
