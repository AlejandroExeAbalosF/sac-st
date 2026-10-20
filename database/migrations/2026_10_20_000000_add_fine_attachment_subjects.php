<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los adjuntos de Multas: la foto de la resolución o del acta en el
 * expediente, el papel que respalda la multa y el comprobante de un pago
 * anterior al sistema.
 *
 * Solo se amplía el catálogo de sujetos. Lo demás de `attachments` —disco
 * privado, huella, validación por contenido, inmutabilidad— ya vale igual
 * para cualquier sujeto.
 */
return new class extends Migration
{
    private const ANTERIORES = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document'";

    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN (
                '.self::ANTERIORES.",
                'fine_case', 'fine', 'fine_historical_movement'
            ))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ANTERIORES.'))');
    }
};
