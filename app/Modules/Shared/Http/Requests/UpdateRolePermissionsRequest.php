<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

/**
 * Los permisos que queda teniendo un rol.
 *
 * Se envía la lista completa, no el alta y la baja: la pantalla muestra la
 * matriz entera, y mandar el estado final evita que dos administradores
 * editando a la vez terminen con un rol que ninguno de los dos configuró.
 *
 * Cada nombre tiene que existir en la tabla de permisos. Un permiso
 * inventado desde el cliente no se crea al vuelo: los permisos nacen en el
 * seeder, con su ruta detrás.
 */
final class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists(Permission::class, 'name')],
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        /** @var list<string> $permissions */
        $permissions = array_values(array_unique((array) $this->validated('permissions')));

        return $permissions;
    }
}
