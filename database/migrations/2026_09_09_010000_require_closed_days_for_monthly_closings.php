<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cierre mensual exige que cada dia con movimiento este cerrado.
 *
 * El mensual congela los totales del periodo calculandolos del libro, sin
 * mirar si cada jornada habia pasado por su arqueo: un mes podia quedar
 * cerrado con quince dias que nadie conto. Los numeros cerraban igual
 * --salen de `journal_lines`-- pero el control diario, que es donde se
 * detecta un faltante, no habia ocurrido.
 *
 * Se exigen los dias con movimiento y no los del calendario: un sabado sin
 * un solo asiento no tiene nada que arquear ni que cerrar, y pedirle una
 * planilla obligaria a inventar treinta cierres vacios por mes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
                estado_arqueo TEXT;
                saldo_arqueo NUMERIC(19,2);
                diferencia_arqueo NUMERIC(19,2);
                instante_arqueo TIMESTAMPTZ;
                saldo_libro NUMERIC(19,2);
            BEGIN
                IF NEW.status <> 'closed'
                   OR (TG_OP = 'UPDATE' AND OLD.status = 'closed') THEN
                    RETURN NEW;
                END IF;

                IF NEW.period_to > (CURRENT_TIMESTAMP AT TIME ZONE 'America/Argentina/Salta')::date THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo que termina el %: esa fecha todavia no llego.', NEW.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.period_type = 'monthly' THEN
                    /*
                     * El mes no cierra sobre dias que nunca se cerraron. Se
                     * exigen los dias con movimiento y no los del
                     * calendario: un sabado sin un solo asiento no tiene
                     * nada que arquear ni que cerrar.
                     */
                    SELECT count(*) INTO pendientes
                      FROM (
                            SELECT DISTINCT financial_events.event_date
                              FROM financial_events
                              JOIN journal_lines
                                ON journal_lines.financial_event_id = financial_events.id
                             WHERE financial_events.cash_box_id = NEW.cash_box_id
                               AND financial_events.status IN ('posted', 'reversed')
                               AND financial_events.event_date BETWEEN NEW.period_from AND NEW.period_to
                               AND journal_lines.currency = NEW.currency
                      ) AS operados
                     WHERE NOT EXISTS (
                            SELECT 1
                              FROM period_closings AS dia
                             WHERE dia.cash_box_id = NEW.cash_box_id
                               AND dia.currency = NEW.currency
                               AND dia.period_type = 'daily'
                               AND dia.status = 'closed'
                               AND dia.period_from = operados.event_date
                     );

                    IF pendientes > 0 THEN
                        RAISE EXCEPTION
                            'El mes no se cierra con % dia(s) con movimiento sin cerrar.', pendientes
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                IF NEW.period_type = 'daily' THEN
                    /*
                     * Imputar la diferencia mueve el libro a proposito: el
                     * asiento contra CASH_DIFFERENCE lo corre hasta igualar
                     * lo contado. Por eso un arqueo imputado se compara
                     * contra lo que concluyo que hay --contado mas lo no
                     * recontado-- y no contra el expected_amount, que quedo
                     * congelado antes de esa imputacion.
                     */
                    SELECT status,
                           CASE
                               WHEN status = 'adjusted'
                                   THEN counted_amount + uncounted_amount
                               ELSE expected_amount
                           END,
                           difference_amount,
                           counted_at
                      INTO estado_arqueo, saldo_arqueo, diferencia_arqueo, instante_arqueo
                      FROM cash_counts
                     WHERE cash_box_id = NEW.cash_box_id
                       AND currency = NEW.currency
                       AND counted_on = NEW.period_to
                     ORDER BY sequence DESC
                     LIMIT 1;

                    IF estado_arqueo IS NULL THEN
                        RAISE EXCEPTION
                            'Antes de cerrar el dia hay que contar el cajon y revisar el arqueo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF estado_arqueo NOT IN ('reviewed', 'adjusted') THEN
                        RAISE EXCEPTION
                            'El ultimo arqueo no puede respaldar este cierre: hay que contar y revisarlo otra vez.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF estado_arqueo = 'reviewed' AND diferencia_arqueo <> 0 THEN
                        RAISE EXCEPTION
                            'El arqueo del dia cierra con una diferencia de % sin imputar.', diferencia_arqueo
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF TG_OP = 'UPDATE'
                       AND OLD.status = 'reopened'
                       AND instante_arqueo < OLD.reopened_at THEN
                        RAISE EXCEPTION
                            'El cierre fue reabierto despues de este arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    SELECT COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0)
                      INTO saldo_libro
                      FROM journal_lines
                      JOIN financial_events
                        ON financial_events.id = journal_lines.financial_event_id
                     WHERE financial_events.status IN ('posted', 'reversed')
                       AND financial_events.event_date <= NEW.period_to
                       AND journal_lines.cash_box_id = NEW.cash_box_id
                       AND journal_lines.currency = NEW.currency
                       AND journal_lines.account_code = 'CASH_ON_HAND';

                    IF saldo_arqueo <> saldo_libro THEN
                        RAISE EXCEPTION
                            'El saldo del libro cambio desde el ultimo arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
                estado_arqueo TEXT;
                saldo_arqueo NUMERIC(19,2);
                diferencia_arqueo NUMERIC(19,2);
                instante_arqueo TIMESTAMPTZ;
                saldo_libro NUMERIC(19,2);
            BEGIN
                IF NEW.status <> 'closed'
                   OR (TG_OP = 'UPDATE' AND OLD.status = 'closed') THEN
                    RETURN NEW;
                END IF;

                IF NEW.period_to > (CURRENT_TIMESTAMP AT TIME ZONE 'America/Argentina/Salta')::date THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo que termina el %: esa fecha todavia no llego.', NEW.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.period_type = 'daily' THEN
                    /*
                     * Imputar la diferencia mueve el libro a proposito: el
                     * asiento contra CASH_DIFFERENCE lo corre hasta igualar
                     * lo contado. Por eso un arqueo imputado se compara
                     * contra lo que concluyo que hay --contado mas lo no
                     * recontado-- y no contra el expected_amount, que quedo
                     * congelado antes de esa imputacion.
                     */
                    SELECT status,
                           CASE
                               WHEN status = 'adjusted'
                                   THEN counted_amount + uncounted_amount
                               ELSE expected_amount
                           END,
                           difference_amount,
                           counted_at
                      INTO estado_arqueo, saldo_arqueo, diferencia_arqueo, instante_arqueo
                      FROM cash_counts
                     WHERE cash_box_id = NEW.cash_box_id
                       AND currency = NEW.currency
                       AND counted_on = NEW.period_to
                     ORDER BY sequence DESC
                     LIMIT 1;

                    IF estado_arqueo IS NULL THEN
                        RAISE EXCEPTION
                            'Antes de cerrar el dia hay que contar el cajon y revisar el arqueo.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF estado_arqueo NOT IN ('reviewed', 'adjusted') THEN
                        RAISE EXCEPTION
                            'El ultimo arqueo no puede respaldar este cierre: hay que contar y revisarlo otra vez.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF estado_arqueo = 'reviewed' AND diferencia_arqueo <> 0 THEN
                        RAISE EXCEPTION
                            'El arqueo del dia cierra con una diferencia de % sin imputar.', diferencia_arqueo
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF TG_OP = 'UPDATE'
                       AND OLD.status = 'reopened'
                       AND instante_arqueo < OLD.reopened_at THEN
                        RAISE EXCEPTION
                            'El cierre fue reabierto despues de este arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    SELECT COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0)
                      INTO saldo_libro
                      FROM journal_lines
                      JOIN financial_events
                        ON financial_events.id = journal_lines.financial_event_id
                     WHERE financial_events.status IN ('posted', 'reversed')
                       AND financial_events.event_date <= NEW.period_to
                       AND journal_lines.cash_box_id = NEW.cash_box_id
                       AND journal_lines.currency = NEW.currency
                       AND journal_lines.account_code = 'CASH_ON_HAND';

                    IF saldo_arqueo <> saldo_libro THEN
                        RAISE EXCEPTION
                            'El saldo del libro cambio desde el ultimo arqueo: hay que volver a contar el cajon.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
