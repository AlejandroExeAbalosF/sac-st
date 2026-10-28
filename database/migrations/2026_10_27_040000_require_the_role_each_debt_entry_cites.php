<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada movimiento de la obligación exige que su documento conserve el papel
 * que le corresponde: el importe original, el de origen; un aumento o una
 * reducción, el de modificación.
 *
 * La guarda anterior (`2026_10_27_010000_add_revocation_to_fine_document_links`)
 * solo pedía que el documento conservara *algún* vínculo vigente. Con un
 * vínculo de respaldo vigente se podía anular el de origen de la resolución
 * del importe original, y la multa quedaba sin resolución de origen aunque su
 * importe la siguiera citando.
 *
 * - `fine_debt_entry_document_role`: el papel que exige cada tipo de
 *   movimiento. Una reversión no exige uno en particular.
 * - `fine_document_links_revoke_only`: no se anula el vínculo con el papel
 *   que exige un movimiento vigente que cita el documento, aunque haya otros.
 *   Los demás papeles del mismo documento se desvinculan.
 * - `fine_debt_entries_cite_active_link`: un movimiento nuevo cita un
 *   documento vinculado y vigente con el papel que le corresponde.
 *
 * La migración se niega a correr si alguna multa ya quedó así, y dice cuáles:
 * se reparan desde la ficha, volviendo a vincular el documento con su papel,
 * para que la reparación quede con autor.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_debt_entry_document_role(p_kind varchar) RETURNS varchar AS $$
                SELECT CASE p_kind
                    WHEN 'constitution' THEN 'origin'
                    WHEN 'increase' THEN 'modification'
                    WHEN 'reduction' THEN 'modification'
                END
            $$ LANGUAGE sql IMMUTABLE;
        SQL);

        $rotas = DB::table('fine_debt_entries AS e')
            ->join('fines AS f', 'f.id', '=', 'e.fine_id')
            ->whereNotNull('e.fine_document_id')
            ->where('e.kind', '<>', 'reversal')
            ->whereNotExists(fn ($q) => $q->from('fine_debt_entries AS r')->whereColumn('r.reversal_of_id', 'e.id'))
            ->whereNotExists(fn ($q) => $q->from('fine_document_links AS l')
                ->whereColumn('l.fine_id', 'e.fine_id')
                ->whereColumn('l.fine_document_id', 'e.fine_document_id')
                ->whereNull('l.revoked_at')
                ->whereRaw('l.role = fine_debt_entry_document_role(e.kind)'))
            ->orderBy('f.id')
            ->get(['f.id', 'f.fine_case_id', 'f.fine_number', 'e.kind', 'e.fine_document_id']);

        if ($rotas->isNotEmpty()) {
            $detalle = $rotas
                ->map(fn (object $r): string => sprintf(
                    'multa %d (expediente %d, multa n.º %s): su %s cita el documento %d sin el vínculo de %s',
                    $r->id,
                    $r->fine_case_id,
                    $r->fine_number,
                    $r->kind === 'constitution' ? 'importe original' : 'ajuste',
                    $r->fine_document_id,
                    $r->kind === 'constitution' ? 'origen' : 'modificación',
                ))
                ->implode('; ');

            throw new RuntimeException(
                "Hay movimientos cuyo documento perdió el papel que exigen. Volvé a vincular cada documento con su papel desde la ficha de la multa y corré la migración de nuevo. {$detalle}.",
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_document_links_revoke_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El vinculo % ya esta anulado.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.revoked_at IS NULL
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                -- El papel que exige un movimiento vigente no se anula, aunque el
                -- documento tenga otros vinculos: el importe original exige el
                -- de origen; un ajuste, el de modificacion.
                IF EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND fine_debt_entry_document_role(e.kind) = OLD.role
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa % con el papel %: no se desvincula.', OLD.fine_document_id, OLD.fine_id, OLD.role
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_cite_active_link() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = NEW.fine_id
                       AND fine_document_id = NEW.fine_document_id
                       AND revoked_at IS NULL
                       AND (fine_debt_entry_document_role(NEW.kind) IS NULL
                            OR role = fine_debt_entry_document_role(NEW.kind))
                ) THEN
                    RAISE EXCEPTION 'El documento que respalda el movimiento no esta vinculado a la multa con el papel que le corresponde: origen para el importe original, modificacion para un ajuste.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_document_links_revoke_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'El vinculo % ya esta anulado.', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.revoked_at IS NULL
                   OR (to_jsonb(NEW) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code')
                      IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at' - 'revoked_by' - 'revocation_reason' - 'revocation_reason_code') THEN
                    RAISE EXCEPTION 'Un vinculo de documento no se edita: solo se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Ordena la anulacion con un ajuste simultaneo de la misma multa.
                PERFORM 1 FROM fines WHERE id = OLD.fine_id FOR UPDATE;

                IF NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = OLD.fine_id
                       AND fine_document_id = OLD.fine_document_id
                       AND revoked_at IS NULL
                       AND id <> OLD.id
                ) AND EXISTS (
                    SELECT 1 FROM fine_debt_entries e
                     WHERE e.fine_id = OLD.fine_id
                       AND e.fine_document_id = OLD.fine_document_id
                       AND e.kind <> 'reversal'
                       AND NOT EXISTS (SELECT 1 FROM fine_debt_entries r WHERE r.reversal_of_id = e.id)
                ) THEN
                    RAISE EXCEPTION 'El documento % respalda un movimiento vigente de la obligacion de la multa %: no se desvincula.', OLD.fine_document_id, OLD.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION fine_debt_entries_cite_active_link() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM fine_document_links
                     WHERE fine_id = NEW.fine_id
                       AND fine_document_id = NEW.fine_document_id
                       AND revoked_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'El documento que respalda el movimiento tiene el vinculo con la multa anulado.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION fine_debt_entry_document_role(varchar);
        SQL);
    }
};
