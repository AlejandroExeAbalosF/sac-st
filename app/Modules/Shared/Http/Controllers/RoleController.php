<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\UpdateRolePermissions;
use App\Modules\Shared\Data\PermissionGroupData;
use App\Modules\Shared\Data\RoleData;
use App\Modules\Shared\Http\Requests\UpdateRolePermissionsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * La matriz de rol × permiso.
 *
 * El catálogo de permisos lo sigue definiendo `RolesAndPermissionsSeeder`:
 * un permiso existe porque hay una ruta que lo exige. Lo que se edita acá
 * es **quién lo tiene**, que es una decisión del área y no del código.
 */
final class RoleController extends Controller
{
    public function index(): Response
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $permissions = array_values(
            Permission::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Permission $permission): string => $permission->name)
                ->all()
        );

        return Inertia::render('configuracion/roles', [
            'roles' => $roles
                ->map(fn (Role $role): RoleData => RoleData::fromModel($role, $role->users_count))
                ->values()
                ->all(),
            'permissionGroups' => PermissionGroupData::group($permissions),
        ]);
    }

    public function update(UpdateRolePermissionsRequest $request, Role $role, UpdateRolePermissions $update): RedirectResponse
    {
        try {
            $update->handle($role, $request->permissions());
        } catch (RuntimeException $e) {
            return back()->withErrors(['permissions' => $e->getMessage()]);
        }

        return back()->with('status', "Permisos del rol {$role->name} actualizados.");
    }
}
