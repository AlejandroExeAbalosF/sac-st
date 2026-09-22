<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asignaciones de fondos — §9.4 del DER.
 *
 * **Acá el dinero deja de ser anónimo.** La recepción dijo que entró; esto
 * dice de quién es. Es el acto que financia una cuota y el que habilita,
 * más adelante, la Orden de Pago.
 *
 * **Vive en Haberes y no en Ledger, contra lo que dice el DER** (Correcciones
 * §26): referencia cuotas y haberes, y en Ledger haría fallar
 * `tests/Arch/ModuleBoundariesTest.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_allocations', function (Blueprint $table): void {
            $table->id();

            /** Uno a uno con el asiento que la registra. */
            $table->foreignId('allocation_event_id')->unique()
                ->constrained('financial_events')->restrictOnDelete();

            $table->foreignId('fund_receipt_id')->constrained('fund_receipts')->restrictOnDelete();

            $table->foreignId('haber_id')->constrained('haberes')->restrictOnDelete();
            $table->unsignedBigInteger('beneficiary_installment_id');

            $table->string('allocation_kind', 30)->default('allocation');
            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('funding_allocations')->restrictOnDelete();

            $table->decimal('amount', 19, 2);

            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('allocated_at')->useCurrent();

            /*
             * Por qué el sistema creyó que esta recepción era de esta
             * cuota. No es adorno: es lo que va a permitir responder,
             * dentro de unos meses, si las señales que hoy se usan para
             * proponer un cruce sirven de verdad.
             */
            $table->unsignedTinyInteger('match_score')->nullable();
            $table->jsonb('match_explanation')->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->index('beneficiary_installment_id');
            $table->index(['fund_receipt_id', 'allocation_kind']);
            $table->index('haber_id');
        });

        /*
         * La cuota tiene que ser del haber que se dice.
         *
         * Sin la foránea compuesta se podría asignar a la cuota de un
         * beneficiario poniendo el haber de otro, y todo lo que se
         * calcule por haber quedaría mal sin que nada proteste. El índice
         * único que la hace posible se creó junto con `deposit_tickets`.
         */
        DB::statement('ALTER TABLE funding_allocations
            ADD CONSTRAINT funding_allocations_installment_belongs_to_haber
            FOREIGN KEY (beneficiary_installment_id, haber_id)
            REFERENCES beneficiary_installments (id, haber_id)');

        DB::statement("ALTER TABLE funding_allocations ADD CONSTRAINT funding_allocations_kind_check
            CHECK (allocation_kind IN ('allocation', 'cash_rounding_surplus', 'reversal'))");
        DB::statement('ALTER TABLE funding_allocations ADD CONSTRAINT funding_allocations_amount_check
            CHECK (amount > 0)');
        DB::statement("ALTER TABLE funding_allocations ADD CONSTRAINT funding_allocations_reversal_check
            CHECK ((allocation_kind = 'reversal') = (reversal_of_id IS NOT NULL))");

        /*
         * ─── No se asigna más de lo que entró ─────────────────────────
         *
         * Invariante 2. Una recepción de $72.000 no puede financiar
         * $80.000 en cuotas: sería inventar dinero que el banco nunca
         * informó.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION allocation_within_receipt() RETURNS trigger AS $$
            DECLARE
                importe_recibido NUMERIC(19,2);
                total_asignado NUMERIC(19,2);
            BEGIN
                SELECT amount INTO importe_recibido
                FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                SELECT COALESCE(SUM(
                    CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END
                ), 0)
                INTO total_asignado
                FROM funding_allocations
                WHERE fund_receipt_id = NEW.fund_receipt_id;

                IF total_asignado > importe_recibido THEN
                    RAISE EXCEPTION
                        'La recepcion es de % y ya tiene % asignados.',
                        importe_recibido, total_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER allocation_within_receipt
            AFTER INSERT OR UPDATE ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION allocation_within_receipt()');

        /*
         * ─── No se financia una cuota de más ──────────────────────────
         *
         * Invariante 2, del otro lado. El importe esperado es el derecho
         * tal como lo define el expediente, y financiarlo de más sería
         * pagarle al beneficiario algo que no se le reconoció.
         *
         * `cash_rounding_surplus` queda fuera del tope: es la única
         * excepción admitida (§2.4), y existe porque cuando el empleador
         * redondea hacia arriba en efectivo se entrega todo lo recibido.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION allocation_within_installment() RETURNS trigger AS $$
            DECLARE
                importe_esperado NUMERIC(19,2);
                total_asignado NUMERIC(19,2);
            BEGIN
                SELECT expected_amount INTO importe_esperado
                FROM beneficiary_installments WHERE id = NEW.beneficiary_installment_id;

                SELECT COALESCE(SUM(
                    CASE WHEN allocation_kind = 'reversal' THEN -amount ELSE amount END
                ), 0)
                INTO total_asignado
                FROM funding_allocations
                WHERE beneficiary_installment_id = NEW.beneficiary_installment_id
                  AND allocation_kind <> 'cash_rounding_surplus';

                IF total_asignado > importe_esperado THEN
                    RAISE EXCEPTION
                        'La cuota espera % y ya tiene % asignados.',
                        importe_esperado, total_asignado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER allocation_within_installment
            AFTER INSERT OR UPDATE ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION allocation_within_installment()');

        /*
         * ─── Una cuota se financia con un solo medio ──────────────────
         *
         * Invariante 4. La primera recepción lo fija y las siguientes
         * tienen que coincidir.
         *
         * No es una regla contable sino documental: el recibo de ingreso
         * imprime **un** medio, en singular. Una cuota financiada mitad en
         * efectivo y mitad por transferencia haría que el comprobante
         * mienta, y ese papel es el que después respalda la Orden de Pago.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION installment_has_single_medium() RETURNS trigger AS $$
            DECLARE
                medio_nuevo TEXT;
                medio_previo TEXT;
            BEGIN
                SELECT medium INTO medio_nuevo
                FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                SELECT r.medium INTO medio_previo
                FROM funding_allocations a
                JOIN fund_receipts r ON r.id = a.fund_receipt_id
                WHERE a.beneficiary_installment_id = NEW.beneficiary_installment_id
                  AND a.id <> NEW.id
                  AND a.allocation_kind <> 'reversal'
                LIMIT 1;

                IF medio_previo IS NOT NULL AND medio_previo IS DISTINCT FROM medio_nuevo THEN
                    RAISE EXCEPTION
                        'La cuota ya se financia por % y esta recepcion es por %.',
                        medio_previo, medio_nuevo
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER installment_has_single_medium
            AFTER INSERT ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION installment_has_single_medium()');

        /*
         * Append-only: una asignación equivocada —la plata era de otro
         * beneficiario— se revierte, no se edita. Que alguien lo haya
         * creído es parte de lo que la auditoría tiene que poder ver.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION funding_allocations_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Una asignacion no se borra: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.allocation_event_id IS DISTINCT FROM OLD.allocation_event_id
                    OR NEW.fund_receipt_id IS DISTINCT FROM OLD.fund_receipt_id
                    OR NEW.haber_id IS DISTINCT FROM OLD.haber_id
                    OR NEW.beneficiary_installment_id IS DISTINCT FROM OLD.beneficiary_installment_id
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.allocation_kind IS DISTINCT FROM OLD.allocation_kind
                    OR NEW.reversal_of_id IS DISTINCT FROM OLD.reversal_of_id
                THEN
                    RAISE EXCEPTION 'Una asignacion no se edita: se revierte.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER funding_allocations_append_only
            BEFORE UPDATE OR DELETE ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION funding_allocations_append_only()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION funding_allocations_same_currency() RETURNS trigger AS $$
            DECLARE
                moneda_recepcion CHAR(3);
                moneda_haber CHAR(3);
            BEGIN
                SELECT currency INTO moneda_recepcion
                  FROM fund_receipts WHERE id = NEW.fund_receipt_id;

                SELECT currency INTO moneda_haber
                  FROM haberes WHERE id = NEW.haber_id;

                IF moneda_recepcion IS DISTINCT FROM moneda_haber THEN
                    RAISE EXCEPTION
                        'No se puede imputar una recepcion en % a un haber en %.',
                        moneda_recepcion, moneda_haber
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER funding_allocations_currency
            BEFORE INSERT OR UPDATE ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION funding_allocations_same_currency()');

        /*
         * Lo revertido no se vuelve a repartir: una imputacion desde una
         * recepcion revertida seria dinero que el libro ya dijo que no entro.
         */
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION funding_allocations_receipt_live() RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM fund_receipts
                    WHERE id = NEW.fund_receipt_id
                      AND reversal_event_id IS NOT NULL
                ) THEN
                    RAISE EXCEPTION 'Esa recepcion esta revertida: su dinero no se puede imputar.'
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$;
        SQL);

        DB::statement('CREATE TRIGGER funding_allocations_receipt_live
            BEFORE INSERT ON funding_allocations
            FOR EACH ROW EXECUTE FUNCTION funding_allocations_receipt_live()');
    }

    public function down(): void
    {
        foreach ([
            'funding_allocations_append_only',
            'installment_has_single_medium',
            'allocation_within_installment',
            'allocation_within_receipt',
        ] as $trigger) {
            DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON funding_allocations");
            DB::statement("DROP FUNCTION IF EXISTS {$trigger}()");
        }

        Schema::dropIfExists('funding_allocations');
    }
};
