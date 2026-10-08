<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El detalle de cada cierre queda congelado con sus totales.
 *
 * Los totales del cierre ya eran un snapshot, pero la planilla volvía a
 * consultar el libro para el detalle —recibos e inventario de cheques—, y
 * el inventario salía del estado actual del cheque: un cheque depositado
 * después desaparecía de la planilla de un día anterior mientras el saldo
 * lo seguía contando. Ahora el detalle se guarda al cerrar, en la misma
 * transacción, y la planilla se dibuja desde ahí.
 *
 * - `period_closing_evidence`: una fila por versión del cierre, append-only.
 *   Reabrir y volver a cerrar agrega una versión; la anterior queda.
 * - `period_closings.evidence_version`: cuál rige. **Cero** marca los
 *   cierres hechos antes de esta migración: su detalle no se inventa a
 *   partir de datos actuales.
 * - `period_closing_has_evidence`: un cierre no queda cerrado sin su
 *   detalle de la versión vigente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_closings', function (Blueprint $table): void {
            // Cero identifica cierres anteriores: no inventamos su detalle.
            $table->unsignedInteger('evidence_version')->default(0);
        });
        Schema::create('period_closing_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('period_closing_id')->constrained('period_closings')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['period_closing_id', 'version']);
        });
        DB::statement('ALTER TABLE period_closing_evidence ADD CONSTRAINT closing_evidence_version_positive CHECK (version > 0)');
        DB::statement('CREATE TRIGGER closing_evidence_append_only BEFORE UPDATE OR DELETE ON period_closing_evidence
            FOR EACH ROW EXECUTE FUNCTION forbid_mutation()');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION closing_evidence_matches_version() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM period_closings WHERE id = NEW.period_closing_id
                    AND status = 'closed' AND evidence_version = NEW.version) THEN
                    RAISE EXCEPTION 'La evidencia debe corresponder a la version vigente de un periodo cerrado.' USING ERRCODE = 'check_violation';
                END IF;
                IF jsonb_typeof(NEW.payload) IS DISTINCT FROM 'object' OR NEW.payload->>'schema' IS DISTINCT FROM '1' THEN
                    RAISE EXCEPTION 'Formato de evidencia de cierre invalido.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER closing_evidence_matches_version BEFORE INSERT ON period_closing_evidence
                FOR EACH ROW EXECUTE FUNCTION closing_evidence_matches_version();
            CREATE OR REPLACE FUNCTION period_closing_has_evidence() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'closed' AND NEW.evidence_version > 0 AND NOT EXISTS (
                    SELECT 1 FROM period_closing_evidence WHERE period_closing_id = NEW.id AND version = NEW.evidence_version
                ) THEN
                    RAISE EXCEPTION 'El cierre requiere su detalle congelado en la misma transaccion.' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER period_closing_has_evidence AFTER INSERT OR UPDATE ON period_closings
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION period_closing_has_evidence();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS period_closing_has_evidence ON period_closings;
            DROP FUNCTION IF EXISTS period_closing_has_evidence();
            DROP TRIGGER IF EXISTS closing_evidence_matches_version ON period_closing_evidence;
            DROP FUNCTION IF EXISTS closing_evidence_matches_version();
        SQL);
        Schema::dropIfExists('period_closing_evidence');
        Schema::table('period_closings', function (Blueprint $table): void {
            $table->dropColumn('evidence_version');
        });
    }
};
