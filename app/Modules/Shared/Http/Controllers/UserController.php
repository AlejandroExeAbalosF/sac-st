<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Shared\Actions\CreateUser;
use App\Modules\Shared\Actions\ResetUserPassword;
use App\Modules\Shared\Actions\SetUserActive;
use App\Modules\Shared\Actions\UpdateUser;
use App\Modules\Shared\Data\UserListItemData;
use App\Modules\Shared\Http\Requests\StoreUserRequest;
use App\Modules\Shared\Http\Requests\UpdateUserRequest;
use App\Modules\Shared\Support\UserManagementGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Alta, corrección y baja operativa de los usuarios del sistema.
 *
 * Hasta que existió esta pantalla, crear un usuario exigía `tinker`.
 *
 * La contraseña temporal viaja de vuelta en un flash de una sola vista.
 * No se guarda en ningún lado y no hay forma de volver a consultarla: si
 * se pierde, se restablece, que es exactamente lo que corresponde.
 */
final class UserController extends Controller
{
    public function index(Request $request, UserManagementGuard $guard): Response
    {
        $viewer = $request->user();

        $search = trim((string) $request->query('buscar', ''));

        $users = User::query()
            ->with('roles')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($inner) use ($search): void {
                    $inner->where('last_name', 'ilike', '%'.$search.'%')
                        ->orWhere('first_name', 'ilike', '%'.$search.'%')
                        ->orWhere('username', 'ilike', '%'.$search.'%')
                        ->orWhere('document_number', 'ilike', '%'.$search.'%')
                        ->orWhere('email', 'ilike', '%'.$search.'%');
                });
            })
            // Los inactivos al final: siguen estando, pero no son con
            // quienes se trabaja todos los días.
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): UserListItemData => UserListItemData::fromModel($user, $viewer, $guard))
            ->values()
            ->all();

        return Inertia::render('configuracion/usuarios', [
            'users' => $users,
            // Solo los que quien mira puede asignar: `super-admin` no
            // aparece para un administrador.
            'roles' => $guard->assignableRoles($viewer),
            'filters' => ['buscar' => $search === '' ? null : $search],
            'can' => [
                'create' => $request->user()?->can('usuarios.crear') ?? false,
                'edit' => $request->user()?->can('usuarios.editar') ?? false,
                'deactivate' => $request->user()?->can('usuarios.desactivar') ?? false,
                'resetPassword' => $request->user()?->can('usuarios.restablecer-password') ?? false,
            ],
        ]);
    }

    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        try {
            ['user' => $user, 'password' => $password] = $createUser->handle(
                $request->userAttributes(),
                (string) $request->validated('role'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        return back()->with('temporaryPassword', [
            'username' => $user->username,
            'name' => $user->name,
            'password' => $password,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $updateUser): RedirectResponse
    {
        try {
            $updateUser->handle(
                $user,
                $request->userAttributes(),
                (string) $request->validated('role'),
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['user' => $e->getMessage()]);
        }

        return back()->with('status', 'Ficha de usuario actualizada.');
    }

    public function setActive(Request $request, User $user, SetUserActive $setActive): RedirectResponse
    {
        $active = $request->boolean('isActive');

        try {
            $setActive->handle($user, $active, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['isActive' => $e->getMessage()]);
        }

        return back()->with(
            'status',
            $active ? 'Usuario activado.' : 'Usuario desactivado y sesiones cerradas.',
        );
    }

    public function resetPassword(Request $request, User $user, ResetUserPassword $reset): RedirectResponse
    {
        try {
            $password = $reset->handle($user, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['resetPassword' => $e->getMessage()]);
        }

        return back()->with('temporaryPassword', [
            'username' => $user->username,
            'name' => $user->name,
            'password' => $password,
        ]);
    }
}
