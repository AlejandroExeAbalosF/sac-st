<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un adjunto opcional se puede anular: queda guardado, fuera de la vista.
 *
 * Anular no es borrar. El archivo y su fila siguen ahí, con quién lo anuló,
 * cuándo y por qué; la pantalla deja de mostrarlo como vigente y, si era el
 * último, vuelve a verse el anterior.
 *
 * Solo se anula lo que es respaldo opcional, y la lista es **cerrada**: un
 * par sujeto + tipo de documento por renglón, el mismo que
 * `Attachment::VOIDABLE`. Un tipo nuevo no queda anulable por existir. El
 * ticket del traslado al banco, el extracto, la planilla de cierre, la
 * rendición, el comprobante de cobro de Aranceles y lo que genera el
 * sistema no están: son la prueba de un hecho.
 *
 * La base impone tres cosas:
 * - todo adjunto **nace vigente**: no se inserta uno ya anulado;
 * - anular es completar las cuatro columnas a la vez, una sola vez, sin
 *   que cambie nada más, y solo en un adjunto de la lista;
 * - un adjunto anulado no se renombra.
 */
return new class extends Migration
{
    /**
     * Los pares anulables. Igual que `Attachment::VOIDABLE`: un test los
     * compara.
     */
    private const VOIDABLE = [
        ['fine_case', 'fine_case_paper'],
        ['fine_document', 'fine_document_paper'],
        ['fine', 'fine_support'],
        ['fine_payment', 'fine_payment_evidence'],
        ['fine_historical_movement', 'fine_historical_evidence'],
        ['receipt', 'talonario_photo'],
        ['deposit_ticket', 'deposit_ticket'],
        ['legacy_document', 'legacy_income_receipt'],
        ['legacy_document', 'legacy_payment_order'],
        ['legacy_document', 'legacy_expense_receipt'],
        ['disbursement', 'transfer_report'],
    ];

    /** Los motivos de `SharedReasonAct::VoidAttachment`. */
    private const VOID_REASONS = ['wrong_file', 'wrong_subject', 'illegible', 'duplicate', 'sensitive_data', 'other'];

    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->timestampTz('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('void_reason_code', 40)->nullable();
            $table->string('void_reason', 500)->nullable();
        });

        $motivos = implode(', ', array_map(static fn (string $codigo): string => "'{$codigo}'", self::VOID_REASONS));

        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_void_complete CHECK (
            (voided_at IS NULL AND voided_by IS NULL AND void_reason_code IS NULL AND void_reason IS NULL)
            OR (
                voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason_code IS NOT NULL
                AND (void_reason_code <> 'other' OR btrim(coalesce(void_reason, '')) <> '')
            )
        )");
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_void_reason_code_check
            CHECK (void_reason_code IS NULL OR void_reason_code IN ({$motivos}))");

        $pares = implode(",\n                    ", array_map(
            static fn (array $par): string => "('{$par[0]}', '{$par[1]}')",
            self::VOIDABLE,
        ));

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION attachment_is_voidable(p_subject_type text, p_document_type text, p_source text)
            RETURNS boolean LANGUAGE sql IMMUTABLE AS \$\$
                SELECT p_source <> 'generated' AND (p_subject_type, p_document_type) IN (
                    {$pares}
                );
            \$\$;
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_born_current() RETURNS trigger AS $$
            BEGIN
                IF NEW.voided_at IS NOT NULL OR NEW.voided_by IS NOT NULL
                   OR NEW.void_reason_code IS NOT NULL OR NEW.void_reason IS NOT NULL THEN
                    RAISE EXCEPTION 'Un adjunto nace vigente: se anula después, con su motivo.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER attachments_born_current
                BEFORE INSERT ON attachments
                FOR EACH ROW EXECUTE FUNCTION attachments_born_current();

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Renombrar: cambia la descripción y ninguna otra columna.
                IF (to_jsonb(NEW) - 'title') = (to_jsonb(OLD) - 'title') THEN
                    IF OLD.voided_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Un adjunto anulado no se renombra.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    IF btrim(NEW.title) = '' THEN
                        RAISE EXCEPTION 'Un adjunto no se queda sin descripción.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                -- Anular: se completan los datos de la anulación, una vez, y nada más.
                IF OLD.voided_at IS NULL AND NEW.voided_at IS NOT NULL
                   AND (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason_code', 'void_reason'])
                     = (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason_code', 'void_reason']) THEN
                    IF NOT attachment_is_voidable(OLD.subject_type, OLD.document_type, OLD.source) THEN
                        RAISE EXCEPTION 'Este adjunto no se anula: es la prueba de un hecho, no un respaldo opcional.'
                            USING ERRCODE = 'restrict_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS attachments_born_current ON attachments;
            DROP FUNCTION IF EXISTS attachments_born_current();

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - 'title') = (to_jsonb(OLD) - 'title') THEN
                    IF btrim(NEW.title) = '' THEN
                        RAISE EXCEPTION 'Un adjunto no se queda sin descripción.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement('DROP FUNCTION IF EXISTS attachment_is_voidable(text, text, text)');
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_void_reason_code_check');
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT IF EXISTS attachments_void_complete');

        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason_code', 'void_reason']);
        });
    }
};
