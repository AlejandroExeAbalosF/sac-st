<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De qué efectivo se compone un traslado — §9.5 del DER.
 *
 * El traslado dice cuánto salió de la caja; esto dice de quién era. Sin
 * los ítems, depositar efectivo perdería el vínculo con el beneficiario y
 * la cuota, y el dinero volvería a ser anónimo justo después de haber
 * dejado de serlo (§2.1, punto 146).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_to_bank_transfer_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('cash_to_bank_transfer_id')->constrained('cash_to_bank_transfers')->restrictOnDelete();
            $table->foreignId('fund_receipt_id')->constrained('fund_receipts')->restrictOnDelete();

            /*
             * La asignación que ese efectivo financiaba. Admite `null`
             * porque el DER contempla trasladar efectivo todavía sin
             * imputar; en este circuito siempre viene con la suya.
             */
            $table->foreignId('funding_allocation_id')->nullable()->constrained('funding_allocations')->restrictOnDelete();

            $table->decimal('amount', 19, 2);

            $table->timestamps();

            $table->index('fund_receipt_id');
        });

        DB::statement('ALTER TABLE cash_to_bank_transfer_items ADD CONSTRAINT cash_to_bank_transfer_items_amount_check
            CHECK (amount > 0)');

        DB::statement('CREATE INDEX cash_to_bank_transfer_items_allocation_index
            ON cash_to_bank_transfer_items (funding_allocation_id)
            WHERE funding_allocation_id IS NOT NULL');

        /*
         * **El mismo efectivo no se deposita dos veces.**
         *
         * Es el invariante que impide duplicar plata: sin esto, dos
         * traslados sobre la misma asignacion sacarian de la caja el doble
         * de lo que la caja tenia, y el arqueo cerraria en negativo sin
         * que nadie supiera por que.
         *
         * Va como trigger y no como indice unico parcial porque el
         * predicado tiene que mirar el estado del traslado —uno cancelado
         * libera su efectivo—, y Postgres no admite subconsultas ahi.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_deposited_once() RETURNS trigger AS $$
            DECLARE
                vigentes integer;
            BEGIN
                IF NEW.funding_allocation_id IS NULL THEN
                    RETURN NULL;
                END IF;

                SELECT count(*) INTO vigentes
                FROM cash_to_bank_transfer_items i
                JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                WHERE i.funding_allocation_id = NEW.funding_allocation_id
                  AND t.status <> 'cancelled';

                IF vigentes > 1 THEN
                    RAISE EXCEPTION
                        'Ese efectivo ya se deposito en un traslado vigente.'
                        USING ERRCODE = 'unique_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_deposited_once
            AFTER INSERT ON cash_to_bank_transfer_items
            FOR EACH ROW EXECUTE FUNCTION cash_deposited_once()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_to_bank_transfer_items_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un item de traslado no se borra: se cancela el traslado.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un item de traslado no se edita: se cancela el traslado.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER cash_to_bank_transfer_items_append_only
            BEFORE UPDATE OR DELETE ON cash_to_bank_transfer_items
            FOR EACH ROW EXECUTE FUNCTION cash_to_bank_transfer_items_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS cash_deposited_once ON cash_to_bank_transfer_items');
        DB::statement('DROP FUNCTION IF EXISTS cash_deposited_once()');
        DB::statement('DROP TRIGGER IF EXISTS cash_to_bank_transfer_items_append_only ON cash_to_bank_transfer_items');
        DB::statement('DROP FUNCTION IF EXISTS cash_to_bank_transfer_items_append_only()');

        Schema::dropIfExists('cash_to_bank_transfer_items');
    }
};
