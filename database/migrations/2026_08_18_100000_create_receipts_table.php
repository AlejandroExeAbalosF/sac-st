<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recibos — §9.8 del DER.
 *
 * **El comprobante que el área entrega.** El de ingreso se emite una sola
 * vez, cuando la cuota queda completamente financiada (§2.1.14): *«Un
 * ingreso parcial no genera comprobante de ningún tipo para el
 * empleador»*.
 *
 * `beneficiary_installment_id` va **sin foránea, a propósito**: esta tabla
 * es de Shared y no puede depender de Haberes. La integridad la garantiza
 * el Action que emite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('document_series_id')->constrained('document_series')->restrictOnDelete();

            /*
             * El número del sistema es **siempre** el identificador.
             *
             * El área confirmó que el del talonario no compite con él: se
             * registra al lado cuando el papel existe. Eso es lo que
             * disuelve el problema del recibo emitido el 28/12 y cargado
             * el 05/01, que con dos numeraciones en pie de igualdad no
             * tenía una solución buena.
             */
            $table->unsignedBigInteger('number');
            $table->string('formatted_number', 40);
            $table->string('talonario_number', 40)->nullable();

            $table->string('receipt_type', 20);

            /** «Recibí de» en el ingreso; el beneficiario en el egreso. */
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->unsignedBigInteger('beneficiary_installment_id')->nullable();

            /*
             * Lo que el papel dice, congelado.
             *
             * No son duplicados por comodidad: el comprobante es un
             * documento entregado, y si mañana alguien corrige el nombre
             * del beneficiario en el maestro, el papel que está en poder
             * del empleador sigue diciendo lo que decía.
             */
            $table->string('concept_snapshot', 300)->nullable();
            $table->string('medium_snapshot', 20);
            $table->string('counterparty_name_snapshot', 200)->nullable();
            $table->string('beneficiary_name_snapshot', 200)->nullable();
            $table->string('beneficiary_document_snapshot', 40)->nullable();
            $table->string('expediente_number_snapshot', 40)->nullable();
            $table->string('installment_label_snapshot', 60)->nullable();

            $table->decimal('amount', 19, 2);
            $table->date('issue_date');

            $table->string('status', 20)->default('issued');
            $table->string('issue_mode', 30)->default('online');

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            /** Cuándo se cargó, si no fue en el momento de emitirlo. */
            $table->timestampTz('recorded_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('voided_at')->nullable();
            $table->string('void_reason', 300)->nullable();
            $table->foreignId('replaces_receipt_id')->nullable()->constrained('receipts')->restrictOnDelete();

            $table->timestampsTz();

            $table->unique(['document_series_id', 'number']);
            $table->index(['receipt_type', 'status']);
            $table->index('beneficiary_installment_id');
            $table->index('issue_date');
        });

        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_type_check
            CHECK (receipt_type IN ('income', 'expense'))");
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_status_check
            CHECK (status IN ('issued', 'voided', 'spoiled', 'replaced'))");
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_issue_mode_check
            CHECK (issue_mode IN ('online', 'offline_talonario'))");
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_medium_check
            CHECK (medium_snapshot IN ('cash', 'cheque', 'bank'))");
        DB::statement('ALTER TABLE receipts ADD CONSTRAINT receipts_amount_check
            CHECK (amount > 0)');

        /* Anular exige decir quién y por qué. */
        DB::statement("ALTER TABLE receipts ADD CONSTRAINT receipts_void_check
            CHECK (
                status NOT IN ('voided', 'replaced')
                OR (voided_by IS NOT NULL AND voided_at IS NOT NULL AND void_reason IS NOT NULL)
            )");

        /*
         * ─── Un solo recibo de ingreso vigente por cuota ──────────────
         *
         * §2.1.14: el recibo se emite **una sola vez**, cuando la cuota
         * queda completamente financiada. Dos recibos vigentes para la
         * misma cuota serían dos documentos entregados por el mismo
         * dinero.
         *
         * Parcial sobre los emitidos: anular y reemplazar es un camino
         * legítimo, y el anulado tiene que poder convivir con el nuevo.
         */
        DB::statement("CREATE UNIQUE INDEX receipts_one_income_per_installment
            ON receipts (beneficiary_installment_id)
            WHERE receipt_type = 'income'
              AND status = 'issued'
              AND beneficiary_installment_id IS NOT NULL");

        /*
         * ─── El número del talonario no se repite ─────────────────────
         *
         * Parcial solo por el nulo, para que convivan los muchos recibos
         * que no salen de un talonario. Entre los que sí es total, y
         * **alcanza también a los anulados**: si la hoja 00071514 se
         * arruinó, ese número existió en papel y no vuelve a usarse.
         */
        DB::statement('CREATE UNIQUE INDEX receipts_talonario_number_unique
            ON receipts (document_series_id, talonario_number)
            WHERE talonario_number IS NOT NULL');

        /*
         * Append-only, con la precisión del §8: el comprobante —número,
         * importe, a quién, por qué concepto— no se edita nunca. Lo que
         * cambia es su estado, y cada cambio queda en `audit_events`.
         *
         * Un recibo mal emitido no se corrige: se anula y se emite otro
         * que lo referencia. Es como funciona un talonario.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION receipts_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un comprobante no se borra: se anula y se emite el reemplazante.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.document_series_id IS DISTINCT FROM OLD.document_series_id
                    OR NEW.number IS DISTINCT FROM OLD.number
                    OR NEW.formatted_number IS DISTINCT FROM OLD.formatted_number
                    OR NEW.talonario_number IS DISTINCT FROM OLD.talonario_number
                    OR NEW.receipt_type IS DISTINCT FROM OLD.receipt_type
                    OR NEW.person_id IS DISTINCT FROM OLD.person_id
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.issue_date IS DISTINCT FROM OLD.issue_date
                    OR NEW.medium_snapshot IS DISTINCT FROM OLD.medium_snapshot
                THEN
                    RAISE EXCEPTION 'Los datos de un comprobante no se editan: se anula y se emite otro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER receipts_append_only
            BEFORE UPDATE OR DELETE ON receipts
            FOR EACH ROW EXECUTE FUNCTION receipts_append_only()');

        /*
         * Quien firma el comprobante, con su nombre y cargo congelados: el
         * papel esta en manos de alguien y no puede cambiar porque despues
         * se corrija el legajo.
         */
        Schema::table('receipts', function (Blueprint $table): void {
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signed_by_name_snapshot', 200)->nullable();
            $table->string('signed_by_title_snapshot', 120)->nullable();

            /*
             * Si se imprime el numero del talonario preimpreso en lugar del
             * que lleva el sistema. La reimpresion tiene que salir igual que
             * el original.
             */
            $table->boolean('prints_talonario_number')->default(false);
        });

        DB::statement('ALTER TABLE receipts
            ADD CONSTRAINT receipts_prints_talonario_requires_number
            CHECK (NOT prints_talonario_number OR talonario_number IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS receipts_append_only ON receipts');
        DB::statement('DROP FUNCTION IF EXISTS receipts_append_only()');

        Schema::dropIfExists('receipts');
    }
};
