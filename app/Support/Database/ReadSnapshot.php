<?php

declare(strict_types=1);

namespace App\Support\Database;

use Generator;
use Illuminate\Support\Facades\DB;

/**
 * Varias consultas que leen el mismo momento de los datos.
 *
 * Una transacción `REPEATABLE READ, READ ONLY`: PostgreSQL fija la foto en la primera
 * consulta y las siguientes leen esa misma foto. Un informe por secciones no puede
 * mostrar un cobro en unas y en otras no porque alguien cobró mientras se armaba.
 * Leer no bloquea a nadie.
 */
final class ReadSnapshot
{
    /**
     * @template T
     *
     * @param  callable(): T  $lectura
     * @return T
     */
    public function run(callable $lectura): mixed
    {
        $propia = $this->abrir();

        try {
            return $lectura();
        } finally {
            $this->cerrar($propia);
        }
    }

    /**
     * Lo mismo para un exporte: la foto dura mientras se escriben las filas.
     *
     * @template TValor
     *
     * @param  callable(): iterable<int, TValor>  $lectura
     * @return Generator<int, TValor>
     */
    public function rows(callable $lectura): Generator
    {
        $propia = $this->abrir();

        try {
            foreach ($lectura() as $fila) {
                yield $fila;
            }
        } finally {
            $this->cerrar($propia);
        }
    }

    private function abrir(): bool
    {
        $propia = DB::transactionLevel() === 0;

        if ($propia) {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }

        return $propia;
    }

    /** Solo se leyó: no hay nada que confirmar. */
    private function cerrar(bool $propia): void
    {
        if ($propia) {
            DB::rollBack();
        }
    }
}
