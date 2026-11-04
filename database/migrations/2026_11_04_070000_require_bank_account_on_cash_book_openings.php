<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toda apertura dice con qué cuenta opera su libro, en cualquier moneda.
 *
 * Sin cuenta, el renglón de depósitos directos quedaba apagado y el saldo del
 * banco afuera de una apertura que no se rehace; y sin vínculo, la caja
 * aceptaba depósitos a cualquier cuenta activa.
 *
 * `NOT VALID` a propósito: la regla rige para toda fila nueva sin tocar una
 * apertura de prueba ya hecha sin cuenta. Esas filas son append-only, no se
 * pueden completar, y no deben trabar la migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE cash_book_openings ADD CONSTRAINT cash_book_openings_bank_account_required
            CHECK (bank_account_id IS NOT NULL) NOT VALID');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cash_book_openings DROP CONSTRAINT cash_book_openings_bank_account_required');
    }
};
