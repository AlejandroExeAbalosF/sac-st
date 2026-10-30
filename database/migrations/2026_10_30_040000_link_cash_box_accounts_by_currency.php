<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El vínculo de una cuenta con una caja referencia la cuenta y su moneda.
 *
 * La moneda del vínculo la copiaba un trigger y la congelaba
 * `freeze_bank_account_currency`, pero esa guarda no ve un vínculo que otra
 * transacción todavía está creando: con dos procesos, la cuenta pasaba a USD
 * mientras su vínculo nacía en ARS. Con la clave foránea compuesta, como la
 * de `cash_book_openings`, PostgreSQL bloquea la fila de la cuenta: el que
 * llega segundo espera y, al seguir, el par (cuenta, moneda) ya no coincide y
 * se rechaza.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_box_bank_accounts
                DROP CONSTRAINT cash_box_bank_accounts_bank_account_id_fkey,
                ADD CONSTRAINT cash_box_bank_accounts_bank_account_fk
                    FOREIGN KEY (bank_account_id, currency) REFERENCES bank_accounts (id, currency) ON DELETE RESTRICT;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_box_bank_accounts
                DROP CONSTRAINT cash_box_bank_accounts_bank_account_fk,
                ADD CONSTRAINT cash_box_bank_accounts_bank_account_id_fkey
                    FOREIGN KEY (bank_account_id) REFERENCES bank_accounts (id) ON DELETE RESTRICT;
        SQL);
    }
};
