<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El primer crédito sigue en deposit_tickets para conservar los cruces existentes.
        Schema::create('deposit_ticket_additional_transactions', function (Blueprint $table): void {
            $table->foreignId('deposit_ticket_id')->constrained('deposit_tickets')->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->unique()->constrained('bank_transactions')->restrictOnDelete();
            $table->jsonb('match_signals')->nullable();
            $table->primary(['deposit_ticket_id', 'bank_transaction_id'], 'deposit_ticket_additional_pk');
        });

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_deposit_ticket_match_total() RETURNS trigger AS $$
            DECLARE
                v_ticket_id bigint;
                v_ticket record;
                v_total numeric;
            BEGIN
                IF TG_TABLE_NAME = 'deposit_tickets' THEN
                    v_ticket_id := NEW.id;
                ELSIF TG_OP = 'DELETE' THEN
                    v_ticket_id := OLD.deposit_ticket_id;
                ELSE
                    v_ticket_id := NEW.deposit_ticket_id;
                END IF;
                SELECT * INTO v_ticket FROM deposit_tickets WHERE id = v_ticket_id;
                IF NOT FOUND THEN RETURN NULL; END IF;

                IF v_ticket.bank_transaction_id IS NOT NULL AND EXISTS (
                    SELECT 1 FROM deposit_ticket_additional_transactions
                    WHERE bank_transaction_id = v_ticket.bank_transaction_id
                ) THEN
                    RAISE EXCEPTION 'Un movimiento no puede estar dos veces en un comprobante.';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM deposit_ticket_additional_transactions a
                    JOIN deposit_tickets t ON t.bank_transaction_id = a.bank_transaction_id
                    WHERE a.deposit_ticket_id = v_ticket_id
                ) THEN
                    RAISE EXCEPTION 'Un movimiento ya pertenece a otro comprobante.';
                END IF;

                SELECT COALESCE((SELECT amount FROM bank_transactions
                    WHERE id = v_ticket.bank_transaction_id), 0)
                    + COALESCE((SELECT SUM(b.amount)
                        FROM deposit_ticket_additional_transactions a
                        JOIN bank_transactions b ON b.id = a.bank_transaction_id
                        WHERE a.deposit_ticket_id = v_ticket_id), 0)
                INTO v_total;

                IF v_total > v_ticket.amount OR (v_ticket.status = 'matched' AND v_total <> v_ticket.amount) THEN
                    RAISE EXCEPTION 'La suma de los movimientos (%) debe coincidir con el comprobante (%).',
                        v_total, v_ticket.amount;
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql
        SQL);
        DB::statement('CREATE CONSTRAINT TRIGGER deposit_ticket_match_total AFTER INSERT OR UPDATE ON deposit_tickets DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_deposit_ticket_match_total()');
        DB::statement('CREATE CONSTRAINT TRIGGER deposit_ticket_extra_match_total AFTER INSERT OR UPDATE OR DELETE ON deposit_ticket_additional_transactions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_deposit_ticket_match_total()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER deposit_ticket_extra_match_total ON deposit_ticket_additional_transactions');
        DB::statement('DROP TRIGGER deposit_ticket_match_total ON deposit_tickets');
        DB::statement('DROP FUNCTION check_deposit_ticket_match_total()');
        Schema::dropIfExists('deposit_ticket_additional_transactions');
    }
};
