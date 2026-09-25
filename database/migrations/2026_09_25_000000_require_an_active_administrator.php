<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El sistema no se queda sin un administrador activo.
 *
 * `SetUserActive` y `UpdateUser` lo comprobaban antes de escribir, pero sin
 * bloquear nada: dos administradores que se desactivan uno al otro al mismo
 * tiempo veían cada uno al otro activo, y pasaban los dos. Sin
 * administrador nadie tiene `usuarios.*`, y reactivar una cuenta solo se
 * puede con SQL a mano.
 *
 * El trigger es **diferido** porque `syncRoles` quita el rol y lo vuelve a
 * poner dentro de la misma transacción: mirado en el medio, un
 * administrador que conserva su rol parecería haberlo perdido.
 *
 * Y toma un **bloqueo** antes de contar. Es lo que ordena las
 * transacciones simultáneas: la segunda espera a que la primera confirme, y
 * como en `READ COMMITTED` cada consulta ve lo ya confirmado, cuenta con el
 * cambio de la otra. Las Actions toman el mismo bloqueo
 * (`UserManagementGuard::lockAdministrators`) para que su mensaje legible
 * coincida con lo que la base va a decidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ensure_an_active_administrator() RETURNS trigger AS $$
            DECLARE
                v_admin bigint;
            BEGIN
                SELECT id INTO v_admin FROM roles WHERE name = 'administrador' AND guard_name = 'web';

                IF v_admin IS NULL THEN
                    RETURN NULL;
                END IF;

                -- Solo importa si lo que cambió le quita algo a un administrador.
                IF TG_TABLE_NAME = 'users' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM model_has_roles
                        WHERE role_id = v_admin AND model_type = 'App\Models\User' AND model_id = OLD.id
                    ) THEN
                        RETURN NULL;
                    END IF;
                ELSIF OLD.role_id <> v_admin THEN
                    RETURN NULL;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('sacst.active_administrators'));

                IF NOT EXISTS (
                    SELECT 1
                    FROM users u
                    JOIN model_has_roles m ON m.model_id = u.id AND m.model_type = 'App\Models\User'
                    WHERE m.role_id = v_admin AND u.is_active
                ) THEN
                    RAISE EXCEPTION 'El sistema no puede quedarse sin un administrador activo.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER users_keep_an_active_administrator
                AFTER UPDATE OF is_active ON users
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.is_active AND NOT NEW.is_active)
                EXECUTE FUNCTION ensure_an_active_administrator();

            CREATE CONSTRAINT TRIGGER model_has_roles_keep_an_active_administrator
                AFTER UPDATE OR DELETE ON model_has_roles
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION ensure_an_active_administrator();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS model_has_roles_keep_an_active_administrator ON model_has_roles;
            DROP TRIGGER IF EXISTS users_keep_an_active_administrator ON users;
            DROP FUNCTION IF EXISTS ensure_an_active_administrator();
        SQL);
    }
};
