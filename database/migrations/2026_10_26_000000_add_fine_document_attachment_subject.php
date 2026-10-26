<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El documento de Multas como dueño de su archivo.
 *
 * La foto de una resolución o de un acta colgaba del expediente, y con tres
 * actas nada decía cuál era la de cuál. Ahora el archivo va al documento;
 * como el documento se comparte entre expedientes y multas, el archivo se ve
 * en todos sin duplicarse. Lo que ya se subió al expediente queda donde
 * está: un adjunto no se mueve.
 */
return new class extends Migration
{
    private const SUJETOS = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'fine_payment', 'cheque_rejection'";

    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.", 'fine_document'))");
    }

    public function down(): void
    {
        if (DB::table('attachments')->where('subject_type', 'fine_document')->exists()) {
            throw new RuntimeException(
                'Hay archivos adjuntos a documentos de Multas: decidí qué hacer con ellos antes de volver atrás.',
            );
        }

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::SUJETOS.'))');
    }
};
