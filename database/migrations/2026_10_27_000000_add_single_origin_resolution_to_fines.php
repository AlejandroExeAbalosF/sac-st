<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una multa tiene una sola resolución de origen: la que la impone.
 *
 * Las demás resoluciones de una multa son posteriores y tienen otro papel:
 * la reducen, la revocan o la ratifican (`modification`, `ratification`).
 * Es lo que muestra el Excel histórico: 135 multas con dos resoluciones en la
 * misma celda, y en las que lo aclaran la segunda es una reducción del
 * Ministerio, un fallo de la Cámara o la corrección de un número —ninguna
 * impone la multa junto con la primera—. Sin esta regla, una segunda
 * resolución podía entrar como origen por error, y el recibo, el importe
 * original y las hojas del expediente leen «la» resolución de origen.
 *
 * Va en un trigger porque el tipo del documento vive en `fine_documents` y un
 * índice parcial no puede mirarlo. Alcanza con el alta: los vínculos son de
 * solo inserción (`fine_document_links_append_only`) y el tipo de un
 * documento no se corrige (`fine_identity_guard`). El candado por multa
 * ordena dos altas simultáneas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $repetidas = DB::table('fine_document_links AS l')
            ->join('fine_documents AS d', 'd.id', '=', 'l.fine_document_id')
            ->where('l.role', 'origin')
            ->where('d.kind', 'resolution')
            ->groupBy('l.fine_id')
            ->havingRaw('count(*) > 1')
            ->pluck('l.fine_id');

        if ($repetidas->isNotEmpty()) {
            throw new RuntimeException(
                'Hay multas con más de una resolución de origen ('.$repetidas->implode(', ').'): decidí cuál es la de origen antes de aplicar la regla.',
            );
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fine_document_links_single_origin_resolution() RETURNS trigger AS $$
            BEGIN
                IF NEW.role <> 'origin'
                   OR (SELECT kind FROM fine_documents WHERE id = NEW.fine_document_id) <> 'resolution' THEN
                    RETURN NEW;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('fine_document_links.origin_resolution'), NEW.fine_id::integer);

                IF EXISTS (
                    SELECT 1
                      FROM fine_document_links l
                      JOIN fine_documents d ON d.id = l.fine_document_id
                     WHERE l.fine_id = NEW.fine_id
                       AND l.role = 'origin'
                       AND d.kind = 'resolution'
                       AND l.fine_document_id <> NEW.fine_document_id
                ) THEN
                    RAISE EXCEPTION 'La multa % ya tiene su resolucion de origen: otra resolucion se vincula como modificacion o ratificacion.', NEW.fine_id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_document_links_single_origin_resolution BEFORE INSERT ON fine_document_links
                FOR EACH ROW EXECUTE FUNCTION fine_document_links_single_origin_resolution();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER fine_document_links_single_origin_resolution ON fine_document_links;
            DROP FUNCTION fine_document_links_single_origin_resolution();
        SQL);
    }
};
