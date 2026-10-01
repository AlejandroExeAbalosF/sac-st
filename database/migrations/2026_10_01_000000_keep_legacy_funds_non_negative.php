<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Del sistema anterior no se paga más de lo que se declaró al abrir los libros.
 *
 * `PayLegacyBeneficiary` lo comprobaba antes de escribir, pero solo en PHP y
 * sin bloquear nada: dos pagos simultáneos leían el mismo pendiente y
 * pasaban los dos, y una escritura que no entrara por el Action no
 * encontraba ninguna guarda. Un saldo negativo de `LEGACY_FUNDS` diría que
 * se entregó plata vieja que nunca estuvo.
 *
 * El trigger es **diferido** porque mira el saldo del asiento entero, y
 * toma un **bloqueo** por caja y moneda antes de sumar: la segunda
 * transacción espera a que la primera confirme y, como en `READ COMMITTED`
 * cada consulta ve lo ya confirmado, suma con el consumo de la otra. Es un
 * bloqueo consultivo propio, como el de `ensure_an_active_administrator`,
 * y no la fila de la caja: así serializa solo los consumos de plata vieja y
 * vale también para una escritura que no pasó por `PostJournalEntry`.
 *
 * Solo controla los débitos: acreditar `LEGACY_FUNDS` nunca lo deja
 * negativo. Incluye las líneas de eventos posteados y revertidos, igual que
 * `CashBalance`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $negativos = DB::scalar(<<<'SQL'
            SELECT string_agg(format('caja %s en %s: %s', COALESCE(cash_box_id::text, 'sin caja'), currency, saldo), '; ')
              FROM (
                SELECT jl.cash_box_id, jl.currency, SUM(jl.credit) - SUM(jl.debit) AS saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND fe.status <> 'draft'
                 GROUP BY jl.cash_box_id, jl.currency
                HAVING SUM(jl.credit) - SUM(jl.debit) < 0
              ) AS saldos
        SQL);

        if (is_string($negativos)) {
            throw new RuntimeException(
                'LEGACY_FUNDS ya tiene saldo negativo y la guarda no se puede crear: '.$negativos
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_balance_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_saldo NUMERIC(19,2);
            BEGIN
                PERFORM pg_advisory_xact_lock(
                    hashtext('sacst.legacy_funds.' || p_currency),
                    COALESCE(p_cash_box_id, 0)::int
                );

                SELECT COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0)
                  INTO v_saldo
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                 WHERE jl.account_code = 'LEGACY_FUNDS'
                   AND jl.cash_box_id IS NOT DISTINCT FROM p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft';

                IF v_saldo < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto por pagar: el saldo en % quedaria en %.',
                        p_currency, v_saldo
                        USING ERRCODE = 'check_violation';
                END IF;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION legacy_funds_line_non_negative() RETURNS trigger AS $$
            BEGIN
                PERFORM legacy_funds_balance_check(NEW.cash_box_id, NEW.currency);

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER journal_lines_legacy_funds_non_negative
                AFTER INSERT ON journal_lines
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (NEW.account_code = 'LEGACY_FUNDS' AND NEW.debit > 0)
                EXECUTE FUNCTION legacy_funds_line_non_negative();

            -- Un borrador no cuenta: el débito pesa recién cuando el evento se postea.
            CREATE OR REPLACE FUNCTION legacy_funds_event_non_negative() RETURNS trigger AS $$
            DECLARE
                v_linea RECORD;
            BEGIN
                FOR v_linea IN
                    SELECT DISTINCT cash_box_id, currency
                      FROM journal_lines
                     WHERE financial_event_id = NEW.id
                       AND account_code = 'LEGACY_FUNDS'
                       AND debit > 0
                LOOP
                    PERFORM legacy_funds_balance_check(v_linea.cash_box_id, v_linea.currency);
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER financial_events_legacy_funds_non_negative
                AFTER UPDATE OF status ON financial_events
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.status = 'draft' AND NEW.status <> 'draft')
                EXECUTE FUNCTION legacy_funds_event_non_negative();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS financial_events_legacy_funds_non_negative ON financial_events;
            DROP TRIGGER IF EXISTS journal_lines_legacy_funds_non_negative ON journal_lines;
            DROP FUNCTION IF EXISTS legacy_funds_event_non_negative();
            DROP FUNCTION IF EXISTS legacy_funds_line_non_negative();
            DROP FUNCTION IF EXISTS legacy_funds_balance_check(bigint, char);
        SQL);
    }
};
