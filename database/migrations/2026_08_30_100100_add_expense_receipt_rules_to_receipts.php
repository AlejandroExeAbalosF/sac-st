<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las dos reglas del recibo de egreso — invariantes 13 y 24 del §11.
 *
 * `receipts` nació con la mitad del trabajo hecho: el tipo `expense` ya
 * estaba en el `CHECK` y la serie `0020` ya estaba sembrada. Lo que
 * faltaba es lo que distingue a este comprobante del de ingreso, y no es
 * el formato del papel.
 *
 * **El recibo de ingreso se emite al recibir y no espera nada** (§2.5.4):
 * el empleador deja el dinero en el mostrador y se lleva su papel. **El de
 * egreso es al revés**: solo puede emitirse después de que el egreso esté
 * confirmado (invariante 13), porque documenta que el beneficiario cobró,
 * y eso no es una promesa sino un hecho.
 *
 * ── Por qué el trigger mira una tabla de otro módulo ───────────────────
 *
 * `receipts` vive en `Shared` y no puede depender de `Haberes`: por eso
 * `beneficiary_installment_id` no lleva foránea. Esa regla es de la
 * **dependencia entre módulos de PHP**, y la verifica un arch test sobre
 * los namespaces.
 *
 * La base de datos es un solo esquema y las migraciones son cronológicas,
 * no modulares. El invariante 13 tiene que vivir acá —regla 1 del
 * proyecto: *los invariantes viven en la base*— porque es exactamente el
 * error que un Action distraído podría cometer: emitir el papel antes de
 * que el dinero salga. El trigger no crea una dependencia de código; el
 * Action de `Haberes` sigue siendo el único que sabe qué significa.
 *
 * **Y no alcanza a lo que no es de Haberes.** El día que Aranceles emita
 * sus recibos de egreso, esos no van a tener cuota y el trigger los deja
 * pasar: la guarda es sobre `beneficiary_installment_id IS NOT NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ─── Un solo recibo de egreso vigente por cuota ──────────────
         *
         * Espejo exacto de `receipts_one_income_per_installment`, y por la
         * misma razón: dos papeles vigentes por la misma cuota serían dos
         * constancias de que el beneficiario cobró lo mismo dos veces.
         *
         * La cuota se entrega completa y una sola vez —lo confirmó el
         * área—, así que no hay caso legítimo de dos recibos vivos. Anular
         * y reemplazar sí lo hay, y por eso el índice es parcial sobre los
         * emitidos.
         */
        DB::statement("CREATE UNIQUE INDEX receipts_one_expense_per_installment
            ON receipts (beneficiary_installment_id)
            WHERE receipt_type = 'expense'
              AND status = 'issued'
              AND beneficiary_installment_id IS NOT NULL");

        /*
         * ─── Invariante 13: el recibo de egreso exige egreso confirmado ─
         *
         * Es la regla que ordena todo el circuito de salida. En la
         * transferencia significa que el organismo informó, que el débito
         * apareció en el extracto y que el contador validó la
         * correspondencia (§2.3.5). En el mostrador, que el beneficiario
         * se llevó el dinero.
         *
         * Se comprueba también al anular y reemplazar: el reemplazante
         * documenta el mismo hecho, así que necesita el mismo respaldo.
         */
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION receipts_expense_needs_confirmed_disbursement()
            RETURNS trigger AS $$
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
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER receipts_expense_needs_confirmed_disbursement
            BEFORE INSERT OR UPDATE ON receipts
            FOR EACH ROW EXECUTE FUNCTION receipts_expense_needs_confirmed_disbursement()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS receipts_expense_needs_confirmed_disbursement ON receipts');
        DB::statement('DROP FUNCTION IF EXISTS receipts_expense_needs_confirmed_disbursement()');
        DB::statement('DROP INDEX IF EXISTS receipts_one_expense_per_installment');
    }
};
