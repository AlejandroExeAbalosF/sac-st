<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un cheque cargado en la apertura puede decir de quién es.
 *
 * El inventario del reverso lista expediente, empresa y beneficiario, y
 * los saca de los snapshots del recibo a través de la asignación a una
 * cuota. Un cheque que estaba en el cajón antes del sistema no tiene
 * recibo emitido ni cuota asignada, así que esas tres columnas salían en
 * blanco — y como un cheque puede quedar años en custodia, saldrían en
 * blanco en cada planilla mensual hasta que se cobre.
 *
 * La apertura ya declara plata que el sistema no respalda con ningún
 * expediente: para eso existe `LEGACY_FUNDS`. Si se acepta cargar cuánto
 * vale un cheque que no está en el sistema, no hay razón para no aceptar
 * de quién dice el papel que es.
 *
 * **Son snapshots, no vínculos.** Llevan los mismos nombres que los de
 * `receipts` a propósito: texto congelado, no una relación hacia Haberes
 * —que Ledger no puede tener—. Es lo que declaró quien abrió los libros,
 * no un dato verificado, y el día que ese cheque se impute a una cuota
 * real manda el snapshot del recibo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_receipts', function (Blueprint $table): void {
            $table->string('expediente_number_snapshot', 40)->nullable();
            $table->string('counterparty_name_snapshot', 160)->nullable();
            $table->string('beneficiary_name_snapshot', 160)->nullable();
        });

        /*
         * Solo tienen sentido sobre un cheque: son las columnas con las que
         * el reverso lo identifica. Sin esto se filtrarían a cualquier
         * recepción y pasarían a competir con los snapshots del recibo,
         * que son la fuente buena cuando existe.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE fund_receipts ADD CONSTRAINT fund_receipts_paper_snapshots_are_for_cheques
            CHECK (
                medium = 'cheque'
                OR (
                    expediente_number_snapshot IS NULL
                    AND counterparty_name_snapshot IS NULL
                    AND beneficiary_name_snapshot IS NULL
                )
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE fund_receipts DROP CONSTRAINT IF EXISTS fund_receipts_paper_snapshots_are_for_cheques');

        Schema::table('fund_receipts', function (Blueprint $table): void {
            $table->dropColumn([
                'expediente_number_snapshot',
                'counterparty_name_snapshot',
                'beneficiary_name_snapshot',
            ]);
        });
    }
};
