<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Egresos al beneficiario — §9.7 del DER.
 *
 * **El dinero saliendo hacia su dueño.** Es el otro extremo de
 * `fund_receipts`: aquella registra que entró y de quién; ésta, que salió
 * y hacia quién. Entre las dos está todo lo que este sistema existe para
 * responder.
 *
 * Vive en `Haberes` y no en `Ledger` por lo mismo que `funding_allocations`:
 * sabe de cuotas y de Órdenes de Pago, que son dominio. El asiento que la
 * respalda sí es de Ledger, y viaja en `financial_event_id`.
 *
 * ── Los dos caminos, y por qué el estado es uno solo ───────────────────
 *
 * | Canal | Qué exige para quedar `confirmed` |
 * |---|---|
 * | Mostrador (`cash`, `cheque`) | la entrega: quién pagó y cuándo se llevó el dinero |
 * | Transferencia (`bank_transfer`) | informe + débito vinculado + validación del contador |
 *
 * El mostrador nace `confirmed`: el beneficiario está enfrente, firma y se
 * lleva la plata, y no hay nada posterior que esperar. La transferencia
 * recorre los estados intermedios porque cada uno es un hecho que ocurre
 * en un momento distinto y que alguien tiene que poder ver por separado
 * —el §12.3 y el §12.4 del DER son justamente los dos órdenes posibles en
 * que llegan el informe y el débito—.
 *
 * **Los siete estados quedan declarados enteros desde esta migración**,
 * igual que en `payment_orders` y por el mismo motivo: viven en un `CHECK`
 * sobre una tabla append-only, y agregarlos de a uno sería una migración
 * por etapa del circuito sobre una tabla que para entonces ya tendría
 * egresos reales.
 *
 * ── Lo que la base no puede imponer ────────────────────────────────────
 *
 * De las tres condiciones que el §9.7 exige para confirmar una
 * transferencia, dos son columnas de esta tabla y quedan en un `CHECK`. La
 * tercera —que exista un débito bancario vinculado en
 * `bank_transaction_allocations`— es una fila de otra tabla, y un `CHECK`
 * no puede consultarla. Queda en el Action que confirma, con su test.
 */
return new class extends Migration
{
    /**
     * Los estados en los que el egreso ocupa el lugar de su cuota.
     *
     * Todo lo que no fracasó ni se revirtió: mientras el egreso esté vivo
     * no puede haber otro, porque serían dos pagos por el mismo dinero.
     */
    private const LIVE_STATUSES = "'pending', 'report_received', 'bank_debit_observed', "
        ."'ready_for_validation', 'confirmed'";

    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table): void {
            $table->id();

            /*
             * El asiento que lo respalda.
             *
             * Nulo mientras el egreso se está preparando: el §9.7 dice que
             * el evento «existe de forma definitiva al confirmar», y
             * asentar antes pondría en los libros una salida de dinero que
             * todavía no ocurrió. Al confirmar deja de poder ser nulo, y
             * eso lo impone el CHECK de más abajo.
             */
            $table->foreignId('financial_event_id')->nullable()->unique()
                ->constrained('financial_events')->restrictOnDelete();

            $table->foreignId('beneficiary_installment_id')
                ->constrained('beneficiary_installments')
                ->restrictOnDelete();

            /*
             * La Orden que lo autoriza. Obligatoria en la transferencia
             * —el organismo no mueve dinero sin ella— y opcional en el
             * mostrador, donde el §2.5.5 igual la contempla: entregar el
             * cheque en custodia puede llevar su Orden de «VALORES EN
             * CUSTODIA».
             */
            $table->foreignId('payment_order_id')->nullable()
                ->constrained('payment_orders')->restrictOnDelete();

            $table->string('method', 20);
            $table->decimal('amount', 19, 2);
            $table->string('status', 30)->default('pending');

            /** El día en que el dinero efectivamente salió. */
            $table->date('payment_date')->nullable();

            /* ─── La transferencia ───────────────────────────────────── */
            /**
             * El CBU realmente utilizado (invariante 8 del §12.7).
             *
             * Congelado acá y no leído de la cuenta: entre que la Orden se
             * emitió y el organismo transfirió pueden haber corregido el
             * dato, y lo que este egreso documenta es a dónde fue el
             * dinero, no a dónde iría hoy.
             */
            $table->string('beneficiary_cbu_snapshot', 22)->nullable();
            $table->string('transfer_reference', 80)->nullable();
            $table->timestampTz('report_received_at')->nullable();
            $table->timestampTz('bank_debit_observed_at')->nullable();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('validated_at')->nullable();

            /* ─── El mostrador ───────────────────────────────────────── */
            $table->foreignId('cash_delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('received_by_beneficiary_at')->nullable();

            $table->string('failure_reason', 300)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();

            $table->index('beneficiary_installment_id');
            $table->index('status');
            $table->index('payment_date');
        });

        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_method_check
            CHECK (method IN ('cash', 'cheque', 'bank_transfer'))");

        DB::statement('ALTER TABLE disbursements ADD CONSTRAINT disbursements_status_check
            CHECK (status IN ('.self::LIVE_STATUSES.", 'reversed', 'failed'))");

        DB::statement('ALTER TABLE disbursements ADD CONSTRAINT disbursements_amount_check
            CHECK (amount > 0)');

        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_cbu_check
            CHECK (beneficiary_cbu_snapshot IS NULL OR beneficiary_cbu_snapshot ~ '^[0-9]{22}$')");

        /*
         * ─── La transferencia no existe sin su Orden ─────────────────
         *
         * El organismo no mueve dinero de un tercero sin el papel que se
         * lo pide. Un egreso por transferencia sin Orden sería un pago que
         * nadie autorizó.
         */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_transfer_needs_order_check
            CHECK (method <> 'bank_transfer' OR payment_order_id IS NOT NULL)");

        /*
         * ─── Confirmar es afirmar que el dinero salió ────────────────
         *
         * Y si salió, hay un asiento que lo dice y un día en que ocurrió.
         * Sin eso, «confirmado» sería una etiqueta sin respaldo en los
         * libros, que es exactamente lo que este sistema no puede tener.
         */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_confirmed_check
            CHECK (
                status <> 'confirmed'
                OR (financial_event_id IS NOT NULL AND payment_date IS NOT NULL)
            )");

        /*
         * ─── Las condiciones del §9.7 para la transferencia ──────────
         *
         * Dos de las tres. La tercera —el débito vinculado en
         * `bank_transaction_allocations`— vive en otra tabla y la impone
         * el Action que confirma.
         *
         * El §2.3.4 es la razón de la validación: el contador coteja la
         * correspondencia entre Orden, informe y débito, y recién ahí el
         * egreso es un hecho. Un informe sin débito no genera egreso
         * (invariante 12); un débito sin informe, tampoco.
         */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_transfer_confirmed_check
            CHECK (
                status <> 'confirmed'
                OR method <> 'bank_transfer'
                OR (
                    report_received_at IS NOT NULL
                    AND bank_debit_observed_at IS NOT NULL
                    AND validated_by IS NOT NULL
                    AND validated_at IS NOT NULL
                )
            )");

        /*
         * ─── El mostrador dice quién pagó y cuándo se lo llevaron ────
         *
         * Es lo único que respalda una entrega en mano: no hay extracto
         * que la confirme después ni organismo que la informe. El papel
         * firmado y estas dos columnas son toda la evidencia, y por eso
         * ninguna de las dos puede faltar.
         */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_counter_confirmed_check
            CHECK (
                status <> 'confirmed'
                OR method = 'bank_transfer'
                OR (cash_delivered_by IS NOT NULL AND received_by_beneficiary_at IS NOT NULL)
            )");

        /* Fallar exige decir por qué. Un egreso caído sin motivo no se puede retomar. */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_failure_check
            CHECK (status <> 'failed' OR failure_reason IS NOT NULL)");

        /*
         * ─── Un solo egreso vivo por cuota ───────────────────────────
         *
         * La cuota se entrega completa y una sola vez. Dos egresos vivos
         * por la misma cuota serían dos pagos por el mismo dinero, y el
         * segundo saldría de fondos que ya no están.
         *
         * Parcial sobre los vivos: el revertido y el fallido conviven con
         * el que los reemplaza, que es el camino por el que un egreso
         * caído se vuelve a intentar.
         */
        DB::statement('CREATE UNIQUE INDEX disbursements_one_live_per_installment
            ON disbursements (beneficiary_installment_id)
            WHERE status IN ('.self::LIVE_STATUSES.')');

        /*
         * ─── Append-only sobre el hecho monetario ────────────────────
         *
         * Lo que este registro afirma —cuánto salió, por qué vía, de qué
         * cuota, con qué asiento— no se edita: es un movimiento de dinero
         * de un tercero. Un egreso equivocado se revierte con su
         * contrapartida, que es lo que el estado `reversed` documenta.
         *
         * Lo que sí avanza es el estado y las fechas del circuito: son las
         * etapas por las que el egreso pasa, y escribirlas es justamente
         * para lo que la tabla existe.
         *
         * `financial_event_id` se protege **una vez asignado**. Ponerlo es
         * el acto de confirmar; cambiarlo después sería mover el asiento
         * que respalda un pago ya hecho.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION disbursements_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un egreso no se borra: se revierte con su contrapartida.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.method IS DISTINCT FROM OLD.method
                    OR (OLD.financial_event_id IS NOT NULL
                        AND NEW.financial_event_id IS DISTINCT FROM OLD.financial_event_id)
                THEN
                    RAISE EXCEPTION 'El importe, la vía y el asiento de un egreso no se editan: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER disbursements_append_only
            BEFORE UPDATE OR DELETE ON disbursements
            FOR EACH ROW EXECUTE FUNCTION disbursements_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS disbursements_append_only ON disbursements');
        DB::statement('DROP FUNCTION IF EXISTS disbursements_append_only()');

        Schema::dropIfExists('disbursements');
    }
};
