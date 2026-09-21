<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/* La fecha del trigger no depende de la zona configurada en PostgreSQL. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
            BEGIN
                IF NEW.status <> 'closed' THEN
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

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        /* No se vuelve a una guarda dependiente de zona ni permisiva. */
    }
};
