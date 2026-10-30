<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta vinculada a una caja, o con la que abrió un libro, no cambia de
 * moneda.
 *
 * La guarda solo miraba los movimientos importados: una cuenta en pesos
 * vinculada a una caja pasaba a dólares mientras su vínculo seguía en pesos,
 * y el libro en pesos podía ofrecerla. El vínculo y la apertura toman la
 * moneda de la cuenta al crearse; cambiarla después los contradice.
 */
return new class extends Migration
{
    /** Como la dejó `create_bank_transactions_table`. */
    private const ANTES = <<<'SQL'
            CREATE OR REPLACE FUNCTION freeze_bank_account_currency() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
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
                $$;
        SQL;

    /** También con vínculos de caja o aperturas. */
    private const AHORA = <<<'SQL'
            CREATE OR REPLACE FUNCTION freeze_bank_account_currency() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF NEW.currency IS NOT DISTINCT FROM OLD.currency THEN
                        RETURN NEW;
                    END IF;

                    IF EXISTS (SELECT 1 FROM bank_transactions WHERE bank_account_id = OLD.id) THEN
                        RAISE EXCEPTION
                            'La cuenta ya tiene movimientos importados: cambiar su moneda reinterpretaria todos sus importes.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM cash_box_bank_accounts WHERE bank_account_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM cash_book_openings WHERE bank_account_id = OLD.id) THEN
                        RAISE EXCEPTION
                            'La cuenta está vinculada a una caja en %: cambiar su moneda contradiría ese vínculo.', OLD.currency
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN NEW;
                END;
                $$;
        SQL;

    public function up(): void
    {
        DB::unprepared(self::AHORA);
    }

    public function down(): void
    {
        DB::unprepared(self::ANTES);
    }
};
