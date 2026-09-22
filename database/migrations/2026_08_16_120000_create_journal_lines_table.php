<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas del libro diario — §9.4 del DER.
 *
 * La doble partida. Cada evento se descompone en líneas que se compensan,
 * y de la suma de todas ellas salen todos los saldos del sistema: cuánto
 * hay en caja, cuánto en el banco, cuánto sin identificar, cuánto tiene
 * asignado cada beneficiario.
 *
 * **Ningún saldo se guarda**: todos se calculan sumando estas líneas. Un
 * contador editable se desincroniza y nadie se entera hasta que el arqueo
 * no cierra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('financial_event_id')->constrained('financial_events')->restrictOnDelete();

            $table->string('account_code', 30);

            /*
             * Débito y crédito en columnas separadas, no un importe con
             * signo. Es como se lee un asiento y como se totaliza: la
             * comprobación de balance es `SUM(debit) = SUM(credit)`, sin
             * interpretar signos.
             */
            $table->decimal('debit', 19, 2)->default(0);
            $table->decimal('credit', 19, 2)->default(0);

            /*
             * Las dimensiones del saldo. Nulas según la cuenta: una línea
             * de `BANK_ACCOUNT` lleva cuenta bancaria y no caja; una de
             * `BENEFICIARY_FUNDS` lleva persona y cuota.
             *
             * `beneficiary_installment_id` y `haber_id` son punteros
             * sueltos, **sin FK**: Ledger no puede depender de Haberes
             * —lo verifica el arch test— y una foránea sería exactamente
             * esa dependencia. La integridad la garantiza el Action que
             * escribe la línea.
             *
             * `bank_account_id` **sí lleva FK**, y la asimetría es
             * deliberada. `bank_accounts` no es dominio de Banking: es el
             * maestro institucional de «qué cuentas tiene el organismo»,
             * el análogo bancario de `cash_boxes`. Aranceles y Multas van
             * a usar esas mismas cuentas, así que la referencia no impide
             * reutilizar el motor. Lo que Ledger no hace es *conocer el
             * modelo* de Banking: no hay `use` ni relación Eloquent hacia
             * `BankAccount`, y el arch test lo sigue verificando. Un
             * expediente, en cambio, no existe fuera de Haberes — de ahí
             * que ese puntero vaya suelto.
             */
            $table->foreignId('cash_box_id')->nullable()->constrained('cash_boxes')->restrictOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $table->foreignId('depositor_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->unsignedBigInteger('haber_id')->nullable();
            $table->unsignedBigInteger('beneficiary_installment_id')->nullable();

            $table->string('description', 300)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['financial_event_id']);
            $table->index(['account_code', 'created_at']);
            $table->index(['bank_account_id', 'account_code']);
            $table->index('beneficiary_installment_id');
        });

        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_account_check
            CHECK (account_code IN (
                'CASH_ON_HAND', 'CHEQUES_IN_CUSTODY', 'CASH_IN_TRANSIT', 'BANK_ACCOUNT',
                'UNASSIGNED_FUNDS', 'BENEFICIARY_FUNDS', 'LEGACY_FUNDS', 'CASH_DIFFERENCE'
            ))");
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_amounts_check
            CHECK (debit >= 0 AND credit >= 0)');
        /*
         * Una línea es débito o es crédito, nunca las dos ni ninguna. Sin
         * esto se podría escribir una línea de ceros que no dice nada, o
         * una con ambos importes que nadie sabría leer.
         */
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_side_check
            CHECK ((debit > 0) <> (credit > 0))');

        /*
         * ─── El asiento tiene que balancear ───────────────────────────
         *
         * Invariante 14: *«Todo evento posteado balancea débitos y
         * créditos»*.
         *
         * Va como `CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED`, y
         * el diferido no es un detalle de implementación: **es lo único
         * que lo hace posible**. Las líneas se insertan de a una, así que
         * después de la primera el asiento está necesariamente
         * desbalanceado. Un trigger inmediato rechazaría todos los
         * asientos del sistema.
         *
         * Diferido, la comprobación corre al confirmar la transacción,
         * cuando el asiento ya está completo. Si no cierra, no entra nada.
         *
         * Solo se exige sobre eventos posteados: un borrador puede estar a
         * medio armar, que es para lo que sirve un borrador.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
            DECLARE
                evento_id BIGINT;
                total_debito NUMERIC(19,2);
                total_credito NUMERIC(19,2);
                estado TEXT;
            BEGIN
                evento_id := COALESCE(NEW.financial_event_id, OLD.financial_event_id);

                SELECT status INTO estado FROM financial_events WHERE id = evento_id;

                -- El evento pudo borrarse en esta misma transaccion, o
                -- seguir en borrador: en ninguno de los dos casos hay nada
                -- que exigir.
                IF estado IS NULL OR estado = 'draft' THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                INTO total_debito, total_credito
                FROM journal_lines
                WHERE financial_event_id = evento_id;

                IF total_debito <> total_credito THEN
                    RAISE EXCEPTION
                        'El asiento del evento % no balancea: debitos %, creditos %.',
                        evento_id, total_debito, total_credito
                        USING ERRCODE = 'check_violation';
                END IF;

                IF total_debito = 0 THEN
                    RAISE EXCEPTION 'El evento % quedo posteado sin asiento.', evento_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER journal_lines_balance
            AFTER INSERT OR UPDATE OR DELETE ON journal_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION journal_entry_must_balance()');

        /*
         * Y el mismo control cuando lo que cambia es el evento: postear un
         * borrador sin asiento, o con uno que no cierra, tiene que fallar
         * igual.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION posted_event_must_balance() RETURNS trigger AS $$
            DECLARE
                total_debito NUMERIC(19,2);
                total_credito NUMERIC(19,2);
            BEGIN
                IF NEW.status <> 'posted' THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(debit), 0), COALESCE(SUM(credit), 0)
                INTO total_debito, total_credito
                FROM journal_lines
                WHERE financial_event_id = NEW.id;

                IF total_debito = 0 THEN
                    RAISE EXCEPTION 'No se puede postear el evento % sin asiento.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                IF total_debito <> total_credito THEN
                    RAISE EXCEPTION
                        'El asiento del evento % no balancea: debitos %, creditos %.',
                        NEW.id, total_debito, total_credito
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE CONSTRAINT TRIGGER financial_events_balance
            AFTER INSERT OR UPDATE ON financial_events
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION posted_event_must_balance()');

        /*
         * Las líneas no se editan ni se borran. Corregir un asiento es
         * revertirlo con otro, que es como funciona un libro contable
         * desde que existen los libros contables.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Una linea del diario no se edita ni se borra: se revierte el asiento.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER journal_lines_append_only
            BEFORE UPDATE OR DELETE ON journal_lines
            FOR EACH ROW EXECUTE FUNCTION journal_lines_append_only()');

        /*
         * La moneda de la linea. El saldo de caja se pregunta siempre igual
         * --que hay en esta cuenta, de esta caja, en esta moneda-- y el
         * indice sigue esa forma.
         */
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->char('currency', 3)->default('ARS');
            $table->index(['cash_box_id', 'account_code', 'currency']);
        });

        DB::statement("ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_currency_check
            CHECK (currency IN ('ARS', 'USD'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entry_must_balance() RETURNS trigger AS $$
            DECLARE
                evento_id BIGINT;
                estado TEXT;
                lineas INT;
                desbalance RECORD;
            BEGIN
                evento_id := COALESCE(NEW.financial_event_id, OLD.financial_event_id);

                SELECT status INTO estado FROM financial_events WHERE id = evento_id;

                IF estado IS NULL OR estado = 'draft' THEN
                    RETURN NULL;
                END IF;

                SELECT COUNT(*) INTO lineas
                  FROM journal_lines WHERE financial_event_id = evento_id;

                IF lineas = 0 THEN
                    RAISE EXCEPTION 'El evento % quedo posteado sin asiento.', evento_id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT currency, SUM(debit) AS d, SUM(credit) AS c
                  INTO desbalance
                  FROM journal_lines
                 WHERE financial_event_id = evento_id
                 GROUP BY currency
                HAVING SUM(debit) <> SUM(credit)
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El asiento del evento % no balancea en %: debitos %, creditos %.',
                        evento_id, desbalance.currency, desbalance.d, desbalance.c
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION posted_event_must_balance() RETURNS trigger AS $$
            DECLARE
                lineas INT;
                desbalance RECORD;
            BEGIN
                IF NEW.status <> 'posted' THEN
                    RETURN NULL;
                END IF;

                SELECT COUNT(*) INTO lineas
                  FROM journal_lines WHERE financial_event_id = NEW.id;

                IF lineas = 0 THEN
                    RAISE EXCEPTION 'No se puede postear el evento % sin asiento.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT currency, SUM(debit) AS d, SUM(credit) AS c
                  INTO desbalance
                  FROM journal_lines
                 WHERE financial_event_id = NEW.id
                 GROUP BY currency
                HAVING SUM(debit) <> SUM(credit)
                 LIMIT 1;

                IF FOUND THEN
                    RAISE EXCEPTION
                        'El asiento del evento % no balancea en %: debitos %, creditos %.',
                        NEW.id, desbalance.currency, desbalance.d, desbalance.c
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_bank_currency() RETURNS trigger AS $$
            DECLARE
                moneda_cuenta CHAR(3);
            BEGIN
                IF NEW.bank_account_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT currency INTO moneda_cuenta
                  FROM bank_accounts WHERE id = NEW.bank_account_id;

                IF moneda_cuenta IS DISTINCT FROM NEW.currency THEN
                    RAISE EXCEPTION
                        'La linea esta en % y la cuenta bancaria % opera en %.',
                        NEW.currency, NEW.bank_account_id, moneda_cuenta
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER journal_lines_bank_currency
            BEFORE INSERT OR UPDATE ON journal_lines
            FOR EACH ROW EXECUTE FUNCTION journal_lines_bank_currency()');

    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balance ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS financial_events_balance ON financial_events');
        DB::statement('DROP FUNCTION IF EXISTS journal_lines_append_only()');
        DB::statement('DROP FUNCTION IF EXISTS journal_entry_must_balance()');
        DB::statement('DROP FUNCTION IF EXISTS posted_event_must_balance()');

        Schema::dropIfExists('journal_lines');
    }
};
