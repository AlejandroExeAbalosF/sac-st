<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La Orden deja de tener tipo.
 *
 * **El formulario cambió.** El papel encabezaba con dos títulos —«VALORES
 * EN CUSTODIA» y «CHEQUES PROPIOS»—, cada uno con su casilla, y el área
 * confirmó que esos campos ya no van. Sin las casillas no hay nada que
 * elegir, y una columna que sólo puede decir una cosa no describe al
 * dominio: lo decora.
 *
 * `own_cheques` nunca llegó a existir. Estaba declarado en el enum a la
 * espera de que el área definiera qué significaba, pero el `FormRequest` y
 * el `Action` lo rechazaban desde el primer día. Por eso esta migración no
 * pierde información: todas las filas de `payment_orders` dicen
 * `custody_values`.
 *
 * ── Lo que se gana, y es el punto ──────────────────────────────────────
 *
 * `payment_orders_custody_accounts_check` era **condicional**: exigía las
 * dos puntas de la transferencia —cuenta del beneficiario, CBU y cuenta
 * del organismo— sólo cuando el tipo era `custody_values`, y dejaba a
 * `own_cheques` afuera porque nadie sabía qué datos llevaba. Al quedar un
 * solo circuito, la excepción se queda sin caso y el invariante pasa a ser
 * incondicional: **ninguna Orden de Pago existe sin destino**. Es una
 * regla más fuerte que la de ayer, no la misma escrita distinto.
 *
 * El desvío queda anotado en Correcciones §34, que es donde vivía el
 * desvío original que introdujo la columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Las dos guardas que nombran la columna. Postgres las tiraría
         * solo al borrarla, pero entonces la de cuentas se iría en
         * silencio y la Orden quedaría un instante sin su invariante más
         * importante. Se bajan a mano y se vuelve a subir la que queda.
         */
        DB::statement('ALTER TABLE payment_orders DROP CONSTRAINT payment_orders_kind_check');
        DB::statement('ALTER TABLE payment_orders DROP CONSTRAINT payment_orders_custody_accounts_check');

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->dropColumn('order_kind');
        });

        /*
         * ─── Una Orden sin destino no existe ─────────────────────────
         *
         * Es el papel que pide transferir *desde* la cuenta del organismo
         * *hacia* la del beneficiario. Sin una de las dos puntas no hay
         * nada que pedir, y el Pase no se puede redactar.
         */
        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_accounts_check
            CHECK (
                beneficiary_bank_account_id IS NOT NULL
                AND beneficiary_cbu_snapshot IS NOT NULL
                AND organism_bank_account_id IS NOT NULL
            )');

        $this->appendOnlySinTipo();
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_orders DROP CONSTRAINT payment_orders_accounts_check');

        Schema::table('payment_orders', function (Blueprint $table): void {
            $table->string('order_kind', 30)->default('custody_values');
        });

        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_kind_check
            CHECK (order_kind IN ('custody_values', 'own_cheques'))");

        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_custody_accounts_check
            CHECK (
                order_kind <> 'custody_values'
                OR (
                    beneficiary_bank_account_id IS NOT NULL
                    AND beneficiary_cbu_snapshot IS NOT NULL
                    AND organism_bank_account_id IS NOT NULL
                )
            )");

        $this->appendOnlyConTipo();
    }

    /**
     * El append-only, sin el renglón de `order_kind`.
     *
     * Se reescribe entera con `CREATE OR REPLACE` en vez de parchearla:
     * la función es la definición de qué datos del papel son inmutables, y
     * leerla completa acá es lo que permite verificar la lista sin ir a
     * buscar la migración que la creó.
     */
    private function appendOnlySinTipo(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_orders_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una Orden de Pago no se borra: se anula y se emite la que la reemplaza.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.order_date IS DISTINCT FROM OLD.order_date
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.beneficiary_bank_account_id IS DISTINCT FROM OLD.beneficiary_bank_account_id
                    OR NEW.beneficiary_cbu_snapshot IS DISTINCT FROM OLD.beneficiary_cbu_snapshot
                    OR NEW.beneficiary_name_snapshot IS DISTINCT FROM OLD.beneficiary_name_snapshot
                    OR NEW.beneficiary_document_snapshot IS DISTINCT FROM OLD.beneficiary_document_snapshot
                    OR NEW.employer_name_snapshot IS DISTINCT FROM OLD.employer_name_snapshot
                    OR NEW.expediente_number_snapshot IS DISTINCT FROM OLD.expediente_number_snapshot
                    OR NEW.organism_bank_account_id IS DISTINCT FROM OLD.organism_bank_account_id
                    OR NEW.income_receipt_id IS DISTINCT FROM OLD.income_receipt_id
                    OR NEW.income_receipt_number_snapshot IS DISTINCT FROM OLD.income_receipt_number_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos impresos de una Orden no se editan: se anula y se emite otra.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    /** La misma función, con el renglón que la vuelta atrás necesita. */
    private function appendOnlyConTipo(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION payment_orders_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una Orden de Pago no se borra: se anula y se emite la que la reemplaza.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.order_date IS DISTINCT FROM OLD.order_date
                    OR NEW.order_kind IS DISTINCT FROM OLD.order_kind
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.beneficiary_bank_account_id IS DISTINCT FROM OLD.beneficiary_bank_account_id
                    OR NEW.beneficiary_cbu_snapshot IS DISTINCT FROM OLD.beneficiary_cbu_snapshot
                    OR NEW.beneficiary_name_snapshot IS DISTINCT FROM OLD.beneficiary_name_snapshot
                    OR NEW.beneficiary_document_snapshot IS DISTINCT FROM OLD.beneficiary_document_snapshot
                    OR NEW.employer_name_snapshot IS DISTINCT FROM OLD.employer_name_snapshot
                    OR NEW.expediente_number_snapshot IS DISTINCT FROM OLD.expediente_number_snapshot
                    OR NEW.organism_bank_account_id IS DISTINCT FROM OLD.organism_bank_account_id
                    OR NEW.income_receipt_id IS DISTINCT FROM OLD.income_receipt_id
                    OR NEW.income_receipt_number_snapshot IS DISTINCT FROM OLD.income_receipt_number_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos impresos de una Orden no se editan: se anula y se emite otra.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
