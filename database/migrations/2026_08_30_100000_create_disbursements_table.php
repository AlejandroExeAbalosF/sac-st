<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Egresos al beneficiario — §9.7 del DER.
 *
 * El dinero saliendo hacia su dueño: el otro extremo de `fund_receipts`,
 * que registra lo que entró y de quién.
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

            /*
             * El débito del extracto que prueba la transferencia. Es lo que
             * separa «el organismo dice que pagó» de «el banco muestra que
             * salió», y con dinero de terceros esa diferencia es la razón
             * de ser del control (§9.7, invariantes 12 y 13).
             */
            $table->foreignId('bank_transaction_id')->nullable()
                ->constrained('bank_transactions')->restrictOnDelete();

            $table->index('beneficiary_installment_id');
            $table->index('status');
            $table->index('payment_date');
            $table->index('bank_transaction_id');
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
         * El contador coteja la correspondencia entre Orden, informe y
         * débito, y recién ahí el egreso es un hecho: un informe sin débito
         * no genera egreso (invariante 12); un débito sin informe, tampoco.
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

        /* La tercera condición del §9.7: un egreso por transferencia no se
           confirma sin el débito que lo prueba contra el extracto. */
        DB::statement("ALTER TABLE disbursements ADD CONSTRAINT disbursements_transfer_needs_debit_check
            CHECK (
                status <> 'confirmed'
                OR method <> 'bank_transfer'
                OR bank_transaction_id IS NOT NULL
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
         *
         * `bank_transaction_id` queda fijo recién al confirmar: mientras el
         * egreso se prepara es justamente lo que se corrige.
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

                IF OLD.status = 'confirmed'
                    AND NEW.bank_transaction_id IS DISTINCT FROM OLD.bank_transaction_id
                THEN
                    RAISE EXCEPTION 'El débito de un egreso confirmado no se cambia: hay un asiento que lo referencia.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER disbursements_append_only
            BEFORE UPDATE OR DELETE ON disbursements
            FOR EACH ROW EXECUTE FUNCTION disbursements_append_only()');

        /*
         * Un recibo de egreso por cuota, y solo con el egreso ya confirmado:
         * el comprobante dice que el dinero salio, asi que no puede emitirse
         * antes de que haya salido.
         */
        DB::statement("CREATE UNIQUE INDEX receipts_one_expense_per_installment
            ON receipts (beneficiary_installment_id)
            WHERE receipt_type = 'expense'
              AND status = 'issued'
              AND beneficiary_installment_id IS NOT NULL");

        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION receipts_expense_needs_confirmed_disbursement() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF NEW.receipt_type <> 'expense'
                    OR NEW.beneficiary_installment_id IS NULL
                    OR NEW.status <> 'issued'
                THEN
                    RETURN NEW;
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM disbursements
                    WHERE disbursements.beneficiary_installment_id = NEW.beneficiary_installment_id
                      AND disbursements.status = 'confirmed'
                ) THEN
                    RAISE EXCEPTION 'El recibo de egreso documenta un pago que todavía no ocurrió: '
                        'la cuota no tiene ningún egreso confirmado.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement('CREATE TRIGGER receipts_expense_needs_confirmed_disbursement
            BEFORE INSERT OR UPDATE ON receipts
            FOR EACH ROW EXECUTE FUNCTION receipts_expense_needs_confirmed_disbursement()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS disbursements_append_only ON disbursements');
        DB::statement('DROP FUNCTION IF EXISTS disbursements_append_only()');

        Schema::dropIfExists('disbursements');
    }
};
