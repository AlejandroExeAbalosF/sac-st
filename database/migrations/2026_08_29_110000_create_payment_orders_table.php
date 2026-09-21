<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de Pago — §9.6 del DER.
 *
 * **El papel con el que el área le pide al SAF que transfiera.** Se emite
 * por una sola cuota, ya completamente financiada y con su recibo de
 * ingreso vigente, y viaja al organismo junto con su Pase.
 *
 * Vive en `Haberes` y no en `Shared` —al revés que `receipts`— porque sabe
 * de cuotas, de expedientes y de empleadores: es dominio, no
 * infraestructura de comprobantes. Por eso acá las foráneas hacia la cuota
 * sí existen.
 *
 * ── Desvíos respecto del DER, todos anotados en Correcciones §34 ───────
 *
 * 1. **`order_kind`.** El DER asume que la Orden siempre se titula
 *    «VALORES EN CUSTODIA». El formulario real tiene también «CHEQUES
 *    PROPIOS», y el área confirmó que sirve para los dos: el tipo se elige
 *    antes de generarla.
 * 2. **`organism_bank_account_id`.** El formulario trae las cuentas del
 *    organismo preimpresas y se marca la que corresponde. El DER no
 *    modelaba cuál. El sistema la deriva de dónde está el dinero.
 * 3. **`income_receipt_number_source`.** Cuál de los dos números del
 *    recibo se imprime —el del sistema o el del talonario—. Es la misma
 *    decisión que `receipts.prints_talonario_number` y por el mismo
 *    motivo: la reimpresión tiene que salir igual que el original.
 * 4. **`cbu_folio_snapshot`.** La foja donde el expediente informa el CBU.
 *    Es lo que el Pase redacta —«a la CBU informada en fs. 19»— y sin ese
 *    número la nota no se puede escribir.
 * 5. **`income_receipt_id` es NOT NULL.** El DER lo pide «obligatorio
 *    antes de aprobar». Acá la Orden no se genera sin recibo de ingreso,
 *    así que la base lo exige desde el principio en vez de confiarlo a un
 *    estado posterior.
 *
 * **Lo que no está y el DER sí tiene:** `reviewed_by`, `approved_by`,
 * `approved_at` y `sent_at`. Son del circuito de aprobación y envío, que
 * viaja con `remisiones` en la tanda siguiente. Los estados sí quedan
 * declarados en el `CHECK`, porque un juego de estados se define entero o
 * queda a medias.
 */
return new class extends Migration
{
    /**
     * Los estados en los que la Orden ocupa el lugar de su cuota.
     *
     * Mientras esté en uno de ellos no puede haber otra: son las etapas en
     * las que el documento existe y está en circulación. `rejected` y
     * `voided` quedan afuera, que es lo que permite emitir la reemplazante.
     */
    private const ACTIVE_STATUSES = "'draft', 'reviewed', 'approved', 'sent', "
        ."'transfer_reported', 'bank_debit_observed', 'ready_for_validation'";

    public function up(): void
    {
        Schema::create('payment_orders', function (Blueprint $table): void {
            $table->id();

            /*
             * Serie 0030. El correlativo se toma bajo lock igual que el
             * del recibo: dos operadores emitiendo a la vez no pueden
             * llevarse el mismo número.
             */
            $table->foreignId('document_series_id')->constrained('document_series')->restrictOnDelete();
            $table->unsignedBigInteger('number');
            $table->string('formatted_number', 40);

            $table->date('order_date');
            $table->string('order_kind', 30)->default('custody_values');

            $table->foreignId('beneficiary_installment_id')
                ->constrained('beneficiary_installments')
                ->restrictOnDelete();

            $table->decimal('amount', 19, 2);
            $table->string('status', 30)->default('draft');
            $table->foreignId('replaces_order_id')->nullable()
                ->constrained('payment_orders')->restrictOnDelete();

            /*
             * ─── El beneficiario y su cuenta ──────────────────────────
             *
             * El sistema opera únicamente con CBU y la cuenta tiene que
             * estar verificada (§2.2.9). Que la cuenta sea del
             * beneficiario lo garantiza la foránea compuesta de más
             * abajo, no una validación de PHP.
             */
            $table->unsignedBigInteger('beneficiary_bank_account_id')->nullable();
            $table->unsignedBigInteger('beneficiary_person_id')->nullable();
            $table->string('beneficiary_cbu_snapshot', 22)->nullable();
            $table->string('beneficiary_bank_name_snapshot', 120)->nullable();
            $table->string('beneficiary_account_number_snapshot', 80)->nullable();
            $table->string('beneficiary_name_snapshot', 200);
            $table->string('beneficiary_document_snapshot', 40)->nullable();
            $table->string('beneficiary_address_snapshot', 300)->nullable();
            $table->string('beneficiary_phone_snapshot', 40)->nullable();
            /** La foja del expediente donde el CBU está informado: «fs. 19». */
            $table->string('cbu_folio_snapshot', 40)->nullable();

            /* ─── El empleador y el expediente ───────────────────────── */
            $table->string('employer_name_snapshot', 200)->nullable();
            $table->string('employer_tax_identifier_snapshot', 40)->nullable();
            $table->string('employer_address_snapshot', 300)->nullable();
            $table->string('employer_phone_snapshot', 40)->nullable();
            $table->string('expediente_number_snapshot', 40);
            /** El canónico completo, que es lo que el Pase pone en «Ref.». */
            $table->string('expediente_canonical_snapshot', 60)->nullable();
            /** La carátula: «Demanda Laboral — MENDOZA … VS. DAEWON SRL». */
            $table->string('expediente_subject_snapshot', 300)->nullable();
            /** «FECHA INI.»: cuándo entró el expediente y empezó la custodia. */
            $table->date('custody_start_date_snapshot')->nullable();

            /* ─── La cuenta del organismo que el formulario marca ────── */
            $table->foreignId('organism_bank_account_id')->nullable()
                ->constrained('bank_accounts')->restrictOnDelete();

            /* ─── Los comprobantes de los dos extremos ───────────────── */
            $table->foreignId('income_receipt_id')->constrained('receipts')->restrictOnDelete();
            $table->string('income_receipt_number_source', 20)->default('system');
            $table->string('income_receipt_number_snapshot', 40);
            $table->foreignId('expense_receipt_id')->nullable()
                ->constrained('receipts')->restrictOnDelete();

            /* ─── El cheque, cuando el valor en custodia es uno ──────── */
            $table->string('cheque_number_snapshot', 40)->nullable();
            $table->string('cheque_bank_snapshot', 120)->nullable();

            /* ─── Quién firma ────────────────────────────────────────── */
            $table->foreignId('treasurer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('treasurer_name_snapshot', 200)->nullable();
            $table->string('treasurer_title_snapshot', 120)->nullable();
            $table->timestampTz('treasurer_signed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('rejection_or_void_reason', 300)->nullable();

            /** El campo `OBS` del formulario. La Orden 3582 lo usa. */
            $table->text('notes')->nullable();

            $table->timestampsTz();

            $table->unique(['document_series_id', 'number']);
            $table->index('status');
            $table->index('beneficiary_installment_id');
            $table->index('order_date');
        });

        /*
         * La cuenta elegida pertenece al beneficiario, garantizado por la
         * base. Es el mismo par (id, person_id) que ya usa `haberes` para
         * su cuenta preferida.
         */
        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_beneficiary_account_fk
            FOREIGN KEY (beneficiary_bank_account_id, beneficiary_person_id)
            REFERENCES person_bank_accounts (id, person_id)');

        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_kind_check
            CHECK (order_kind IN ('custody_values', 'own_cheques'))");

        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_status_check
            CHECK (status IN ('.self::ACTIVE_STATUSES.", 'completed', 'rejected', 'voided'))");

        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_receipt_source_check
            CHECK (income_receipt_number_source IN ('system', 'talonario'))");

        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_amount_check
            CHECK (amount > 0)');

        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_cbu_check
            CHECK (beneficiary_cbu_snapshot IS NULL OR beneficiary_cbu_snapshot ~ '^[0-9]{22}$')");

        /*
         * ─── Una Orden de valores en custodia sin destino no existe ───
         *
         * Es el papel que pide transferir *desde* la cuenta del organismo
         * *hacia* la del beneficiario. Sin una de las dos puntas no hay
         * nada que pedir, y el Pase no se puede redactar.
         *
         * `own_cheques` queda fuera a propósito: el área todavía no
         * confirmó qué significa esa casilla del formulario, y exigirle
         * datos que quizá no lleve sería inventar la regla.
         */
        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_custody_accounts_check
            CHECK (
                order_kind <> 'custody_values'
                OR (
                    beneficiary_bank_account_id IS NOT NULL
                    AND beneficiary_cbu_snapshot IS NOT NULL
                    AND organism_bank_account_id IS NOT NULL
                )
            )");

        /* La cuenta y su dueño viajan juntos o no viajan. */
        DB::statement('ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_account_pair_check
            CHECK ((beneficiary_bank_account_id IS NULL) = (beneficiary_person_id IS NULL))');

        /* Anular o rechazar exige decir quién y por qué. */
        DB::statement("ALTER TABLE payment_orders ADD CONSTRAINT payment_orders_void_check
            CHECK (
                status NOT IN ('voided', 'rejected')
                OR (voided_by IS NOT NULL AND voided_at IS NOT NULL
                    AND rejection_or_void_reason IS NOT NULL)
            )");

        /*
         * ─── Una sola Orden activa por cuota ─────────────────────────
         *
         * §2.2.4. Dos Órdenes vivas por la misma cuota son dos pedidos de
         * transferencia por el mismo dinero, y el organismo no tiene cómo
         * saber cuál vale. Parcial sobre los estados en circulación: la
         * anulada convive con la que la reemplaza, que es el camino que
         * §2.2.3 contempla.
         */
        DB::statement('CREATE UNIQUE INDEX payment_orders_one_active_per_installment
            ON payment_orders (beneficiary_installment_id)
            WHERE status IN ('.self::ACTIVE_STATUSES.')');

        /*
         * ─── Append-only sobre lo que el papel dice ──────────────────
         *
         * Igual que el recibo: una Orden mal emitida no se corrige, se
         * anula y se emite otra encadenada por `replaces_order_id`. El
         * organismo ya tiene el papel en la mano; editarlo en silencio
         * dejaría el registro diciendo algo distinto del documento que
         * está circulando.
         *
         * Lo que sí se mueve es el estado, el firmante, el recibo de
         * egreso que llega después y las notas.
         */
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

        DB::statement('CREATE TRIGGER payment_orders_append_only
            BEFORE UPDATE OR DELETE ON payment_orders
            FOR EACH ROW EXECUTE FUNCTION payment_orders_append_only()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS payment_orders_append_only ON payment_orders');
        DB::statement('DROP FUNCTION IF EXISTS payment_orders_append_only()');

        Schema::dropIfExists('payment_orders');
    }
};
