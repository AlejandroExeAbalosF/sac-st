<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El número de la multa dentro de su expediente: «la multa 2 del 169607».
 *
 * Es lo que va en la dirección —`/multas/expedientes/12/169607-2024-0/multa/2`—
 * en lugar del id global, que no le dice nada a nadie, y de la carátula, que
 * puede ser el nombre de una persona. Igual que el haber en Haberes.
 *
 * Lo pone la base al insertar, no el Action: el alta, la importación del Excel
 * histórico y cualquier carga futura salen numeradas sin acordarse de
 * hacerlo. El candado por expediente ordena dos altas simultáneas; el índice
 * único es la garantía de fondo. Una vez puesto no cambia: `fine_identity_guard`
 * rechaza modificar cualquier dato que no figure entre los editables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fines ADD COLUMN fine_number smallint;

            -- Las multas que ya están se numeran en el orden en que se cargaron.
            ALTER TABLE fines DISABLE TRIGGER fines_identity_guard;
            UPDATE fines f
               SET fine_number = n.orden
              FROM (SELECT id, row_number() OVER (PARTITION BY fine_case_id ORDER BY id) AS orden FROM fines) n
             WHERE n.id = f.id;
            ALTER TABLE fines ENABLE TRIGGER fines_identity_guard;

            ALTER TABLE fines ALTER COLUMN fine_number SET NOT NULL;
            ALTER TABLE fines ADD CONSTRAINT fines_number_positive_check CHECK (fine_number >= 1);
            CREATE UNIQUE INDEX fines_case_number_unique ON fines (fine_case_id, fine_number);

            CREATE OR REPLACE FUNCTION fines_assign_number() RETURNS trigger AS $$
            BEGIN
                IF NEW.fine_number IS NULL THEN
                    -- Dos altas del mismo expediente no leen el mismo máximo.
                    PERFORM pg_advisory_xact_lock(hashtext('fines.fine_number'), NEW.fine_case_id::integer);

                    SELECT coalesce(max(fine_number), 0) + 1
                      INTO NEW.fine_number
                      FROM fines
                     WHERE fine_case_id = NEW.fine_case_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fines_assign_number BEFORE INSERT ON fines
                FOR EACH ROW EXECUTE FUNCTION fines_assign_number();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER fines_assign_number ON fines;
            DROP FUNCTION fines_assign_number();
            DROP INDEX fines_case_number_unique;
            ALTER TABLE fines DROP CONSTRAINT fines_number_positive_check;
            ALTER TABLE fines DROP COLUMN fine_number;
        SQL);
    }
};
