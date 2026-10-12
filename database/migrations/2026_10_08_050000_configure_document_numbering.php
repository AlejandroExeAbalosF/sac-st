<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE document_series ADD COLUMN display_prefix varchar(12);
            UPDATE document_series SET display_prefix = code;
            ALTER TABLE document_series DROP CONSTRAINT document_series_code_unique;
            CREATE UNIQUE INDEX document_series_active_code_unique ON document_series(code) WHERE is_active;
            CREATE UNIQUE INDEX document_series_active_type_unique ON document_series(document_type) WHERE is_active AND origin = 'system';
            ALTER TABLE document_series ADD CONSTRAINT document_series_format_check CHECK (
                (display_prefix IS NULL OR display_prefix ~ '^[0-9]{1,12}$')
                AND code_padding BETWEEN 1 AND 12 AND number_padding BETWEEN 1 AND 12
                AND format_pattern IN ('{code}/{number}', '{code}-{number}')
            );
            CREATE UNIQUE INDEX receipts_formatted_number_unique ON receipts(formatted_number);
            CREATE UNIQUE INDEX payment_orders_formatted_number_unique ON payment_orders(formatted_number);
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(receipt_type, talonario_number)
                WHERE talonario_number IS NOT NULL;
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
            CREATE TRIGGER document_series_preserve_history BEFORE UPDATE ON document_series
                FOR EACH ROW EXECUTE FUNCTION document_series_preserve_history();
        SQL);
    }

    public function down(): void
    {
        // Puede haber varias versiones del mismo código: no se descarta historia al revertir.
        if (DB::table('document_series')->select('code')->groupBy('code')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Hay series versionadas. No se puede retirar esta migración sin perder su historia.');
        }
        DB::unprepared(<<<'SQL'
            DROP TRIGGER document_series_preserve_history ON document_series;
            DROP FUNCTION document_series_preserve_history();
            DROP INDEX receipts_formatted_number_unique;
            DROP INDEX payment_orders_formatted_number_unique;
            DROP INDEX receipts_talonario_number_unique;
            CREATE UNIQUE INDEX receipts_talonario_number_unique ON receipts(document_series_id, talonario_number)
                WHERE talonario_number IS NOT NULL;
            DROP INDEX document_series_active_code_unique;
            DROP INDEX document_series_active_type_unique;
            ALTER TABLE document_series DROP CONSTRAINT document_series_format_check;
            ALTER TABLE document_series DROP COLUMN display_prefix;
            ALTER TABLE document_series ADD CONSTRAINT document_series_code_unique UNIQUE(code);
        SQL);
    }
};
