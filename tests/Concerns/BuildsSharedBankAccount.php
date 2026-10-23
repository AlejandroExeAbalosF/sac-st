<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Multas\Actions\OpenFineCashBook;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;

/**
 * Una cuenta bancaria compartida por Multas y Aranceles, sus movimientos y
 * la apertura de la caja de Multas.
 *
 * Los movimientos se crean directo, con su importación, como en
 * `SalidasDelAppendOnlyTest`: lo que se prueba es lo que pasa después.
 */
trait BuildsSharedBankAccount
{
    protected function cuenta(string $numero = '310000999888777', string $moneda = 'ARS'): BankAccount
    {
        return BankAccount::query()->create([
            'label' => "Cta. Cte. {$numero}",
            'bank_name' => 'Banco Macro',
            'account_number' => $numero,
            'currency' => $moneda,
            'is_active' => true,
        ]);
    }

    protected function importacion(BankAccount $cuenta, string $desde = '2026-01-01', string $hasta = '2026-01-01'): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->quienAbre(),
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => $desde,
            'period_to' => $hasta,
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);
    }

    /**
     * Un movimiento con su fila en el extracto. Sin archivo, viene en uno
     * propio que abarca solo su día: no demuestra nada de otras fechas.
     */
    protected function movimiento(
        BankAccount $cuenta,
        string $fecha,
        string $importe,
        TransactionDirection $sentido = TransactionDirection::Credit,
        ?string $saldo = null,
        ?BankStatementImport $importacion = null,
        ?int $fila = null,
    ): BankTransaction {
        $importacion ??= $this->importacion($cuenta, $fecha, $fecha);

        $movimiento = BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $importacion->id,
            'transaction_date' => $fecha,
            'amount' => $importe,
            'direction' => $sentido,
            'description' => 'Transferencia recibida',
            'operation_id' => (string) random_int(100000, 999999),
            'balance_after' => $saldo,
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);

        BankStatementRow::query()->forceCreate([
            'bank_statement_import_id' => $importacion->id,
            'row_number' => $fila ?? ((int) BankStatementRow::query()->where('bank_statement_import_id', $importacion->id)->max('row_number') + 1),
            'raw_data' => [$fecha, $importe],
            'parsed_transaction_date' => $fecha,
            'parsed_amount' => $importe,
            'parsed_direction' => $sentido,
            'parsed_balance_after' => $saldo,
            'parse_status' => 'valid',
            'bank_transaction_id' => $movimiento->id,
            'match_method' => 'imported',
        ]);

        return $movimiento;
    }

    /** La fila con la que otro archivo vuelve a traer un movimiento ya importado. */
    protected function filaRepetida(BankStatementImport $importacion, BankTransaction $movimiento, int $fila): void
    {
        BankStatementRow::query()->forceCreate([
            'bank_statement_import_id' => $importacion->id,
            'row_number' => $fila,
            'raw_data' => [(string) $movimiento->transaction_date?->toDateString(), $movimiento->amount],
            'parsed_transaction_date' => $movimiento->transaction_date?->toDateString(),
            'parsed_amount' => $movimiento->amount,
            'parsed_direction' => $movimiento->direction,
            'parsed_balance_after' => $movimiento->balance_after,
            'parse_status' => 'valid',
            'bank_transaction_id' => $movimiento->id,
            'match_method' => 'fingerprint',
        ]);
    }

    /**
     * Un archivo entero, con los movimientos en orden cronológico. Las filas
     * se numeran como las exporta MacroOnline: lo más nuevo arriba.
     *
     * @param  list<array{0: string, 1: string, 2: TransactionDirection, 3: string|null}>  $movimientos  fecha, importe, sentido, saldo
     * @return list<BankTransaction>
     */
    protected function extracto(BankAccount $cuenta, array $movimientos): array
    {
        $importacion = $this->importacion($cuenta, $movimientos[0][0], $movimientos[count($movimientos) - 1][0]);
        $total = count($movimientos);

        return array_values(array_map(
            fn (array $m, int $i): BankTransaction => $this->movimiento($cuenta, $m[0], $m[1], $m[2], $m[3], $importacion, $total - $i),
            $movimientos,
            array_keys($movimientos),
        ));
    }

    protected function abrirMultas(BankAccount $cuenta, string $fecha = '2026-06-01'): CashBookOpening
    {
        return app(OpenFineCashBook::class)->handle(
            date: CarbonImmutable::parse($fecha),
            bankAccountId: $cuenta->id,
            balances: [],
            denominations: [],
            cheques: [],
            notes: null,
            declaredEmpty: true,
            actorId: $this->quienAbre(),
        );
    }

    protected function cajaDe(string $codigo): int
    {
        return (int) CashBox::query()->where('code', $codigo)->value('id');
    }
}
