<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La rendición oficial mensual de Multas (P02): dos informes por mes cerrado
 * —los ingresos en término y los fuera de término— congelados como la
 * planilla de caja.
 *
 * Cuelgan del cierre mensual de la caja de Multas y de su versión: no hay un
 * segundo calendario de cierres. Reabrir y volver a cerrar el mes produce una
 * versión nueva del cierre, y con ella otra rendición; la anterior queda.
 * Cada informe se escribe una vez y no se modifica.
 *
 * Para saber qué se reconoció antes de un cierre, la evidencia de cada
 * versión anota el último asiento de la caja que vio. No alcanza con comparar
 * horas: `closed_at` y `created_at` se guardan al segundo —PostgreSQL
 * redondea—, y un reconocimiento hecho en el mismo segundo que el cierre, o
 * en el siguiente redondeado hacia abajo, no se distingue de uno anterior.
 * Los asientos de una caja y su cierre toman el mismo candado (`cash_boxes
 * FOR UPDATE`), así que el orden de los ids es el orden real.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->evidenceWatermark();

        Schema::create('fine_monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_closing_id')->constrained('period_closings')->restrictOnDelete();
            $table->unsignedInteger('closing_version');
            $table->string('kind', 10);
            $table->jsonb('payload');
            $table->decimal('total', 19, 2);
            $table->unsignedInteger('rows_count');
            $table->foreignId('attachment_id')->nullable()->unique()->constrained('attachments')->restrictOnDelete();
            $table->foreignId('emitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('emitted_at');
            $table->unique(['period_closing_id', 'closing_version', 'kind']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_monthly_reports
                ADD CONSTRAINT fine_monthly_reports_kind_check CHECK (kind IN ('on_time', 'late')),
                ADD CONSTRAINT fine_monthly_reports_amounts_check CHECK (total >= 0 AND rows_count >= 0),
                ADD CONSTRAINT fine_monthly_reports_payload_check CHECK (jsonb_typeof(payload) = 'array');

            -- Solo sobre el cierre mensual vigente de la caja de Multas, cerrado.
            CREATE OR REPLACE FUNCTION fine_monthly_report_closing_ok() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM period_closings
                     WHERE id = NEW.period_closing_id
                       AND cash_box_id = multas_cash_box_id()
                       AND period_type = 'monthly'
                       AND status = 'closed'
                       AND evidence_version = NEW.closing_version
                ) THEN
                    RAISE EXCEPTION 'La rendición se emite sobre el cierre mensual vigente de Multas, cerrado.'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_monthly_reports_closing_ok BEFORE INSERT ON fine_monthly_reports
                FOR EACH ROW EXECUTE FUNCTION fine_monthly_report_closing_ok();

            -- El PDF se adjunta una vez, después de congelar las filas; nada más cambia.
            CREATE OR REPLACE FUNCTION fine_monthly_report_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una rendición emitida no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;
                IF OLD.attachment_id IS NOT NULL
                    OR NEW.attachment_id IS NULL
                    OR (to_jsonb(NEW) - 'attachment_id') IS DISTINCT FROM (to_jsonb(OLD) - 'attachment_id') THEN
                    RAISE EXCEPTION 'Una rendición emitida no se modifica.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_monthly_reports_frozen BEFORE UPDATE OR DELETE ON fine_monthly_reports
                FOR EACH ROW EXECUTE FUNCTION fine_monthly_report_frozen();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('fine_monthly_reports');
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS fine_monthly_report_closing_ok();
            DROP FUNCTION IF EXISTS fine_monthly_report_frozen();
            DROP TRIGGER IF EXISTS closing_evidence_watermark ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_watermark();
            ALTER TABLE period_closing_evidence DROP COLUMN IF EXISTS covers_through_event_id;
        SQL);
    }

    /**
     * El último asiento de la caja que vio cada versión del cierre. Lo pone
     * la base al congelar la evidencia, dentro del candado de la caja: el
     * código no lo elige ni lo puede adelantar.
     *
     * Las versiones ya congeladas se completan por hora, la única referencia
     * que tienen. Ninguna es de Multas en producción —la caja todavía no se
     * abrió—, que es la única que lee la marca.
     */
    private function evidenceWatermark(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE period_closing_evidence ADD COLUMN covers_through_event_id bigint;

            ALTER TABLE period_closing_evidence DISABLE TRIGGER closing_evidence_append_only;
            UPDATE period_closing_evidence e
               SET covers_through_event_id = COALESCE((
                   SELECT max(f.id)
                     FROM financial_events f
                     JOIN period_closings c ON c.cash_box_id = f.cash_box_id
                    WHERE c.id = e.period_closing_id
                      AND f.created_at <= e.created_at
               ), 0);
            ALTER TABLE period_closing_evidence ENABLE TRIGGER closing_evidence_append_only;

            ALTER TABLE period_closing_evidence
                ALTER COLUMN covers_through_event_id SET NOT NULL,
                ADD CONSTRAINT period_closing_evidence_watermark_check CHECK (covers_through_event_id >= 0);

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
