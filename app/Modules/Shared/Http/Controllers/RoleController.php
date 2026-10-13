<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\UpdateRolePermissions;
use App\Modules\Shared\Data\PermissionGroupData;
use App\Modules\Shared\Data\RoleData;
use App\Modules\Shared\Enums\SystemRole;
use App\Modules\Shared\Http\Requests\UpdateRolePermissionsRequest;
use App\Modules\Shared\Permissions\PermissionCatalog;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * La matriz de rol × permiso.
 *
 * Qué permisos existen y qué hace cada uno lo declara `PermissionCatalog`:
 * un permiso existe porque hay una ruta que lo exige. Lo que se edita acá
 * es **quién lo tiene**, que es una decisión del área y no del código.
 */
final class RoleController extends Controller
{
    public function index(PermissionCatalog $catalog): Response
    {
        $roles = Role::query()
            ->with('permissions')
            ->withCount('users')
            ->get()
            ->sortBy(fn (Role $role): int => SystemRole::tryFrom($role->name)?->displayOrder() ?? PHP_INT_MAX);

        /** @var list<string> $seeded */
        $seeded = Permission::query()->pluck('name')->all();

        return Inertia::render('configuracion/roles', [
            'roles' => $roles
                ->map(fn (Role $role): RoleData => RoleData::fromModel($role, $role->users_count))
                ->values()
                ->all(),
            'permissionGroups' => PermissionGroupData::fromCatalog($catalog, $seeded),
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
