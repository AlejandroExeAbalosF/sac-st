<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La moneda de una cuenta se congela apenas tiene movimientos.
 *
 * §4.4 del DER decide que la moneda vive donde se establece y se hereda
 * hacia abajo: `bank_transactions` no lleva columna propia, es de la
 * moneda de su cuenta. Esa decisión es correcta —evita redundar y evita
 * inconsistencias— pero tiene una contracara que el DER no menciona:
 * **cambiar la moneda de la cuenta reinterpreta todo su pasado**. Los
 * mismos importes que ayer eran pesos hoy se leen como dólares, sin que
 * nada haya cambiado en el banco.
 *
 * No alcanza con validarlo en el formulario. Es la regla que sostiene el
 * invariante de §4.4 —nunca se suman ni se comparan importes de monedas
 * distintas— y una carga masiva o un `UPDATE` a mano la saltearían.
 *
 * Un `CHECK` no sirve: mira una sola fila y esto exige consultar otra
 * tabla. Va como trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION freeze_bank_account_currency() RETURNS trigger AS $$
            BEGIN
                IF NEW.currency IS DISTINCT FROM OLD.currency
                    AND EXISTS (SELECT 1 FROM bank_transactions WHERE bank_account_id = OLD.id)
                THEN
                    RAISE EXCEPTION
                        'La cuenta ya tiene movimientos importados: cambiar su moneda reinterpretaria todos sus importes.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER bank_accounts_freeze_currency
            BEFORE UPDATE ON bank_accounts
            FOR EACH ROW EXECUTE FUNCTION freeze_bank_account_currency()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS bank_accounts_freeze_currency ON bank_accounts');
        DB::statement('DROP FUNCTION IF EXISTS freeze_bank_account_currency()');
    }
};
