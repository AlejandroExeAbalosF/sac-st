<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El invariante 30, entero y en la base.
 *
 * *«No puede cerrarse un período con eventos en `draft`, importaciones en
 * curso o arqueos sin resolver»* — §9.9 regla 5.
 *
 * `ClosePeriod` ya comprobaba dos de las tres y daba el mensaje legible,
 * pero **la regla vivía solo en PHP**, contra la primera regla del
 * proyecto: los invariantes viven en la base. Un Action se puede saltear —
 * un job en cola, un seeder, una corrección a mano—; el trigger no.
 *
 * ─── Y resuelve una frontera que en PHP no tiene salida ──────────────────
 *
 * La condición que faltaba es la de las importaciones, y no se podía
 * escribir en `ClosePeriod`: `bank_statement_imports` es de **Banking**, y
 * Ledger no puede depender de Banking —lo verifica `ModuleBoundariesTest`
 * y falla en CI—.
 *
 * En la base no hay módulos. Es el mismo argumento que el proyecto ya
 * escribió para el invariante 13, donde un trigger sobre `receipts`
 * —de Shared— mira `disbursements` —de Haberes—: *«la base de datos es un
 * solo esquema y las migraciones son cronológicas, no modulares… el trigger
 * no crea una dependencia de código»*.
 *
 * ─── Sobre la condición de las importaciones ─────────────────────────────
 *
 * **Hoy no puede dispararse, y conviene decirlo.** `ImportBankStatement`
 * crea la fila en `parsing` y la deja en `completed` dentro de la misma
 * transacción: o commitea entera o la fila nunca existió. `uploaded` está
 * declarado en el enum y no lo asigna nadie.
 *
 * Se escribe igual porque el invariante la nombra y porque el día que el
 * parseo de un archivo grande pase a una cola —la evolución natural— el
 * estado intermedio va a persistir de verdad. Cuesta una consulta por
 * cierre y evita tener que acordarse entonces.
 *
 * No se filtra por caja: `bank_statement_imports` cuelga de la cuenta
 * bancaria, que es el maestro institucional y no pertenece a ninguna.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_ready_to_close() RETURNS trigger AS $$
            DECLARE
                pendientes INT;
            BEGIN
                IF NEW.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                /*
                 * Reabrir y volver a cerrar pasa por acá igual, y tiene que
                 * pasar: entre un cierre y el siguiente pudo aparecer un
                 * borrador que antes no estaba.
                 */

                -- 1 · Eventos en borrador
                SELECT count(*) INTO pendientes
                  FROM financial_events
                 WHERE cash_box_id = NEW.cash_box_id
                   AND status = 'draft'
                   AND event_date BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % asiento(s) en borrador adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 2 · Arqueos sin resolver
                SELECT count(*) INTO pendientes
                  FROM cash_counts
                 WHERE cash_box_id = NEW.cash_box_id
                   AND currency = NEW.currency
                   AND status = 'draft'
                   AND counted_on BETWEEN NEW.period_from AND NEW.period_to;

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo con % arqueo(s) sin resolver adentro.', pendientes
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- 3 · Extractos a medio importar que tocan el periodo
                SELECT count(*) INTO pendientes
                  FROM bank_statement_imports
                 WHERE status IN ('uploaded', 'parsing')
                   AND (
                        period_from IS NULL
                        OR (period_from <= NEW.period_to AND period_to >= NEW.period_from)
                   );

                IF pendientes > 0 THEN
                    RAISE EXCEPTION
                        'No se cierra un periodo mientras se importa un extracto con movimientos suyos.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('CREATE TRIGGER period_closings_ready_to_close
            BEFORE INSERT OR UPDATE ON period_closings
            FOR EACH ROW EXECUTE FUNCTION period_closings_ready_to_close()');
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS period_closings_ready_to_close ON period_closings');
        DB::statement('DROP FUNCTION IF EXISTS period_closings_ready_to_close()');
    }
};
