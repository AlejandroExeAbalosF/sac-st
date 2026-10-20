<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\ImportStatus;
use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Models\BankStatementRow;
use App\Modules\Banking\Models\BankTransaction;

/**
 * El orden bancario de los movimientos de un día, según lo que demuestran
 * los extractos importados.
 *
 * Cada archivo dice en qué orden pasaron sus filas de ese día —el de
 * `ParsedStatement::chronologicalRows`: de abajo hacia arriba, porque
 * MacroOnline exporta lo más nuevo arriba—, y los movimientos que dos
 * archivos comparten unen esos órdenes. Si de todo eso sale **un único
 * orden posible**, es el del día. Si no —dos archivos del mismo día sin
 * ningún movimiento en común—, el día queda indeterminado: nadie puede
 * afirmar cuál fue el último, y ni el saldo de cierre ni un salto se
 * deducen de él.
 *
 * Ni el `id` del movimiento ni el del archivo ordenan nada: dicen cuándo
 * entró a la base, no cuándo pasó en el banco. Un extracto viejo importado
 * después no hace retroceder lo que ya se sabía.
 */
final class BankDayOrder
{
    /**
     * Los movimientos del día en el orden del banco, o `null` si los
     * extractos no alcanzan para saberlo.
     *
     * @return list<BankTransaction>|null
     */
    public function of(int $bankAccountId, string $day): ?array
    {
        $filas = BankStatementRow::query()
            ->select(['bank_statement_rows.bank_statement_import_id', 'bank_statement_rows.bank_transaction_id'])
            ->join('bank_statement_imports', 'bank_statement_imports.id', '=', 'bank_statement_rows.bank_statement_import_id')
            ->where('bank_statement_imports.bank_account_id', $bankAccountId)
            ->where('bank_statement_imports.status', ImportStatus::Completed->value)
            ->where('bank_statement_rows.parse_status', '<>', ParseStatus::Rejected->value)
            ->whereNotNull('bank_statement_rows.bank_transaction_id')
            ->where('bank_statement_rows.parsed_transaction_date', $day)
            ->orderBy('bank_statement_rows.bank_statement_import_id')
            ->orderByDesc('bank_statement_rows.row_number')
            ->get();

        /** @var array<int, true> $nodos */
        $nodos = [];
        /** @var array<int, array<int, true>> $siguientes */
        $siguientes = [];
        $anterior = null;

        foreach ($filas as $fila) {
            $id = (int) $fila->bank_transaction_id;
            $nodos[$id] = true;

            // Dentro de un archivo, cada fila va después de la anterior.
            if ($anterior !== null && $anterior['archivo'] === $fila->bank_statement_import_id && $anterior['id'] !== $id) {
                $siguientes[$anterior['id']][$id] = true;
            }

            $anterior = ['archivo' => $fila->bank_statement_import_id, 'id' => $id];
        }

        $orden = $this->unique(array_keys($nodos), $siguientes);

        if ($orden === null) {
            return null;
        }

        $movimientos = BankTransaction::query()->whereKey($orden)->get()->keyBy('id');

        return array_map(fn (int $id): BankTransaction => $movimientos[$id], $orden);
    }

    /**
     * El orden topológico, si es único: en cada paso tiene que haber un solo
     * movimiento sin predecesores pendientes. Dos a la vez es no saber cuál
     * fue primero; ninguno, un ciclo que ningún banco produce.
     *
     * @param  list<int>  $nodos
     * @param  array<int, array<int, true>>  $siguientes
     * @return list<int>|null
     */
    private function unique(array $nodos, array $siguientes): ?array
    {
        $entrantes = array_fill_keys($nodos, 0);

        foreach ($siguientes as $destinos) {
            foreach (array_keys($destinos) as $destino) {
                $entrantes[$destino]++;
            }
        }

        $orden = [];

        while ($entrantes !== []) {
            $libres = array_keys(array_filter($entrantes, fn (int $n): bool => $n === 0));

            if (count($libres) !== 1) {
                return null;
            }

            $actual = $libres[0];
            $orden[] = $actual;
            unset($entrantes[$actual]);

            foreach (array_keys($siguientes[$actual] ?? []) as $destino) {
                $entrantes[$destino]--;
            }
        }

        return $orden;
    }
}
