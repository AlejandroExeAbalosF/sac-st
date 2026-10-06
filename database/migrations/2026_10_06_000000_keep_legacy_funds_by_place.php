<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Del sistema anterior no se aparta ni se paga más de lo que quedaba en ese lugar.
 *
 * `LEGACY_FUNDS` es un saldo único para el efectivo, los cheques y el
 * banco, y el cajón y la cuenta mezclan plata vieja con la que entró
 * después. Controlar «hay plata en el cajón» y «queda saldo del sistema
 * anterior» por separado dejaba pasar esto: el cajón alcanza por un cobro
 * de hoy —de otro beneficiario— y el saldo viejo alcanza por la plata que
 * estaba en el banco. Se apartaba como efectivo viejo un efectivo nuevo.
 *
 * Ahora se lleva lo que queda **por lugar**, sin columnas nuevas, porque
 * cada hecho ya dice de dónde es:
 *
 * - la apertura y los pagos desde Pagos anteriores tienen la línea del
 *   lugar —efectivo, cheques o la cuenta bancaria— con sus reversiones;
 * - un apartado de efectivo o de depósito directo deja una recepción
 *   `legacy` con su medio y su cuenta, y liberarlo revierte su asiento.
 *
 * ```text
 * queda en un lugar = lo que la apertura declaró ahí
 *                   − lo pagado desde Pagos anteriores con ese lugar
 *                   − lo apartado de ahí, neto de lo liberado
 * ```
 *
 * Los cheques no entran: cada uno es un papel con número y ya se controla
 * de a uno (`legacy_cheque_stays_whole`, lo sin detallar). Lo que queda en
 * cheques es el resto: el saldo total menos el efectivo y las cuentas.
 *
 * El control vive en la función que ya protegía el total, así que corre en
 * los mismos momentos —al debitar `LEGACY_FUNDS`, diferido, y al postear un
 * borrador— y bajo el mismo bloqueo por caja y moneda.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_funds_at(
                p_cash_box_id bigint,
                p_currency char(3),
                p_account text,
                p_bank_account_id bigint
            ) RETURNS numeric AS $$
            DECLARE
                v_declarado NUMERIC(19,2);
                v_apartado NUMERIC(19,2);
            BEGIN
                -- La apertura suma en el lugar y el pago desde Pagos anteriores
                -- resta; una reversión cuenta como el evento que revierte.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_declarado
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = p_account
                   AND jl.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND fe.status <> 'draft'
                   AND COALESCE(original.event_type, fe.event_type) IN ('opening_balance', 'legacy_disbursement');

                -- Lo apartado de ahí: el débito a LEGACY_FUNDS del apartado,
                -- menos lo que devolvieron las liberaciones que lo revierten.
                SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
                  INTO v_apartado
                  FROM fund_receipts r
                  JOIN financial_events fe
                    ON fe.id = r.financial_event_id OR fe.reversal_of_id = r.financial_event_id
                  JOIN journal_lines jl
                    ON jl.financial_event_id = fe.id AND jl.account_code = 'LEGACY_FUNDS'
                 WHERE r.origin = 'legacy'
                   AND r.medium = CASE p_account WHEN 'CASH_ON_HAND' THEN 'cash' WHEN 'BANK_ACCOUNT' THEN 'bank' END
                   AND r.bank_account_id IS NOT DISTINCT FROM p_bank_account_id
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency
                   AND fe.status <> 'draft';

                RETURN v_declarado - v_apartado;
            END;
            $$ LANGUAGE plpgsql STABLE;

            -- Las cuentas bancarias donde hubo plata del sistema anterior.
            CREATE OR REPLACE FUNCTION legacy_funds_bank_accounts(p_cash_box_id bigint, p_currency char(3))
            RETURNS SETOF bigint AS $$
                SELECT DISTINCT jl.bank_account_id
                  FROM journal_lines jl
                  JOIN financial_events fe ON fe.id = jl.financial_event_id
                  LEFT JOIN financial_events original ON original.id = fe.reversal_of_id
                 WHERE jl.account_code = 'BANK_ACCOUNT'
                   AND jl.bank_account_id IS NOT NULL
                   AND jl.cash_box_id = p_cash_box_id
                   AND jl.currency = p_currency
                   AND COALESCE(original.event_type, fe.event_type) IN ('opening_balance', 'legacy_disbursement')
                UNION
                SELECT r.bank_account_id
                  FROM fund_receipts r
                 WHERE r.origin = 'legacy'
                   AND r.medium = 'bank'
                   AND r.cash_box_id = p_cash_box_id
                   AND r.currency = p_currency;
            $$ LANGUAGE sql STABLE;

            CREATE OR REPLACE FUNCTION legacy_funds_places_check(p_cash_box_id bigint, p_currency char(3))
            RETURNS void AS $$
            DECLARE
                v_cuenta bigint;
                v_queda NUMERIC(19,2);
            BEGIN
                v_queda := legacy_funds_at(p_cash_box_id, p_currency, 'CASH_ON_HAND', NULL);

                IF v_queda < 0 THEN
                    RAISE EXCEPTION
                        'Del sistema anterior no queda tanto en efectivo: quedaria en %.', v_queda
                        USING ERRCODE = 'check_violation';
                END IF;

                FOR v_cuenta IN SELECT legacy_funds_bank_accounts(p_cash_box_id, p_currency) LOOP
                    v_queda := legacy_funds_at(p_cash_box_id, p_currency, 'BANK_ACCOUNT', v_cuenta);

                    IF v_queda < 0 THEN
                        RAISE EXCEPTION
                            'Del sistema anterior no queda tanto en la cuenta %: quedaria en %.', v_cuenta, v_queda
                            USING ERRCODE = 'check_violation';
                    END IF;
                END LOOP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        $this->assertExistingPlacesNonNegative();

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

                -- Y en cada lugar: el total no alcanza si la plata vieja está en otro.
                IF p_cash_box_id IS NOT NULL THEN
                    PERFORM legacy_funds_places_check(p_cash_box_id, p_currency);
                END IF;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
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

            DROP FUNCTION IF EXISTS legacy_funds_places_check(bigint, char);
            DROP FUNCTION IF EXISTS legacy_funds_bank_accounts(bigint, char);
            DROP FUNCTION IF EXISTS legacy_funds_at(bigint, char, text, bigint);
        SQL);
    }

    /**
     * Lo ya registrado no se corrige —el libro es append-only—: si algún
     * lugar ya quedó en negativo, la migración se detiene y lo nombra.
     */
    private function assertExistingPlacesNonNegative(): void
    {
        $negativos = DB::scalar(<<<'SQL'
            SELECT string_agg(format('caja %s en %s, %s: %s', caja, moneda, lugar, queda), '; ')
              FROM (
                SELECT c.caja, c.moneda, 'efectivo' AS lugar,
                       legacy_funds_at(c.caja, c.moneda, 'CASH_ON_HAND', NULL) AS queda
                  FROM (SELECT DISTINCT cash_box_id AS caja, currency AS moneda
                          FROM journal_lines
                         WHERE account_code = 'LEGACY_FUNDS' AND cash_box_id IS NOT NULL) c
                UNION ALL
                SELECT c.caja, c.moneda, 'cuenta ' || cuenta,
                       legacy_funds_at(c.caja, c.moneda, 'BANK_ACCOUNT', cuenta)
                  FROM (SELECT DISTINCT cash_box_id AS caja, currency AS moneda
                          FROM journal_lines
                         WHERE account_code = 'LEGACY_FUNDS' AND cash_box_id IS NOT NULL) c,
                       LATERAL legacy_funds_bank_accounts(c.caja, c.moneda) AS cuenta
              ) AS lugares
             WHERE queda < 0
        SQL);

        if (is_string($negativos)) {
            throw new RuntimeException(
                'El saldo del sistema anterior ya es negativo en algún lugar y la guarda no se puede crear: '.$negativos
            );
        }
    }
};
