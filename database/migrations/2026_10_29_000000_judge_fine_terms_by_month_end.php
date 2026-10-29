<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El término de Multas se corta en el fin del mes, no en su cierre manual.
 *
 * Decisión del usuario (29/10/2026): el período es el mes calendario y lo
 * que pasa después de su último día ya es del mes siguiente, se haya
 * cerrado la caja o no. El cierre manual sigue existiendo para la caja
 * —arqueos, días cerrados, totales congelados— y deja de decidir el término.
 *
 * - **Término del pago (M08):** se guarda el último día del mes de registro
 *   de la multa contra el que se juzgó (`term_ends_on`), en lugar de la
 *   evidencia del cierre y su hora. Un pago con término decidido lo tiene
 *   siempre; «a revisar» puede no tenerlo (multa sin fecha de registro).
 *   Lo ya aplicado conserva su clasificación: se completa el día con el fin
 *   del mes de registro de la multa.
 * - **Rendición:** se clasifica por la fecha del reconocimiento, que ya está
 *   en el asiento. La marca del último asiento que vio cada cierre
 *   (`covers_through_event_id`), que existía solo para eso, se retira.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications ADD COLUMN term_ends_on date;

            ALTER TABLE fine_payment_applications DISABLE TRIGGER fine_payment_applications_append_only;
            UPDATE fine_payment_applications a
               SET term_ends_on = (date_trunc('month', f.registered_on) + interval '1 month - 1 day')::date
              FROM fines f
             WHERE f.id = a.fine_id
               AND f.registered_on IS NOT NULL
               AND f.registered_precision <> 'unknown';
            ALTER TABLE fine_payment_applications ENABLE TRIGGER fine_payment_applications_append_only;

            ALTER TABLE fine_payment_applications
                DROP CONSTRAINT fine_payment_applications_timeliness_check,
                DROP COLUMN origin_closing_evidence_id,
                DROP COLUMN origin_closed_at,
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (timeliness = 'undetermined' OR term_ends_on IS NOT NULL)
                );

            DROP TRIGGER IF EXISTS closing_evidence_watermark ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_watermark();
            ALTER TABLE period_closing_evidence
                DROP CONSTRAINT IF EXISTS period_closing_evidence_watermark_check,
                DROP COLUMN IF EXISTS covers_through_event_id;
        SQL);
    }

    /**
     * Vuelve las columnas, sin datos: el día del término no dice contra qué
     * evidencia de cierre se habría juzgado, y la marca de cada cierre no se
     * puede reconstruir. Por eso la guarda vieja vuelve sin validar.
     */
    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_payment_applications
                DROP CONSTRAINT fine_payment_applications_timeliness_check,
                ADD COLUMN origin_closing_evidence_id bigint REFERENCES period_closing_evidence (id) ON DELETE RESTRICT,
                ADD COLUMN origin_closed_at timestamptz(0),
                DROP COLUMN term_ends_on,
                ADD CONSTRAINT fine_payment_applications_timeliness_check CHECK (
                    timeliness IN ('on_time', 'late', 'undetermined')
                    AND (origin_closing_evidence_id IS NULL) = (origin_closed_at IS NULL)
                    AND (timeliness <> 'late' OR origin_closing_evidence_id IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE period_closing_evidence
                ADD COLUMN covers_through_event_id bigint NOT NULL DEFAULT 0,
                ADD CONSTRAINT period_closing_evidence_watermark_check CHECK (covers_through_event_id >= 0);
            ALTER TABLE period_closing_evidence ALTER COLUMN covers_through_event_id DROP DEFAULT;

            CREATE OR REPLACE FUNCTION closing_evidence_watermark() RETURNS trigger AS $$
            BEGIN
                NEW.covers_through_event_id := COALESCE((
                    SELECT max(f.id)
                      FROM financial_events f
                     WHERE f.cash_box_id = (SELECT cash_box_id FROM period_closings WHERE id = NEW.period_closing_id)
                ), 0);
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER closing_evidence_watermark BEFORE INSERT ON period_closing_evidence
                FOR EACH ROW EXECUTE FUNCTION closing_evidence_watermark();
        SQL);
    }
};
