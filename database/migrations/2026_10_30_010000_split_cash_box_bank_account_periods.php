<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una cuenta puede volver a una caja: cada vigencia es un período propio.
 *
 * Con un solo vínculo por caja y cuenta, rehabilitar una cuenta dada de baja
 * borraba la baja y devolvía a la caja los créditos del tiempo en que no le
 * correspondía. Ahora cada habilitación es una fila nueva (decisión del
 * usuario, 30/10/2026):
 *
 * - **Los períodos de una cuenta en una caja no se superponen.** Lo controla
 *   un trigger con candado transaccional por caja y cuenta; un `EXCLUDE`
 *   pediría la extensión `btree_gist`, que la app no instala.
 * - **Achicar un período no deja afuera lo que cubría**, salvo que otro
 *   período lo siga cubriendo. La guarda pasa a `AFTER UPDATE` para ver el
 *   estado final.
 *
 * El resto no cambia: `cash_box_account_covers` ya pregunta si algún vínculo
 * cubre la fecha, y la predeterminada sigue siendo un período abierto.
 */
return new class extends Migration
{
    /** La guarda como la dejó `add_cash_box_bank_accounts`: un solo período por cuenta. */
    private const VENTANA_UNICA = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_box_bank_account_window() RETURNS trigger AS $$
            DECLARE v_fecha date;
            BEGIN
                IF NEW.valid_from IS NOT DISTINCT FROM OLD.valid_from AND NEW.valid_until IS NOT DISTINCT FROM OLD.valid_until THEN
                    RETURN NEW;
                END IF;

                SELECT deposit_date INTO v_fecha
                  FROM cash_to_bank_transfers
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id
                   AND ((NEW.valid_from IS NOT NULL AND deposit_date < NEW.valid_from)
                        OR (NEW.valid_until IS NOT NULL AND deposit_date > NEW.valid_until))
                 LIMIT 1;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM bank_transaction_allocations a
                      JOIN bank_transactions t ON t.id = a.bank_transaction_id
                      JOIN financial_events e ON e.id = a.financial_event_id
                     WHERE e.cash_box_id = NEW.cash_box_id AND e.event_type = 'funds_received'
                       AND a.reversal_of_id IS NULL AND t.bank_account_id = NEW.bank_account_id
                       AND ((NEW.valid_from IS NOT NULL AND t.transaction_date < NEW.valid_from)
                            OR (NEW.valid_until IS NOT NULL AND t.transaction_date > NEW.valid_until))
                     LIMIT 1;
                END IF;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM fine_historical_movements h
                      JOIN bank_transactions t ON t.id = h.bank_transaction_id
                      JOIN cash_boxes c ON c.id = NEW.cash_box_id AND c.code = 'multas'
                     WHERE h.status <> 'rejected' AND t.bank_account_id = NEW.bank_account_id
                       AND ((NEW.valid_from IS NOT NULL AND t.transaction_date < NEW.valid_from)
                            OR (NEW.valid_until IS NOT NULL AND t.transaction_date > NEW.valid_until))
                     LIMIT 1;
                END IF;

                IF v_fecha IS NOT NULL THEN
                    RAISE EXCEPTION 'La vigencia dejaría afuera lo registrado el % con esta cuenta en esta caja.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    /** La guarda por período: lo que este cubría y ningún otro cubre. */
    private const VENTANA_POR_PERIODO = <<<'SQL'
            CREATE OR REPLACE FUNCTION cash_box_bank_account_window() RETURNS trigger AS $$
            DECLARE v_fecha date;
            BEGIN
                IF NEW.valid_from IS NOT DISTINCT FROM OLD.valid_from AND NEW.valid_until IS NOT DISTINCT FROM OLD.valid_until THEN
                    RETURN NULL;
                END IF;

                -- Lo que el período cubría antes del cambio y ningún período cubre después.
                SELECT deposit_date INTO v_fecha
                  FROM cash_to_bank_transfers
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id
                   AND (OLD.valid_from IS NULL OR deposit_date >= OLD.valid_from)
                   AND (OLD.valid_until IS NULL OR deposit_date <= OLD.valid_until)
                   AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, deposit_date)
                 LIMIT 1;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM bank_transaction_allocations a
                      JOIN bank_transactions t ON t.id = a.bank_transaction_id
                      JOIN financial_events e ON e.id = a.financial_event_id
                     WHERE e.cash_box_id = NEW.cash_box_id AND e.event_type = 'funds_received'
                       AND a.reversal_of_id IS NULL AND t.bank_account_id = NEW.bank_account_id
                       AND (OLD.valid_from IS NULL OR t.transaction_date >= OLD.valid_from)
                       AND (OLD.valid_until IS NULL OR t.transaction_date <= OLD.valid_until)
                       AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, t.transaction_date)
                     LIMIT 1;
                END IF;

                IF v_fecha IS NULL THEN
                    SELECT t.transaction_date INTO v_fecha
                      FROM fine_historical_movements h
                      JOIN bank_transactions t ON t.id = h.bank_transaction_id
                      JOIN cash_boxes c ON c.id = NEW.cash_box_id AND c.code = 'multas'
                     WHERE h.status <> 'rejected' AND t.bank_account_id = NEW.bank_account_id
                       AND (OLD.valid_from IS NULL OR t.transaction_date >= OLD.valid_from)
                       AND (OLD.valid_until IS NULL OR t.transaction_date <= OLD.valid_until)
                       AND NOT cash_box_account_covers(NEW.cash_box_id, NEW.bank_account_id, t.transaction_date)
                     LIMIT 1;
                END IF;

                IF v_fecha IS NOT NULL THEN
                    RAISE EXCEPTION 'La vigencia dejaría afuera lo registrado el % con esta cuenta en esta caja.', v_fecha
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL;

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE cash_box_bank_accounts DROP CONSTRAINT cash_box_bank_accounts_unique;
            CREATE INDEX cash_box_bank_accounts_box_account ON cash_box_bank_accounts (cash_box_id, bank_account_id, valid_from);

            -- Los períodos de una cuenta en una caja no se superponen.
            CREATE OR REPLACE FUNCTION cash_box_bank_account_periods() RETURNS trigger AS $$
            DECLARE v_otro RECORD;
            BEGIN
                PERFORM pg_advisory_xact_lock(hashtext('sacst.cash_box_bank_accounts'), hashtext(NEW.cash_box_id || ':' || NEW.bank_account_id));

                SELECT valid_from, valid_until INTO v_otro
                  FROM cash_box_bank_accounts
                 WHERE cash_box_id = NEW.cash_box_id AND bank_account_id = NEW.bank_account_id AND id <> NEW.id
                   AND daterange(valid_from, valid_until, '[]') && daterange(NEW.valid_from, NEW.valid_until, '[]')
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION 'Los períodos de una cuenta en la caja no se superponen: ya vale desde % hasta %.',
                        COALESCE(to_char(v_otro.valid_from, 'DD/MM/YYYY'), 'su inicio histórico'),
                        COALESCE(to_char(v_otro.valid_until, 'DD/MM/YYYY'), 'hoy')
                        USING ERRCODE = 'exclusion_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER cash_box_bank_accounts_periods BEFORE INSERT OR UPDATE OF valid_from, valid_until ON cash_box_bank_accounts
                FOR EACH ROW EXECUTE FUNCTION cash_box_bank_account_periods();

            DROP TRIGGER cash_box_bank_accounts_window ON cash_box_bank_accounts;
        SQL);

        DB::unprepared(self::VENTANA_POR_PERIODO);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_box_bank_accounts_window AFTER UPDATE ON cash_box_bank_accounts
                FOR EACH ROW EXECUTE FUNCTION cash_box_bank_account_window();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM cash_box_bank_accounts GROUP BY cash_box_id, bank_account_id HAVING count(*) > 1) THEN
                    RAISE EXCEPTION 'Hay cuentas con más de un período en una caja: un solo vínculo por cuenta no las representa.';
                END IF;
            END;
            $$;

            DROP TRIGGER cash_box_bank_accounts_window ON cash_box_bank_accounts;
            DROP TRIGGER cash_box_bank_accounts_periods ON cash_box_bank_accounts;
            DROP FUNCTION cash_box_bank_account_periods();
            DROP INDEX cash_box_bank_accounts_box_account;
            ALTER TABLE cash_box_bank_accounts ADD CONSTRAINT cash_box_bank_accounts_unique UNIQUE (cash_box_id, bank_account_id);
        SQL);

        DB::unprepared(self::VENTANA_UNICA);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER cash_box_bank_accounts_window BEFORE UPDATE ON cash_box_bank_accounts
                FOR EACH ROW EXECUTE FUNCTION cash_box_bank_account_window();
        SQL);
    }
};
