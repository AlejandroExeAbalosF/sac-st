<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cierres de período — §9.9 del DER.
 *
 * Es el anverso de la planilla, fila por fila. La de junio de 2026 dice:
 *
 * ```text
 * PLANILLA DE HABERES EN CONSIGNACION 02/06/2026
 * RECIBO Nº     | EFECTIVO   | CHEQUES    | DEPOSITOS DIRECTOS
 * SALDO INICIAL |  6.852.300 | 673.804,70 | 1.902.907,01
 * 72190…72198     (una fila por recibo de ingreso)
 * INGRESOS      | 24.985.600 |          0 |            0
 * 76397…76455     (una fila por recibo de egreso)
 * EGRESOS       | 25.077.450 |          0 |            0
 * SUBTOTAL      |  6.760.450 | 673.804,70 | 1.902.907,01
 * DEPOSITOS BANCO MACRO CTA. 3100001…    0
 * SALDO FINAL   |  6.760.450 | 673.804,70 | 1.902.907,01
 * ```
 *
 * La cadena cierra los veinte días del mes: `SALDO FINAL` del día N es el
 * `SALDO INICIAL` del N+1, sin una sola excepción.
 *
 * ─── Dos desvíos respecto del DER ────────────────────────────────────────
 *
 * **1. Los movimientos van por columna, no en un total único.** El DER
 * define `total_received` y `total_disbursed` en singular, pero su propia
 * planilla tiene una fila `INGRESOS` y una `EGRESOS` **por cada uno de los
 * tres saldos**. Con un total único, la aritmética de las columnas
 * `CHEQUES` y `DEPOSITOS DIRECTOS` no cierra contra nada. En junio esas dos
 * no se movieron, pero la estructura existe y un cheque en custodia que se
 * deposita la usa.
 *
 * **2. Los saldos finales son columnas generadas.** El DER los lista como
 * datos. Son una resta: `inicial + ingresos − egresos − depositado`. Un
 * importe tipeado puede contradecir a sus propios sumandos; una columna
 * generada no. Es la aritmética de la planilla impuesta por la base.
 *
 * ─── Lo que sigue abierto ────────────────────────────────────────────────
 *
 * **`DEPOSITOS DIRECTOS` no es el saldo bancario.** La cifra 1.902.907,01
 * está congelada los veinte días de junio y **no aparece en ninguna de las
 * 18 hojas del libro banco** (hasta el 31/03/2026). La hipótesis es que sea
 * plata depositada directo en la cuenta y todavía no atribuida —lo que en
 * el plan de cuentas es `UNASSIGNED_FUNDS`, una cuenta de atribución— pero
 * es una hipótesis y hay que preguntarla. La columna se conserva con el
 * nombre del DER hasta que el área la defina.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_closings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_box_id')->constrained('cash_boxes')->restrictOnDelete();

            /** Un cierre por moneda: los totales de pesos y de dólares no se suman. */
            $table->char('currency', 3)->default('ARS');

            $table->string('period_type', 10);
            $table->date('period_from');
            $table->date('period_to');

            /*
             * Los tres saldos de apertura. La planilla arrastra tres
             * columnas y el cierre conserva esa estructura o no reproduce
             * el papel.
             */
            $table->decimal('opening_cash', 19, 2)->default(0);
            $table->decimal('opening_cheques', 19, 2)->default(0);
            $table->decimal('opening_bank_deposits', 19, 2)->default(0);

            $table->decimal('received_cash', 19, 2)->default(0);
            $table->decimal('received_cheques', 19, 2)->default(0);
            $table->decimal('received_bank_deposits', 19, 2)->default(0);

            $table->decimal('disbursed_cash', 19, 2)->default(0);
            $table->decimal('disbursed_cheques', 19, 2)->default(0);
            $table->decimal('disbursed_bank_deposits', 19, 2)->default(0);

            /*
             * La fila «DEPOSITOS BANCO MACRO CTA. 310000123456789»: lo que
             * salió de la caja hacia el banco ese día. El cheque en custodia
             * también se deposita, y por eso son dos y no una.
             */
            $table->decimal('deposited_to_bank_cash', 19, 2)->default(0);
            $table->decimal('deposited_to_bank_cheques', 19, 2)->default(0);

            /*
             * La cola de trabajo al cierre: plata recibida cuyo dueño
             * todavía no se determinó. No es un saldo de ubicación como los
             * tres de arriba —es de atribución— y por eso no entra en la
             * aritmética de las columnas.
             */
            $table->decimal('total_unassigned', 19, 2)->default(0);

            $table->string('status', 20)->default('draft');

            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();

            $table->string('notes', 1000)->nullable();
            $table->timestampsTz();

            $table->unique(['cash_box_id', 'period_type', 'period_from', 'period_to', 'currency']);
            $table->index(['cash_box_id', 'period_to']);
            $table->index(['status']);
        });

        /*
         * ─── La aritmética de la planilla, impuesta por la base ──────────
         *
         * `SALDO FINAL = SALDO INICIAL + INGRESOS − EGRESOS − DEPOSITADO`.
         * Verificado contra el 02/06/2026: 6.852.300 + 24.985.600 −
         * 25.077.450 − 0 = 6.760.450.
         */
        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_cash NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_cash + received_cash - disbursed_cash - deposited_to_bank_cash
            ) STORED');

        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_cheques NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_cheques + received_cheques - disbursed_cheques - deposited_to_bank_cheques
            ) STORED');

        DB::statement('ALTER TABLE period_closings
            ADD COLUMN closing_bank_deposits NUMERIC(19,2) GENERATED ALWAYS AS (
                opening_bank_deposits + received_bank_deposits - disbursed_bank_deposits
            ) STORED');

        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_currency_check
            CHECK (currency IN ('ARS', 'USD'))");
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_type_check
            CHECK (period_type IN ('daily', 'monthly'))");
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_status_check
            CHECK (status IN ('draft', 'closed', 'reopened'))");

        DB::statement('ALTER TABLE period_closings ADD CONSTRAINT period_closings_range_check
            CHECK (period_from <= period_to)');

        /** Un cierre diario cubre un día. Si cubre dos, no es diario. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_daily_check
            CHECK (period_type <> 'daily' OR period_from = period_to)");

        /** Un cierre mensual empieza el 1 y termina el último día del mes. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_monthly_check
            CHECK (
                period_type <> 'monthly'
                OR (period_from = date_trunc('month', period_from)::date
                    AND period_to = (date_trunc('month', period_from) + INTERVAL '1 month - 1 day')::date)
            )");

        /*
         * Invariante 4 del §9.9: *«Toda reapertura conserva motivo, usuario
         * y fecha»*. Los tres o ninguno.
         */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_reopen_check
            CHECK (
                (status = 'reopened')
                = (reopened_by IS NOT NULL AND reopened_at IS NOT NULL AND reopen_reason IS NOT NULL)
            )");

        /** Cerrar deja firma y hora, y el borrador todavía no las tiene. */
        DB::statement("ALTER TABLE period_closings ADD CONSTRAINT period_closings_closed_check
            CHECK (
                (status = 'draft')
                = (closed_by IS NULL AND closed_at IS NULL)
            )");

        $this->cierreCongelado();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS period_closings_frozen ON period_closings');
        DB::statement('DROP FUNCTION IF EXISTS period_closings_frozen()');

        Schema::dropIfExists('period_closings');
    }

    /**
     * Los totales se congelan como snapshot al cerrar.
     *
     * Regla 2 del §9.9. Un cierre cerrado que siguiera recalculándose
     * dejaría de ser un cierre: el papel que el área archiva y la fila de
     * la base tienen que decir lo mismo dentro de cinco años. La única
     * salida es la reapertura, que deja motivo y responsable.
     */
    private function cierreCongelado(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Un periodo cerrado no se borra: se reabre.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                -- Del cierre solo se sale reabriendo.
                IF NEW.status <> 'reopened' THEN
                    RAISE EXCEPTION
                        'El periodo % — % ya esta cerrado. Reabrilo con motivo antes de tocarlo.',
                        OLD.period_from, OLD.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.opening_cash IS DISTINCT FROM OLD.opening_cash
                    OR NEW.opening_cheques IS DISTINCT FROM OLD.opening_cheques
                    OR NEW.opening_bank_deposits IS DISTINCT FROM OLD.opening_bank_deposits
                    OR NEW.received_cash IS DISTINCT FROM OLD.received_cash
                    OR NEW.received_cheques IS DISTINCT FROM OLD.received_cheques
                    OR NEW.received_bank_deposits IS DISTINCT FROM OLD.received_bank_deposits
                    OR NEW.disbursed_cash IS DISTINCT FROM OLD.disbursed_cash
                    OR NEW.disbursed_cheques IS DISTINCT FROM OLD.disbursed_cheques
                    OR NEW.disbursed_bank_deposits IS DISTINCT FROM OLD.disbursed_bank_deposits
                    OR NEW.deposited_to_bank_cash IS DISTINCT FROM OLD.deposited_to_bank_cash
                    OR NEW.deposited_to_bank_cheques IS DISTINCT FROM OLD.deposited_to_bank_cheques
                THEN
                    RAISE EXCEPTION 'Reabrir un periodo no reescribe su snapshot: lo habilita a recalcularse.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER period_closings_frozen
            BEFORE UPDATE OR DELETE ON period_closings
            FOR EACH ROW EXECUTE FUNCTION period_closings_frozen()');
    }
};
