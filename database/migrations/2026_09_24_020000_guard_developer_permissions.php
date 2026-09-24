<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las capacidades `dev.*` solo se vinculan al rol `super-admin`.
 *
 * El comodín del administrador las deja afuera a propósito, pero la
 * pantalla de roles dejaba otorgárselas a cualquier otro: un contador con
 * `dev.forzar-cbu` da por verificado un CBU que no pasa los controles, y la
 * Orden de Pago sale a esa cuenta. `UpdateRolePermissions` lo rechaza con
 * un mensaje; esto lo impide aunque alguien escriba en la tabla por otro
 * camino.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION role_has_permissions_guard_developer() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name LIKE 'dev.%')
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name = 'super-admin') THEN
                    RAISE EXCEPTION 'Las capacidades de desarrollo (dev.*) solo las tiene el rol super-admin.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER role_has_permissions_guard_developer
                BEFORE INSERT OR UPDATE ON role_has_permissions
                FOR EACH ROW EXECUTE FUNCTION role_has_permissions_guard_developer()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS role_has_permissions_guard_developer ON role_has_permissions');
        DB::statement('DROP FUNCTION IF EXISTS role_has_permissions_guard_developer()');
    }
};
