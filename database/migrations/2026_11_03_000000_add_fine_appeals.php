<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El recurso del 20 % como trámite propio (decisión del área, 03/11/2026).
 *
 * El pago sigue siendo la condición (M07): tipo, base y 20 % de referencia.
 * El trámite es lo que pasa con la multa mientras el recurso se resuelve:
 *
 * - **en trámite** (`open`): la multa queda congelada en lo económico. No
 *   admite otros pagos, antecedentes ni movimientos de importe; solo sigue
 *   el pago del propio recurso.
 * - **prosperó** (`upheld`): la multa queda cancelada con lo cobrado. Si eso
 *   es menos que la obligación, una reducción citando el recurso la lleva a
 *   lo cobrado. Después no admite movimientos de importe.
 * - **no prosperó** (`rejected`): se descongela y lo pagado queda a cuenta.
 * - **cayó** (`dropped`): su depósito informado se descartó mientras estaba
 *   en trámite; la multa admite otro recurso.
 *
 * Solo se recurre una multa con deuda y sin pagos ni antecedentes vivos.
 * Cada guarda bloquea la fila de la multa: dos operaciones sobre la misma
 * multa se serializan y la segunda ve lo que confirmó la primera.
 */
return new class extends Migration
{
    private const GUARDAS = <<<'SQL'
            CREATE OR REPLACE FUNCTION fine_appeal_status(p_fine_id bigint) RETURNS varchar AS $$
                SELECT status FROM fine_appeals WHERE fine_id = p_fine_id AND status <> 'dropped';
            $$ LANGUAGE sql STABLE;

            /*
             * Nace en trámite, sobre una multa con deuda y sin pagos ni
             * antecedentes vivos. Se resuelve una vez: los estados finales no
             * cambian, y no se borra.
             */
            CREATE OR REPLACE FUNCTION fine_appeals_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un recurso no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                    IF NEW.status <> 'open' THEN
                        RAISE EXCEPTION 'Un recurso nace en trámite.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <= 0 THEN
                        RAISE EXCEPTION 'La multa no tiene saldo pendiente: no hay recurso que pagar.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF EXISTS (SELECT 1 FROM fine_payments WHERE fine_id = NEW.fine_id AND status <> 'discarded')
                       OR EXISTS (SELECT 1 FROM fine_historical_movements WHERE fine_id = NEW.fine_id AND status <> 'rejected') THEN
                        RAISE EXCEPTION 'La multa ya tiene pagos: el recurso se registra antes de cualquier pago.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF OLD.status <> 'open' THEN
                    RAISE EXCEPTION 'El recurso ya se resolvió: queda como está.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status = 'open'
                   OR (to_jsonb(NEW) - ARRAY['status', 'resolved_at', 'resolved_by', 'updated_at'])
                      IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'resolved_at', 'resolved_by', 'updated_at']) THEN
                    RAISE EXCEPTION 'Un recurso en trámite solo cambia al resolverse o al caer.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_appeals_guard BEFORE INSERT OR UPDATE OR DELETE ON fine_appeals
                FOR EACH ROW EXECUTE FUNCTION fine_appeals_guard();

            /*
             * Con el recurso en trámite, la multa no admite otro pago; el del
             * recurso solo entra a un trámite abierto, y una corrección queda
             * en el mismo recurso que el pago que reemplaza.
             */
            CREATE OR REPLACE FUNCTION fine_payments_follow_appeal() RETURNS trigger AS $$
            DECLARE
                v_reemplazado bigint;
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                IF NEW.fine_appeal_id IS NULL THEN
                    IF fine_appeal_status(NEW.fine_id) = 'open' THEN
                        RAISE EXCEPTION 'La multa está en recurso: hasta resolverlo no admite otros pagos.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                ELSIF (SELECT status FROM fine_appeals WHERE id = NEW.fine_appeal_id) IS DISTINCT FROM 'open' THEN
                    RAISE EXCEPTION 'El pago de un recurso entra solo a un recurso en trámite.'
                        USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                    SELECT fine_appeal_id INTO v_reemplazado FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;

                    IF v_reemplazado IS DISTINCT FROM NEW.fine_appeal_id THEN
                        RAISE EXCEPTION 'Una corrección queda en el mismo recurso que el pago que reemplaza.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payments_follow_appeal BEFORE INSERT ON fine_payments
                FOR EACH ROW EXECUTE FUNCTION fine_payments_follow_appeal();

            -- Un antecedente es un pago: no entra con el recurso en trámite ni después de que prosperó.
            CREATE OR REPLACE FUNCTION fine_historical_follow_appeal() RETURNS trigger AS $$
            BEGIN
                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;

                IF fine_appeal_status(NEW.fine_id) IN ('open', 'upheld') THEN
                    RAISE EXCEPTION 'La multa está en recurso o lo ganó: no admite antecedentes.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_historical_follow_appeal BEFORE INSERT ON fine_historical_movements
                FOR EACH ROW EXECUTE FUNCTION fine_historical_follow_appeal();

            /*
             * El importe no se mueve con el recurso en trámite, ni después de
             * que prosperó: un ajuste no deshace el veredicto. La única
             * excepción es la reducción del veredicto, que lleva la
             * obligación exactamente a lo cobrado y no se revierte.
             */
            CREATE OR REPLACE FUNCTION fine_debt_entries_follow_appeal() RETURNS trigger AS $$
            DECLARE
                v_estado varchar;
                v_pagado numeric(19,2);
            BEGIN
                IF NEW.kind = 'constitution' THEN
                    RETURN NEW;
                END IF;

                PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                v_estado := fine_appeal_status(NEW.fine_id);

                IF NEW.fine_appeal_id IS NULL THEN
                    IF v_estado = 'open' THEN
                        RAISE EXCEPTION 'La multa está en recurso: hasta resolverlo no admite cambios de importe.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_estado = 'upheld' THEN
                        RAISE EXCEPTION 'El recurso de la multa prosperó: su importe ya no se modifica.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF NEW.kind = 'reversal' AND EXISTS (
                        SELECT 1 FROM fine_debt_entries WHERE id = NEW.reversal_of_id AND fine_appeal_id IS NOT NULL
                    ) THEN
                        RAISE EXCEPTION 'El veredicto del recurso no se revierte.' USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                IF (SELECT status FROM fine_appeals WHERE id = NEW.fine_appeal_id) IS DISTINCT FROM 'upheld' THEN
                    RAISE EXCEPTION 'Solo un recurso que prosperó reduce la multa.' USING ERRCODE = 'check_violation';
                END IF;

                SELECT operational_paid + historical_paid INTO v_pagado FROM fine_balances WHERE fine_id = NEW.fine_id;

                IF fine_obligation(NEW.fine_id) - NEW.amount <> v_pagado THEN
                    RAISE EXCEPTION 'El recurso que prospera lleva la multa a lo cobrado: % y no %.',
                        v_pagado, fine_obligation(NEW.fine_id) - NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_debt_entries_follow_appeal BEFORE INSERT ON fine_debt_entries
                FOR EACH ROW EXECUTE FUNCTION fine_debt_entries_follow_appeal();

            /*
             * Lo que el trámite exige de sus pagos, al confirmar: una
             * corrección descarta y crea en la misma transacción, así que
             * recién al final se sabe si el recurso quedó con su pago.
             *
             * - en trámite: exactamente un pago vivo;
             * - cayó: ninguno;
             * - no prosperó: a lo sumo uno, porque su depósito puede
             *   descartarse después y el veredicto se conserva;
             * - prosperó: su pago aplicado y la multa sin deuda.
             */
            CREATE OR REPLACE FUNCTION fine_appeal_consistent() RETURNS trigger AS $$
            DECLARE
                v_recurso record;
                v_vivos integer;
                v_aplicados integer;
            BEGIN
                FOR v_recurso IN SELECT id, status FROM fine_appeals WHERE fine_id = NEW.fine_id LOOP
                    SELECT count(*) FILTER (WHERE status <> 'discarded'), count(*) FILTER (WHERE status = 'applied')
                      INTO v_vivos, v_aplicados
                      FROM fine_payments WHERE fine_appeal_id = v_recurso.id;

                    IF v_recurso.status = 'open' AND v_vivos <> 1 THEN
                        RAISE EXCEPTION 'Un recurso en trámite tiene su pago: sin él, el recurso cae.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'dropped' AND v_vivos <> 0 THEN
                        RAISE EXCEPTION 'Un recurso que cayó no conserva pagos vivos.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'rejected' AND v_vivos > 1 THEN
                        RAISE EXCEPTION 'Un recurso tiene un solo pago.' USING ERRCODE = 'check_violation';
                    END IF;

                    IF v_recurso.status = 'upheld' THEN
                        IF v_aplicados <> 1 THEN
                            RAISE EXCEPTION 'El recurso prospera con su pago cobrado.' USING ERRCODE = 'check_violation';
                        END IF;

                        IF (SELECT debt FROM fine_balances WHERE fine_id = NEW.fine_id) <> 0 THEN
                            RAISE EXCEPTION 'El recurso que prosperó deja la multa cancelada.' USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                END LOOP;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER fine_appeals_consistent AFTER INSERT OR UPDATE ON fine_appeals
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();
            CREATE CONSTRAINT TRIGGER fine_payments_appeal_consistent AFTER INSERT OR UPDATE ON fine_payments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();
            CREATE CONSTRAINT TRIGGER fine_payment_applications_appeal_consistent AFTER INSERT ON fine_payment_applications
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();
            CREATE CONSTRAINT TRIGGER fine_debt_entries_appeal_consistent AFTER INSERT ON fine_debt_entries
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();
            CREATE CONSTRAINT TRIGGER fine_historical_appeal_consistent AFTER INSERT OR UPDATE ON fine_historical_movements
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION fine_appeal_consistent();
        SQL;

    private const QUITAR_GUARDAS = <<<'SQL'
            DROP TRIGGER fine_historical_appeal_consistent ON fine_historical_movements;
            DROP TRIGGER fine_debt_entries_appeal_consistent ON fine_debt_entries;
            DROP TRIGGER fine_payment_applications_appeal_consistent ON fine_payment_applications;
            DROP TRIGGER fine_payments_appeal_consistent ON fine_payments;
            DROP TRIGGER fine_appeals_consistent ON fine_appeals;
            DROP FUNCTION fine_appeal_consistent();
            DROP TRIGGER fine_debt_entries_follow_appeal ON fine_debt_entries;
            DROP FUNCTION fine_debt_entries_follow_appeal();
            DROP TRIGGER fine_historical_follow_appeal ON fine_historical_movements;
            DROP FUNCTION fine_historical_follow_appeal();
            DROP TRIGGER fine_payments_follow_appeal ON fine_payments;
            DROP FUNCTION fine_payments_follow_appeal();
            DROP TRIGGER fine_appeals_guard ON fine_appeals;
            DROP FUNCTION fine_appeals_guard();
            DROP FUNCTION fine_appeal_status(bigint);
        SQL;

    private const AJUSTE = "'appeal_admitted', 'court_ruling', 'resolution_correction', 'other'";

    private const CORRECCION_DE_IMPORTE = "'data_entry_error', 'misread_paper', 'other'";

    private const REVERSION = "'data_entry_error', 'wrong_fine', 'duplicate', 'other'";

    /** La vista de `2026_10_23_010000_add_cheque_rejections.php`, con el estado del recurso al final. */
    private const SALDOS_CON_RECURSO = <<<'SQL'
            CREATE OR REPLACE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid,
                       -- El instrumento entero —excedente incluido—, una vez por cadena, mientras su rechazo esté abierto (M03).
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a
                            WHERE a.fine_id = f.id
                              AND EXISTS (
                                  SELECT 1 FROM cheque_rejections c
                                   WHERE c.replacement_fund_receipt_id IS NULL
                                     AND fund_receipt_root(c.fund_receipt_id) = a.fund_receipt_id
                              )
                       ), 0)::numeric(19,2) AS pending_regularization
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess,
                   pending_regularization,
                   -- El recurso que no cayó, si hay: uno solo por multa.
                   (SELECT r.status FROM fine_appeals r WHERE r.fine_id = base.fine_id AND r.status <> 'dropped')::varchar(10) AS appeal_status
              FROM base;
        SQL;

    private const SALDOS_SIN_RECURSO = <<<'SQL'
            DROP VIEW fine_balances;
            CREATE VIEW fine_balances AS
            WITH base AS (
                SELECT f.id AS fine_id,
                       fine_obligation(f.id) AS obligation,
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a WHERE a.fine_id = f.id
                       ), 0)::numeric(19,2) AS operational_paid,
                       COALESCE((
                           SELECT SUM(CASE h.kind WHEN 'payment' THEN h.amount ELSE -h.amount END)
                             FROM fine_historical_movements h
                            WHERE h.fine_id = f.id AND h.status = 'validated'
                       ), 0)::numeric(19,2) AS historical_paid,
                       -- El instrumento entero —excedente incluido—, una vez por cadena, mientras su rechazo esté abierto (M03).
                       COALESCE((
                           SELECT SUM(a.amount) FROM fine_payment_applications a
                            WHERE a.fine_id = f.id
                              AND EXISTS (
                                  SELECT 1 FROM cheque_rejections c
                                   WHERE c.replacement_fund_receipt_id IS NULL
                                     AND fund_receipt_root(c.fund_receipt_id) = a.fund_receipt_id
                              )
                       ), 0)::numeric(19,2) AS pending_regularization
                  FROM fines f
            )
            SELECT fine_id,
                   obligation,
                   operational_paid,
                   historical_paid,
                   GREATEST(obligation - operational_paid - historical_paid, 0)::numeric(19,2) AS debt,
                   GREATEST(operational_paid + historical_paid - obligation, 0)::numeric(19,2) AS excess,
                   GREATEST(operational_paid - GREATEST(obligation - historical_paid, 0), 0)::numeric(19,2) AS operational_excess,
                   pending_regularization
              FROM base;
        SQL;

    public function up(): void
    {
        // Con la regla nueva el recurso es el primer pago: uno vivo que convive con otros no se convierte.
        $incompatibles = DB::table('fine_payments AS p')
            ->whereNotNull('p.appeal_kind')
            ->where('p.status', '<>', 'discarded')
            ->where(fn ($q) => $q
                ->whereExists(fn ($o) => $o->from('fine_payments AS o')
                    ->whereColumn('o.fine_id', 'p.fine_id')
                    ->whereColumn('o.id', '<>', 'p.id')
                    ->where('o.status', '<>', 'discarded'))
                ->orWhereExists(fn ($h) => $h->from('fine_historical_movements AS h')
                    ->whereColumn('h.fine_id', 'p.fine_id')
                    ->where('h.status', '<>', 'rejected')))
            ->orderBy('p.fine_id')
            ->pluck('p.fine_id');

        if ($incompatibles->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay recursos que conviven con otros pagos o antecedentes (multas %s): con la regla nueva el recurso es el primer pago. '
                .'Revisalos desde la ficha de cada multa y corré la migración de nuevo.',
                $incompatibles->unique()->implode(', '),
            ));
        }

        Schema::create('fine_appeals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fine_id')->constrained('fines')->restrictOnDelete();
            $table->string('status', 10)->default('open');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['id', 'fine_id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fine_appeals
                ADD CONSTRAINT fine_appeals_status_check CHECK (status IN ('open', 'upheld', 'rejected', 'dropped')),
                ADD CONSTRAINT fine_appeals_resolution_check CHECK (
                    (status IN ('upheld', 'rejected')) = (resolved_at IS NOT NULL)
                    AND (resolved_by IS NULL OR resolved_at IS NOT NULL)
                );
            CREATE UNIQUE INDEX fine_appeals_one_per_fine ON fine_appeals (fine_id) WHERE status <> 'dropped';

            ALTER TABLE fine_payments ADD COLUMN fine_appeal_id bigint;
            ALTER TABLE fine_debt_entries ADD COLUMN fine_appeal_id bigint;
        SQL);

        $this->convertirRecursosExistentes();

        $ajuste = self::AJUSTE;
        $correccion = self::CORRECCION_DE_IMPORTE;
        $reversion = self::REVERSION;

        DB::unprepared(<<<SQL
            ALTER TABLE fine_payments
                ADD CONSTRAINT fine_payments_appeal_fk FOREIGN KEY (fine_appeal_id, fine_id)
                    REFERENCES fine_appeals (id, fine_id) ON DELETE RESTRICT,
                ADD CONSTRAINT fine_payments_appeal_link_check CHECK ((appeal_kind IS NULL) = (fine_appeal_id IS NULL));
            CREATE INDEX fine_payments_fine_appeal_id_index ON fine_payments (fine_appeal_id);

            ALTER TABLE fine_debt_entries
                ADD CONSTRAINT fine_debt_entries_appeal_fk FOREIGN KEY (fine_appeal_id, fine_id)
                    REFERENCES fine_appeals (id, fine_id) ON DELETE RESTRICT,
                DROP CONSTRAINT fine_debt_entries_reason_check,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_appeal_id IS NOT NULL AND kind = 'reduction' AND fine_document_id IS NULL AND reason_code = 'appeal_admitted')
                        OR (fine_appeal_id IS NULL AND fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_appeal_id IS NULL AND fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (fine_appeal_id IS NULL OR kind = 'reduction')
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );
            CREATE UNIQUE INDEX fine_debt_entries_one_per_appeal ON fine_debt_entries (fine_appeal_id) WHERE fine_appeal_id IS NOT NULL;
        SQL);

        DB::unprepared(self::GUARDAS);
        DB::unprepared(self::SALDOS_CON_RECURSO);
    }

    public function down(): void
    {
        if (DB::table('fine_appeals')->whereIn('status', ['upheld', 'rejected'])->exists()) {
            throw new RuntimeException('Hay recursos resueltos: sin el trámite, su veredicto se perdería.');
        }

        $ajuste = self::AJUSTE;
        $correccion = self::CORRECCION_DE_IMPORTE;
        $reversion = self::REVERSION;

        DB::unprepared(self::SALDOS_SIN_RECURSO);
        DB::unprepared(self::QUITAR_GUARDAS);
        DB::unprepared(<<<SQL
            DROP INDEX fine_debt_entries_one_per_appeal;
            ALTER TABLE fine_debt_entries
                DROP CONSTRAINT fine_debt_entries_reason_check,
                DROP CONSTRAINT fine_debt_entries_appeal_fk,
                DROP COLUMN fine_appeal_id,
                ADD CONSTRAINT fine_debt_entries_reason_check CHECK (
                    (kind = 'constitution') = (reason_code IS NULL)
                    AND (kind <> 'reversal' OR reason_code IN ({$reversion}))
                    AND (kind NOT IN ('increase', 'reduction')
                        OR (fine_document_id IS NOT NULL AND reason_code IN ({$ajuste}))
                        OR (fine_document_id IS NULL AND reason_code IN ({$correccion}) AND reason IS NOT NULL))
                    AND (reason IS NULL OR btrim(reason) <> '')
                    AND (reason_code IS DISTINCT FROM 'other' OR reason IS NOT NULL)
                );

            ALTER TABLE fine_payments
                DROP CONSTRAINT fine_payments_appeal_link_check,
                DROP CONSTRAINT fine_payments_appeal_fk,
                DROP COLUMN fine_appeal_id;
        SQL);

        Schema::drop('fine_appeals');
    }

    /**
     * Un trámite por cadena de corrección: la raíz se sigue por
     * `replaces_fine_payment_id`. La cadena queda en trámite si conserva un
     * pago vivo; si todos se descartaron, cayó.
     *
     * Un pago no se edita (`fine_payments_guard`): el vínculo se escribe con
     * la guarda apagada, una sola vez y dentro de esta migración.
     */
    private function convertirRecursosExistentes(): void
    {
        $pagos = DB::table('fine_payments')
            ->whereNotNull('appeal_kind')
            ->orderBy('id')
            ->get(['id', 'fine_id', 'status', 'replaces_fine_payment_id', 'created_by', 'created_at']);

        if ($pagos->isEmpty()) {
            return;
        }

        $reemplaza = $pagos->pluck('replaces_fine_payment_id', 'id')->all();
        $raiz = static function (int $id) use ($reemplaza): int {
            while (($anterior = $reemplaza[$id] ?? null) !== null && array_key_exists($anterior, $reemplaza)) {
                $id = $anterior;
            }

            return $id;
        };

        DB::unprepared('ALTER TABLE fine_payments DISABLE TRIGGER fine_payments_guard');

        foreach ($pagos->groupBy(fn (object $pago): int => $raiz((int) $pago->id)) as $cadena) {
            $primero = $cadena->first();
            $vivo = $cadena->contains(fn (object $pago): bool => $pago->status !== 'discarded');

            $recurso = DB::table('fine_appeals')->insertGetId([
                'fine_id' => $primero->fine_id,
                'status' => $vivo ? 'open' : 'dropped',
                'created_by' => $primero->created_by,
                'created_at' => $primero->created_at,
                'updated_at' => now(),
            ]);

            DB::table('fine_payments')->whereIn('id', $cadena->pluck('id'))->update(['fine_appeal_id' => $recurso]);
        }

        // El UPDATE deja pendientes las verificaciones diferidas de la tabla, y con eventos pendientes PostgreSQL no admite ALTER TABLE.
        DB::unprepared('SET CONSTRAINTS ALL IMMEDIATE');
        DB::unprepared('ALTER TABLE fine_payments ENABLE TRIGGER fine_payments_guard');
    }
};
