<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Models\Fine;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * La cuenta corriente filtrada: la misma para la pantalla y para el exporte.
 *
 * Lo último que se registró va primero y los históricos sin fecha, al
 * final. El orden se escribe con `COALESCE(..., '-infinity')` y no con
 * `NULLS LAST` porque el exporte recorre por cursor, y una comparación de
 * filas no sabe de `NULLS LAST`: con el mismo valor en el orden y en el
 * cursor, un bloque arranca exactamente donde terminó el anterior.
 *
 * @phpstan-type Filters array{q: string, estado: string, anio: int|null}
 */
final class FineListQuery
{
    private const ORDEN = "COALESCE(fines.registered_on, '-infinity'::date)";

    /**
     * @param  Filters  $filtros
     * @return Builder<Fine>
     */
    public function query(array $filtros): Builder
    {
        return Fine::query()
            ->select('fines.*')
            ->join('fine_balances', 'fine_balances.fine_id', '=', 'fines.id')
            ->with([
                'fineCase:id,original_text,number,year,suffix',
                'person:id,name',
                'documents',
                'balance',
            ])
            ->buscar($filtros['q'])
            ->when($filtros['estado'] === 'con-deuda', fn (Builder $query) => $query->where('fine_balances.debt', '>', 0))
            ->when($filtros['estado'] === 'sin-deuda', fn (Builder $query) => $query->where('fine_balances.debt', '=', 0))
            ->when($filtros['anio'] !== null, fn (Builder $query) => $query->whereYear('fines.registered_on', (int) $filtros['anio']))
            ->orderByRaw(self::ORDEN.' DESC')
            ->orderByDesc('fines.id');
    }

    /**
     * Todas las filas, en bloques, para el exporte.
     *
     * Todos los bloques, con sus relaciones, se leen en una sola
     * transacción `REPEATABLE READ, READ ONLY`: PostgreSQL fija la foto de
     * los datos en la primera consulta y los bloques siguientes leen esa
     * misma foto. Sin eso, el cursor —que usa la fecha de registro, un dato
     * corregible— se cruzaba con una corrección hecha durante la descarga:
     * la multa movida detrás del cursor salía dos veces, y la movida delante,
     * ninguna. Lo mismo con los saldos y los filtros. Leer no bloquea a
     * nadie: quien corrige mientras tanto no espera.
     *
     * La transacción se abre en la primera vuelta del generador, ya dentro
     * de la respuesta en streaming, y se cierra al terminar o al abandonarse
     * el recorrido. Si ya hay una transacción abierta —solo pasa en los
     * tests con `RefreshDatabase`—, el aislamiento lo fijó quien la abrió.
     *
     * @param  Filters  $filtros
     * @return Generator<int, Collection<int, Fine>>
     */
    public function chunks(array $filtros, int $size = 500): Generator
    {
        $propia = DB::transactionLevel() === 0;

        if ($propia) {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        try {
            $cursor = null;

            do {
                $bloque = $this->query($filtros)
                    ->when($cursor !== null, fn (Builder $query) => $query
                        ->whereRaw('('.self::ORDEN.', fines.id) < (?::date, ?)', $cursor))
                    ->limit($size)
                    ->get();

                if ($bloque->isEmpty()) {
                    return;
                }

                yield $bloque;

                /** @var Fine $ultima */
                $ultima = $bloque->last();
                $cursor = [$ultima->getRawOriginal('registered_on') ?? '-infinity', $ultima->id];
            } while ($bloque->count() === $size);
        } finally {
            // Solo se leyó: no hay nada que confirmar.
            if ($propia) {
                DB::rollBack();
            }
        }
    }
}
