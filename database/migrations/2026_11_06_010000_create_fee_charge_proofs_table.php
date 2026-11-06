<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El comprobante del depósito con que se paga un arancel: lo que dice el papel y su
 * foto. Sin él, un pendiente no se identifica contra el extracto.
 *
 * Es lo que dijo quien pagó, no el dinero: el importe no se ata al cobro, porque un
 * depósito puede pagar en parte o de más, y el dinero se verifica contra el extracto.
 * Solo se agrega: cambiarlo suma otra fila, y vale la última.
 */
return new class extends Migration
{
    private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection',
                'fine_document'";

    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'fee_charge'))");

        Schema::create('fee_charge_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fee_charge_id')->constrained('fee_charges')->restrictOnDelete();
            $table->foreignId('attachment_id')->unique()->constrained('attachments')->restrictOnDelete();
            $table->decimal('amount', 19, 2);
            $table->date('deposit_date');
            $table->time('deposit_time')->nullable();
            $table->string('operation_number', 40)->nullable();
            $table->string('terminal', 40)->nullable();
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['fee_charge_id', 'id']);
        });

        DB::statement('ALTER TABLE fee_charge_proofs ADD CONSTRAINT fee_charge_proofs_amount_positive CHECK (amount > 0)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fee_charge_proofs_guard() RETURNS trigger AS $$
            DECLARE
                v_charge fee_charges%ROWTYPE;
                v_attachment attachments%ROWTYPE;
            BEGIN
                -- Con la fila tomada: no se cruza con quien lo está anulando.
                SELECT * INTO v_charge FROM fee_charges WHERE id = NEW.fee_charge_id FOR UPDATE;

                IF v_charge.medium <> 'bank' THEN
                    RAISE EXCEPTION 'Solo un cobro de arancel por banco lleva comprobante de depósito.';
                END IF;

                IF v_charge.status = 'voided' THEN
                    RAISE EXCEPTION 'El cobro de arancel % está anulado: su comprobante ya no se cambia.', NEW.fee_charge_id;
                END IF;

                SELECT * INTO v_attachment FROM attachments WHERE id = NEW.attachment_id;

                IF v_attachment.subject_type <> 'fee_charge' OR v_attachment.subject_id <> NEW.fee_charge_id THEN
                    RAISE EXCEPTION 'El archivo no es de ese cobro de arancel.';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fee_charge_proofs_guard BEFORE INSERT ON fee_charge_proofs
                FOR EACH ROW EXECUTE FUNCTION fee_charge_proofs_guard();
            CREATE TRIGGER fee_charge_proofs_append_only BEFORE UPDATE OR DELETE ON fee_charge_proofs
                FOR EACH ROW EXECUTE FUNCTION fee_rows_append_only();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('attachments')->where('subject_type', 'fee_charge')->exists()) {
            throw new RuntimeException(
                'Hay comprobantes adjuntos a cobros de aranceles: decidí qué hacer con ellos antes de volver atrás.',
            );
        }

        Schema::dropIfExists('fee_charge_proofs');
        DB::unprepared('DROP FUNCTION fee_charge_proofs_guard();');

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');
    }
};
