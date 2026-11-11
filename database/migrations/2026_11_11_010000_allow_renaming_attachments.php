<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un adjunto admite que se corrija su descripción, y nada más.
 *
 * El archivo sigue siendo inmutable: dónde está, qué es, cuánto pesa, su
 * huella y quién lo subió no cambian nunca. La descripción es lo que una
 * persona escribió al subirlo —«Resolución 1234/25, foja 3»— y un error de
 * tipeo ahí no justifica subir el archivo de nuevo.
 *
 * La comparación es de la fila entera menos `title`, y no columna por
 * columna: una columna que se agregue mañana queda protegida sin que haya
 * que acordarse de sumarla acá.
 *
 * El borrado no cambia: solo por las funciones `SECURITY DEFINER`, siendo
 * dueño de la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- Renombrar: cambia la descripción y ninguna otra columna.
                IF (to_jsonb(NEW) - 'title') = (to_jsonb(OLD) - 'title') THEN
                    IF btrim(NEW.title) = '' THEN
                        RAISE EXCEPTION 'Un adjunto no se queda sin descripción.'
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
