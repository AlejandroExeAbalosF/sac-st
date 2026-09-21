<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El cierre también tiene papeles colgados.
 *
 * La planilla del día —el Excel con su anverso y su reverso— es la
 * evidencia del cierre, no una descarga que se genera cada vez que alguien
 * la pide. Guardada como `attachment` con `source = 'generated'` queda con
 * su sha256, su fecha y su autor: reexportar el 02/06 dentro de un año
 * devuelve el archivo que el área firmó, no una reconstrucción.
 */
return new class extends Migration
{
    private const ANTERIORES = "'expediente', 'import', 'receipt', 'payment_order',
        'pase', 'disbursement', 'cash_transfer', 'deposit_ticket'";

    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ANTERIORES.", 'period_closing'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ANTERIORES.'))');
    }
};
