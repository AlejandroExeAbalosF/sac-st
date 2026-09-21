<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La moneda entra al sublibro — §4.4 del DER, corrección 23.
 *
 * Hasta acá todos los importes eran `numeric(19,2)` **sin moneda**, y solo
 * `bank_accounts` sabía en qué estaba denominada. El área confirmó que ya
 * hay efectivo en dólares en el cajón, así que el sistema estaba sumando
 * pesos con dólares sin advertirlo: en el saldo de caja, en el arqueo y en
 * el cálculo de «¿está financiada la cuota?».
 *
 * **Se adelanta acá y no cuando aparezca el arqueo**, porque el arqueo es
 * justamente donde explota —se cuentan billetes de dos monedas y se los
 * suma en una sola columna— y retrofitear la moneda sobre un sublibro con
 * arqueos ya cerrados obligaría a reinterpretar importes históricos. La
 * columna nace con `default 'ARS'`: todo lo registrado hasta hoy son
 * pesos, y eso es un hecho, no un supuesto.
 *
 * **No hay conversión, ni cotización, ni cuenta de diferencia de cambio.**
 * El área confirmó que se recibe y se paga en la misma moneda: las dos
 * conviven sin mezclarse nunca. Eso convierte un problema de cambio de
 * divisas en uno de segregación, que se resuelve con tres invariantes:
 *
 * 1. El asiento balancea **por moneda**, no en total.
 * 2. Una línea imputada a una cuenta bancaria lleva la moneda de esa cuenta.
 * 3. Una asignación vincula recepción y haber de la misma moneda.
 *
 * > Es una política nueva, no una ley de la naturaleza. Si más adelante se
 * > admite recibir en una moneda y pagar en otra, el modelo no se rompe,
 * > pero los `CHECK` van a frenar el intento en vez de mezclar los
 * > importes en silencio — que es exactamente para lo que están.
 */
return new class extends Migration
{
    /** Las dos monedas que el organismo maneja hoy. */
    private const MONEDAS = "('ARS', 'USD')";

    public function up(): void
    {
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->char('currency', 3)->default('ARS')->after('account_code');
        });

        Schema::table('fund_receipts', function (Blueprint $table): void {
            $table->char('currency', 3)->default('ARS')->after('medium');
        });

        /*
         * La moneda del derecho, no de la cuota.
         *
         * Un haber es de un beneficiario y está denominado en una moneda;
         * sus cuotas son fracciones de ese mismo derecho y la heredan. Una
         * columna por cuota permitiría un haber en pesos con una cuota en
         * dólares, que no significa nada.
         */
        Schema::table('haberes', function (Blueprint $table): void {
            $table->char('currency', 3)->default('ARS')->after('assigned_amount');
        });

        foreach (['journal_lines', 'fund_receipts', 'haberes'] as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT {$tabla}_currency_check
                CHECK (currency IN ".self::MONEDAS.')');
        }

        /*
         * El saldo de caja se pregunta siempre igual: qué hay en esta
         * cuenta, de esta caja, en esta moneda. El índice sigue esa forma.
         */
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->index(['cash_box_id', 'account_code', 'currency']);
        });

        $this->balanceePorMoneda();
        $this->lineaBancariaRespetaLaMonedaDeLaCuenta();
        $this->asignacionDeLaMismaMoneda();
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS funding_allocations_currency ON funding_allocations');
        DB::statement('DROP FUNCTION IF EXISTS funding_allocations_same_currency()');

        DB::statement('DROP TRIGGER IF EXISTS journal_lines_bank_currency ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS journal_lines_bank_currency()');

        $this->balanceeSinMoneda();

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex(['cash_box_id', 'account_code', 'currency']);
        });

        foreach (['journal_lines', 'fund_receipts', 'haberes'] as $tabla) {
            DB::statement("ALTER TABLE {$tabla} DROP CONSTRAINT IF EXISTS {$tabla}_currency_check");

            Schema::table($tabla, function (Blueprint $table): void {
                $table->dropColumn('currency');
            });
        }
    }

    /**
     * Invariante 1 — el asiento balancea por moneda.
     *
     * Las dos funciones ya existen y los triggers las apuntan; se
     * redefinen en su lugar. Sin esto, un asiento con un débito de 100
     * dólares y un crédito de 100 pesos pasaría el control de balance:
     * `SUM(debit) = SUM(credit)` es cierto y el asiento es un disparate.
     */
    private function balanceePorMoneda(): void
    {
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
    }

    /**
     * Invariante 2 — una línea bancaria lleva la moneda de su cuenta.
     *
     * `bank_accounts.currency` ya se congela apenas la cuenta tiene
     * movimientos. Esto cierra el otro extremo: acreditar dólares en una
     * cuenta en pesos deja de ser posible, que es la mitad de «un traslado
     * de efectivo solo va a una cuenta de la misma moneda».
     */
    private function lineaBancariaRespetaLaMonedaDeLaCuenta(): void
    {
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

    /**
     * Invariante 3 — la asignación no cruza monedas.
     *
     * Es la otra mitad: imputar una recepción en dólares a un haber en
     * pesos daría por financiada una cuota con plata que no la financia.
     * `funding_allocations` tiene FK real hacia las dos puntas, así que el
     * control vive donde debe.
     */
    private function asignacionDeLaMismaMoneda(): void
    {
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
    }

    /** Las dos funciones de balance como estaban antes de la moneda. */
    private function balanceeSinMoneda(): void
    {
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
    }
};
