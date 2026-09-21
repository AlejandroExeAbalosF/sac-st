<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El medio previsto deja de ser opcional.
 *
 * Nació opcional porque el DER lo trata como una previsión y no como una
 * decisión: el medio real lo fija la primera recepción. Pero una cuota sin
 * medio declarado no se puede leer —no dice si se va a cobrar por
 * mostrador o si se espera un depósito—, y el área confirmó que al cargar
 * el expediente siempre se sabe. Lo que era un dato ausente pasa a ser un
 * dato que hay que declarar.
 *
 * ── Las cuotas que ya existen ──────────────────────────────────────────
 *
 * Dos pasos, y el orden importa:
 *
 * 1. **A las que tienen plata adentro se les deriva el medio real**, el
 *    que fijó su recepción y va impreso en el recibo. No es inventar: es
 *    escribir en la columna un hecho que ya está asentado en el libro.
 * 2. **A las que no tienen plata se les pone `cash`**, que es cómo entra
 *    la mayoría en Haberes. Sin dinero imputado el dato todavía no decidió
 *    nada —ninguna Orden se emitió sobre él, ningún recibo lo imprimió— y
 *    se corrige desde la pantalla sin consecuencia contable.
 *
 * La reversión devuelve la columna a nullable pero **no vacía lo que se
 * completó**: esos valores pasaron a ser parte del expediente y borrarlos
 * perdería los que alguien haya corregido a mano después.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * El medio de la recepción viva de cada cuota. `DISTINCT ON`
         * resuelve la primera por id, que es el mismo criterio de
         * `InstallmentFunding::medium()`: el medio lo fija la primera
         * recepción, no la última.
         */
        DB::statement(<<<'SQL'
            UPDATE beneficiary_installments AS bi
            SET expected_medium = origen.medium
            FROM (
                SELECT DISTINCT ON (fa.beneficiary_installment_id)
                    fa.beneficiary_installment_id AS cuota,
                    fr.medium
                FROM funding_allocations AS fa
                JOIN fund_receipts AS fr ON fr.id = fa.fund_receipt_id
                WHERE fa.allocation_kind <> 'reversal'
                ORDER BY fa.beneficiary_installment_id, fa.id
            ) AS origen
            WHERE bi.id = origen.cuota
              AND bi.expected_medium IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE beneficiary_installments
            SET expected_medium = 'cash'
            WHERE expected_medium IS NULL
        SQL);

        /*
         * Los `UPDATE` de arriba dejan triggers diferidos pendientes sobre
         * la tabla, y PostgreSQL no deja hacerle `ALTER TABLE` mientras los
         * tenga: «no se puede hacer ALTER TABLE porque tiene eventos de
         * trigger pendientes». Forzarlos acá los dispara y los limpia
         * dentro de la misma transacción, que es lo que permite seguir.
         */
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        DB::statement('ALTER TABLE beneficiary_installments ALTER COLUMN expected_medium SET NOT NULL');

        /*
         * Y el CHECK deja de admitir el nulo. Se reemplaza en vez de
         * agregarse otro: dos restricciones sobre la misma columna diciendo
         * cosas parecidas es lo que después nadie sabe cuál manda.
         */
        DB::statement('ALTER TABLE beneficiary_installments DROP CONSTRAINT installments_medium_check');
        DB::statement(<<<'SQL'
            ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_medium_check
            CHECK (expected_medium IN ('cash', 'cheque', 'bank'))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE beneficiary_installments DROP CONSTRAINT installments_medium_check');
        DB::statement(<<<'SQL'
            ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_medium_check
            CHECK (expected_medium IS NULL OR expected_medium IN ('cash', 'cheque', 'bank'))
        SQL);

        DB::statement('ALTER TABLE beneficiary_installments ALTER COLUMN expected_medium DROP NOT NULL');
    }
};
