<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Series y recibos por caja — Multas, DER físico §5.
 *
 * Hasta acá había una sola caja que emitía: una serie activa por tipo de
 * documento, un talonario único por tipo y un recibo que siempre nombraba a
 * una Persona. Multas numera aparte —su propio talonario de papel, su propio
 * prefijo— y extiende el recibo a la carátula del expediente, que no siempre
 * es una Persona del maestro.
 *
 * Haberes no cambia: su caja sigue exigiendo la persona, y sus series y
 * recibos quedan asignados a ella.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Por defecto se exige: una caja nueva no relaja el recibo sin decidirlo.
            ALTER TABLE cash_boxes ADD COLUMN requires_receipt_person boolean NOT NULL DEFAULT true;

            -- Los dos primeros dígitos del código ya decían la caja (00, 01, 02).
            UPDATE document_series s SET cash_box_id = b.id
              FROM cash_boxes b
             WHERE s.cash_box_id IS NULL
               AND b.code = CASE substr(s.code, 1, 2)
                    WHEN '00' THEN 'haberes' WHEN '01' THEN 'aranceles' WHEN '02' THEN 'multas' END;
            ALTER TABLE document_series ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE document_series ADD CONSTRAINT document_series_cash_box_fk
                FOREIGN KEY (cash_box_id) REFERENCES cash_boxes(id) ON DELETE RESTRICT;
            ALTER TABLE document_series ADD CONSTRAINT document_series_id_cash_box_unique UNIQUE (id, cash_box_id);
            DROP INDEX document_series_active_type_unique;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(cash_box_id, document_type)
                WHERE is_active AND origin = 'system';

            ALTER TABLE receipts ADD COLUMN cash_box_id bigint;
            UPDATE receipts r SET cash_box_id = s.cash_box_id FROM document_series s WHERE s.id = r.document_series_id;
            ALTER TABLE receipts ALTER COLUMN cash_box_id SET NOT NULL;
            ALTER TABLE receipts ADD CONSTRAINT receipts_series_cash_box_fk
                FOREIGN KEY (document_series_id, cash_box_id) REFERENCES document_series(id, cash_box_id);
            CREATE INDEX receipts_cash_box_issue_date_index ON receipts(cash_box_id, issue_date);

            -- Cada caja tiene su talonario de papel: el mismo número puede existir en dos.
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(cash_box_id, receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;

            ALTER TABLE receipts ALTER COLUMN person_id DROP NOT NULL;

            CREATE OR REPLACE FUNCTION receipts_belong_to_cash_box() RETURNS trigger AS $$
            DECLARE v_requires boolean;
            BEGIN
                IF NEW.cash_box_id IS NULL THEN
                    SELECT cash_box_id INTO NEW.cash_box_id FROM document_series WHERE id = NEW.document_series_id;
                END IF;

                SELECT requires_receipt_person INTO v_requires FROM cash_boxes WHERE id = NEW.cash_box_id;

                IF v_requires AND NEW.person_id IS NULL THEN
                    RAISE EXCEPTION 'Los recibos de esta caja se extienden a una persona del maestro.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.person_id IS NULL AND btrim(coalesce(NEW.counterparty_name_snapshot, '')) = '' THEN
                    RAISE EXCEPTION 'Un recibo sin persona tiene que decir a quién se le extendió.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER receipts_belong_to_cash_box BEFORE INSERT ON receipts
                FOR EACH ROW EXECUTE FUNCTION receipts_belong_to_cash_box();

            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.counterparty_name_snapshot IS DISTINCT FROM OLD.counterparty_name_snapshot
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION document_series_preserve_history() RETURNS trigger AS $$
            DECLARE used_number bigint;
            BEGIN
                SELECT max(number) INTO used_number FROM (
                    SELECT number FROM receipts WHERE document_series_id = OLD.id
                    UNION ALL SELECT number FROM payment_orders WHERE document_series_id = OLD.id
                ) numbers;
                IF used_number IS NOT NULL AND (
                    NEW.code IS DISTINCT FROM OLD.code OR NEW.display_prefix IS DISTINCT FROM OLD.display_prefix
                    OR NEW.format_pattern IS DISTINCT FROM OLD.format_pattern
                    OR NEW.code_padding IS DISTINCT FROM OLD.code_padding OR NEW.number_padding IS DISTINCT FROM OLD.number_padding
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.origin IS DISTINCT FROM OLD.origin
                    OR NEW.cash_box_id IS DISTINCT FROM OLD.cash_box_id
                ) THEN
                    RAISE EXCEPTION 'Una serie utilizada conserva su formato; crea una nueva version.' USING ERRCODE = 'check_violation';
                END IF;
                IF used_number IS NOT NULL AND NEW.next_number <= used_number THEN
                    RAISE EXCEPTION 'El proximo numero debe superar los comprobantes emitidos.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        if (DB::table('receipts')->whereNull('person_id')->exists()) {
            throw new RuntimeException('Hay recibos sin persona. No se puede retirar esta migración sin perderlos.');
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_series_preserve_history() RETURNS trigger AS $$
            DECLARE used_number bigint;
            BEGIN
                SELECT max(number) INTO used_number FROM (
                    SELECT number FROM receipts WHERE document_series_id = OLD.id
                    UNION ALL SELECT number FROM payment_orders WHERE document_series_id = OLD.id
                ) numbers;
                IF used_number IS NOT NULL AND (
                    NEW.code IS DISTINCT FROM OLD.code OR NEW.display_prefix IS DISTINCT FROM OLD.display_prefix
                    OR NEW.format_pattern IS DISTINCT FROM OLD.format_pattern
                    OR NEW.code_padding IS DISTINCT FROM OLD.code_padding OR NEW.number_padding IS DISTINCT FROM OLD.number_padding
                    OR NEW.document_type IS DISTINCT FROM OLD.document_type OR NEW.origin IS DISTINCT FROM OLD.origin
                ) THEN
                    RAISE EXCEPTION 'Una serie utilizada conserva su formato; crea una nueva version.' USING ERRCODE = 'check_violation';
                END IF;
                IF used_number IS NOT NULL AND NEW.next_number <= used_number THEN
                    RAISE EXCEPTION 'El proximo numero debe superar los comprobantes emitidos.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER receipts_belong_to_cash_box ON receipts;
            DROP FUNCTION receipts_belong_to_cash_box();
            ALTER TABLE receipts ALTER COLUMN person_id SET NOT NULL;
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;
            DROP INDEX receipts_cash_box_issue_date_index;
            ALTER TABLE receipts DROP CONSTRAINT receipts_series_cash_box_fk;
            ALTER TABLE receipts DROP COLUMN cash_box_id;

            DROP INDEX document_series_active_type_unique;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(document_type)
                WHERE is_active AND origin = 'system';
            ALTER TABLE document_series DROP CONSTRAINT document_series_id_cash_box_unique;
            ALTER TABLE document_series DROP CONSTRAINT document_series_cash_box_fk;
            ALTER TABLE document_series ALTER COLUMN cash_box_id DROP NOT NULL;

            ALTER TABLE cash_boxes DROP COLUMN requires_receipt_person;
        SQL);
    }
};
