<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cheque sigue al traslado, y cada línea va en la moneda de lo que mueve.
 *
 * **El estado del cheque.** Depositar un cheque lo sacaba de
 * `CHEQUES_IN_CUSTODY` en el libro pero lo dejaba `in_custody` en
 * `fund_receipts`: la planilla de caja lo seguía listando, y lo que el
 * inventario llama «sin detallar» quedaba corto. Ahora el estado se ata al
 * traslado vigente que lo llevó: `deposited` mientras está en tránsito,
 * `cleared` cuando el extracto lo acredita, y de vuelta en custodia si el
 * traslado se cancela. Los cheques ya depositados se corrigen acá.
 *
 * **La moneda.** Varias operaciones escribían sus líneas sin moneda, y
 * `EntryLine` cae en pesos. Con un haber en dólares eso partía el libro:
 * `BENEFICIARY_FUNDS` en pesos para una cuota en dólares. Se exige en la
 * base que una línea con cuota vaya en la moneda del haber, y que las
 * líneas del asiento de una recepción vayan en la moneda de la recepción,
 * que además pasa a ser inmutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertCurrenciesAlreadyCoherent();
        $this->backfillChequeStatus();

        $this->chequeFollowsTransfer();
        $this->lineCurrencyFollowsHaber();
        $this->receiptCurrencyFollowsEvent();
        $this->freezeReceiptCurrency();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS cheque_follows_transfer_item ON cash_to_bank_transfer_items;
            DROP TRIGGER IF EXISTS cheque_follows_transfer_status ON cash_to_bank_transfers;
            DROP TRIGGER IF EXISTS cheque_follows_transfer_receipt ON fund_receipts;
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_item();
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_transfer();
            DROP FUNCTION IF EXISTS cheque_follows_transfer_from_receipt();
            DROP FUNCTION IF EXISTS cheque_follows_transfer(bigint);

            DROP TRIGGER IF EXISTS journal_lines_installment_currency ON journal_lines;
            DROP FUNCTION IF EXISTS journal_lines_installment_currency();

            DROP TRIGGER IF EXISTS fund_receipts_currency_matches_event ON fund_receipts;
            DROP FUNCTION IF EXISTS fund_receipts_currency_matches_event();

            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
        SQL);
    }

    /**
     * Las líneas ya escritas no se pueden corregir —son append-only—, así
     * que si alguna ya contradice la regla la migración se detiene y dice
     * cuántas, en vez de crear una guarda que el libro ya incumple.
     */
    private function assertCurrenciesAlreadyCoherent(): void
    {
        $deCuota = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM journal_lines jl
              JOIN haberes h ON h.id = jl.haber_id
             WHERE jl.currency <> h.currency
        SQL);

        $deRecepcion = (int) DB::scalar(<<<'SQL'
            SELECT count(*)
              FROM fund_receipts fr
              JOIN journal_lines jl ON jl.financial_event_id = fr.financial_event_id
             WHERE jl.currency <> fr.currency
        SQL);

        if ($deCuota > 0 || $deRecepcion > 0) {
            throw new RuntimeException(sprintf(
                'Hay líneas en una moneda distinta de la de su haber (%d) o de su recepción (%d). '
                .'Hay que revisarlas antes de crear la guarda.',
                $deCuota,
                $deRecepcion,
            ));
        }
    }

    /**
     * Los cheques que ya viajaron en un traslado vigente toman el estado
     * de ese traslado. `cheque_status` es la única columna de la recepción
     * que se mueve, así que el append-only lo permite.
     */
    private function backfillChequeStatus(): void
    {
        DB::statement(<<<'SQL'
            UPDATE fund_receipts fr
               SET cheque_status = CASE t.status
                                       WHEN 'deposited' THEN 'deposited'
                                       ELSE 'cleared'
                                   END
              FROM cash_to_bank_transfer_items i
              JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
             WHERE i.fund_receipt_id = fr.id
               AND fr.medium = 'cheque'
               AND t.status IN ('deposited', 'bank_confirmed')
        SQL);
    }

    /**
     * El estado del cheque es el de su traslado vigente.
     *
     * Con uno vigente, `deposited` o `cleared` según si el extracto ya lo
     * acreditó; sin ninguno, no puede figurar en el banco. Se controla
     * desde los tres lados que pueden romperlo —el ítem nuevo, el cambio de
     * estado del traslado y el cambio de estado del cheque—, diferido
     * porque las tres escrituras ocurren en la misma transacción y en
     * cualquier orden.
     */
    private function chequeFollowsTransfer(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cheque_follows_transfer(p_receipt_id bigint) RETURNS void AS $$
            DECLARE
                v_cheque RECORD;
                v_esperado TEXT;
            BEGIN
                SELECT medium, cheque_status, cheque_number INTO v_cheque
                  FROM fund_receipts WHERE id = p_receipt_id;

                IF v_cheque.medium IS DISTINCT FROM 'cheque' THEN
                    RETURN;
                END IF;

                SELECT CASE t.status WHEN 'deposited' THEN 'deposited' ELSE 'cleared' END
                  INTO v_esperado
                  FROM cash_to_bank_transfer_items i
                  JOIN cash_to_bank_transfers t ON t.id = i.cash_to_bank_transfer_id
                 WHERE i.fund_receipt_id = p_receipt_id
                   AND t.status <> 'cancelled'
                 LIMIT 1;

                IF v_esperado IS NOT NULL AND v_cheque.cheque_status IS DISTINCT FROM v_esperado THEN
                    RAISE EXCEPTION 'El cheque % viajó en un traslado vigente: tiene que figurar %, no %.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_esperado, v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;

                IF v_esperado IS NULL AND v_cheque.cheque_status IN ('deposited', 'cleared') THEN
                    RAISE EXCEPTION 'El cheque % figura % sin ningún traslado vigente que lo haya llevado al banco.',
                        coalesce(v_cheque.cheque_number, '(sin número)'), v_cheque.cheque_status
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_item() RETURNS trigger AS $$
            BEGIN
                PERFORM cheque_follows_transfer(NEW.fund_receipt_id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_transfer() RETURNS trigger AS $$
            DECLARE
                v_recepcion bigint;
            BEGIN
                FOR v_recepcion IN
                    SELECT fund_receipt_id FROM cash_to_bank_transfer_items
                     WHERE cash_to_bank_transfer_id = NEW.id
                LOOP
                    PERFORM cheque_follows_transfer(v_recepcion);
                END LOOP;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION cheque_follows_transfer_from_receipt() RETURNS trigger AS $$
            BEGIN
                PERFORM cheque_follows_transfer(NEW.id);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_item
                AFTER INSERT ON cash_to_bank_transfer_items
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_item();

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_status
                AFTER UPDATE OF status ON cash_to_bank_transfers
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_transfer();

            CREATE CONSTRAINT TRIGGER cheque_follows_transfer_receipt
                AFTER UPDATE OF cheque_status ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION cheque_follows_transfer_from_receipt();
        SQL);
    }

    /**
     * Una línea con cuota va en la moneda del haber. Es la que se rompía:
     * el asiento cuadra por moneda igual, así que una cuota en dólares con
     * `BENEFICIARY_FUNDS` en pesos pasaba sin que nada protestara.
     */
    private function lineCurrencyFollowsHaber(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_installment_currency() RETURNS trigger AS $$
            DECLARE
                v_moneda char(3);
            BEGIN
                SELECT currency INTO v_moneda FROM haberes WHERE id = NEW.haber_id;

                IF NEW.currency IS DISTINCT FROM v_moneda THEN
                    RAISE EXCEPTION 'La línea es en % y el haber % es en %.', NEW.currency, NEW.haber_id, v_moneda
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER journal_lines_installment_currency
                BEFORE INSERT ON journal_lines
                FOR EACH ROW
                WHEN (NEW.haber_id IS NOT NULL)
                EXECUTE FUNCTION journal_lines_installment_currency();
        SQL);
    }

    /**
     * Las líneas del asiento de una recepción van en la moneda de la
     * recepción. Es lo que permite que el resto —asignar, desasignar,
     * revertir— tome la moneda de la recepción sin volver a preguntarla.
     * Diferido: el asiento se escribe antes que la fila de la recepción.
     */
    private function receiptCurrencyFollowsEvent(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_currency_matches_event() RETURNS trigger AS $$
            DECLARE
                v_otra char(3);
            BEGIN
                SELECT currency INTO v_otra
                  FROM journal_lines
                 WHERE financial_event_id = NEW.financial_event_id
                   AND currency <> NEW.currency
                 LIMIT 1;

                IF v_otra IS NOT NULL THEN
                    RAISE EXCEPTION 'La recepción es en % y su asiento tiene líneas en %.', NEW.currency, v_otra
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fund_receipts_currency_matches_event
                AFTER INSERT ON fund_receipts
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION fund_receipts_currency_matches_event();
        SQL);
    }

    /** La moneda se suma a los datos de la recepción que no se editan. */
    private function freezeReceiptCurrency(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fund_receipts_append_only() RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        RAISE EXCEPTION 'Una recepcion de fondos no se borra: se revierte.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id
                        OR NEW.amount IS DISTINCT FROM OLD.amount
                        OR NEW.currency IS DISTINCT FROM OLD.currency
                        OR NEW.medium IS DISTINCT FROM OLD.medium
                        OR NEW.received_date IS DISTINCT FROM OLD.received_date
                        OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                        OR NEW.cheque_number IS DISTINCT FROM OLD.cheque_number
                        OR NEW.cheque_bank IS DISTINCT FROM OLD.cheque_bank
                        OR NEW.cheque_issue_date IS DISTINCT FROM OLD.cheque_issue_date
                        OR NEW.origin IS DISTINCT FROM OLD.origin
                        OR NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    THEN
                        RAISE EXCEPTION 'Los datos de una recepcion no se editan: se revierte y se registra de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    IF OLD.reversal_event_id IS NOT NULL
                        AND NEW.reversal_event_id IS DISTINCT FROM OLD.reversal_event_id
                    THEN
                        RAISE EXCEPTION 'Una reversion no se deshace: se registra la recepcion de nuevo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END;
                $$;
        SQL);
    }
};
