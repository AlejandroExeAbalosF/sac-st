<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los permisos que no se delegan solo se vinculan a los roles que reciben
 * todo por diseño.
 *
 * Con cualquiera de ellos, un rol de la matriz se daba más poder del que
 * tenía: con `roles.gestionar` editaba su propia matriz; con
 * `usuarios.crear` o `usuarios.editar` asignaba el rol de administrador;
 * con `usuarios.restablecer-password` entraba como un compañero, y con
 * `usuarios.desactivar` dejaba afuera a los demás. El catálogo los declara
 * no delegables y `UpdateRolePermissions` los rechaza con un mensaje; esto
 * lo impide aunque alguien escriba en la tabla por otro camino.
 *
 * La lista va escrita acá y no leída del catálogo, que es PHP: un test
 * coteja que cada permiso no delegable del catálogo esté cubierto.
 *
 * Si algún rol de la matriz ya lo tiene, la migración se frena en vez de
 * quitárselo en silencio: sacarlo es un cambio de permisos, y tiene que
 * hacerse desde Configuración → Roles, que lo deja en la auditoría.
 */
return new class extends Migration
{
    private const RESERVED = [
        'roles.gestionar',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'usuarios.restablecer-password',
    ];

    private const ALLOWED_ROLES = ['administrador', 'super-admin'];

    public function up(): void
    {
        $infractores = DB::table('role_has_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.name', self::RESERVED)
            ->whereNotIn('r.name', self::ALLOWED_ROLES)
            ->orderBy('r.name')
            ->selectRaw("r.name || ' (' || p.name || ')' AS detalle")
            ->pluck('detalle')
            ->implode(', ');

        if ($infractores !== '') {
            throw new RuntimeException(
                "Estos roles tienen permisos que ya no se delegan: {$infractores}. "
                .'Quitáselos desde Configuración → Roles —queda en la auditoría— y volvé a migrar.'
            );
        }

        $reservados = self::sqlList(self::RESERVED);
        $permitidos = self::sqlList(self::ALLOWED_ROLES);

        DB::statement(
            'CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ('.$reservados.'))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('.$permitidos.')) THEN
                    RAISE EXCEPTION \'Este permiso no se delega: lo tiene solo el administrador.\'
                        USING ERRCODE = \'check_violation\';
                END IF;

                RETURN NEW;
            END;
            $$'
        );

        DB::statement(<<<'SQL'
            CREATE TRIGGER role_has_permissions_guard_non_delegable
                BEFORE INSERT OR UPDATE ON role_has_permissions
                FOR EACH ROW EXECUTE FUNCTION role_has_permissions_guard_non_delegable()
            SQL);
    }

    /**
     * @param  list<string>  $values
     */
    private static function sqlList(array $values): string
    {
        return implode(', ', array_map(fn (string $value): string => "'{$value}'", $values));
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS role_has_permissions_guard_non_delegable ON role_has_permissions');
        DB::statement('DROP FUNCTION IF EXISTS role_has_permissions_guard_non_delegable()');
    }
};
