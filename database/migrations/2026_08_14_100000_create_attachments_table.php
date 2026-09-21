<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos adjuntos — §9.10 del DER.
 *
 * Toda la evidencia del sistema pasa por acá: el ticket que llega con el
 * expediente, el extracto que se importó, el Pase firmado, la Orden en
 * PDF. Una sola tabla para todos porque el archivo se comporta igual sea
 * cual sea su sujeto —se guarda, se descarga, no se edita— y separarlos
 * por tipo obligaría a repetir la misma lógica en cada módulo.
 *
 * **Los archivos son inmutables**, impuesto por trigger. Es textual del
 * DER: *«Cada reenvío de Pase o documento firmado genera otro
 * attachment»*. Corregir un adjunto no es editarlo: es subir el nuevo y
 * que el anterior siga ahí. Un comprobante que se puede reescribir no
 * prueba nada, que es el mismo motivo por el que `audit_events` y
 * `bank_transactions` son append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table): void {
            $table->id();

            /*
             * El nombre corto del sujeto, no la clase completa: es lo que
             * ya hace `RecordAuditEvent` con `class_basename`, y por el
             * mismo motivo —mover una clase de módulo no puede invalidar
             * la evidencia—. Sin FK, a propósito: los adjuntos sobreviven
             * a la fila que los originó.
             */
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');

            /*
             * Qué documento es. Sin `CHECK`: el catálogo crece con cada
             * tipo nuevo de comprobante y no gobierna ninguna regla de
             * integridad. Los que sí son conjuntos cerrados —el sujeto, el
             * origen, la confidencialidad— tienen su restricción abajo.
             */
            $table->string('document_type', 40);
            $table->string('title', 200);

            $table->string('storage_disk', 30)->default('local');
            $table->string('object_key', 255);

            $table->string('original_filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);

            $table->string('source', 20);
            /** Versión de la plantilla, para los documentos que genera el sistema. */
            $table->string('template_version', 20)->nullable();

            $table->string('confidentiality', 20)->default('internal');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            // La pregunta habitual es «qué archivos tiene este expediente».
            $table->index(['subject_type', 'subject_id']);
            // Y la otra: «¿este archivo ya lo teníamos?».
            $table->index('sha256');
        });

        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN (
                'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket'
            ))");
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_source_check
            CHECK (source IN ('generated', 'uploaded', 'scanned'))");
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_confidentiality_check
            CHECK (confidentiality IN ('internal', 'restricted'))");
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_size_check
            CHECK (size_bytes > 0)');
        DB::statement("ALTER TABLE attachments ADD CONSTRAINT attachments_sha_check
            CHECK (sha256 ~ '^[0-9a-f]{64}$')");

        /*
         * Inmutable.
         *
         * El `DELETE` se habilita dentro de una transacción que lo declare,
         * igual que en `bank_transactions`. Lo necesita revertir una
         * importación: si el extracto se borra, su archivo tiene que irse
         * con él, o quedaría un adjunto apuntando a un sujeto inexistente.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER attachments_immutable
            BEFORE UPDATE OR DELETE ON attachments
            FOR EACH ROW EXECUTE FUNCTION attachments_are_immutable()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS attachments_immutable ON attachments');
        DB::statement('DROP FUNCTION IF EXISTS attachments_are_immutable()');

        Schema::dropIfExists('attachments');
    }
};
