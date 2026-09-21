<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Modules\Shared\Enums\SystemRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un usuario.
 *
 * La contraseña no se valida acá porque no llega: la genera el sistema.
 * Las reglas de identidad se reusan del mismo trait que valida el perfil
 * propio, para que el formato del `username` y del DNI sea uno solo y siga
 * coincidiendo con los `CHECK` de la tabla.
 */
final class StoreUserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'position' => ['nullable', 'string', 'max:120'],
            'role' => ['required', Rule::enum(SystemRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'firstName' => 'nombre',
            'lastName' => 'apellido',
            'username' => 'nombre de usuario',
            'documentNumber' => 'DNI',
            'email' => 'correo electrónico',
            'position' => 'cargo',
            'role' => 'rol',
        ];
    }

    /**
     * Los atributos del usuario, con el nombre que tienen en la tabla.
     *
     * `name` no figura: la calcula la base a partir del apellido y el nombre.
     *
     * @return array{first_name: string, last_name: string, username: string, document_number: string, email: string, position: string|null}
     */
    public function userAttributes(): array
    {
        $position = trim((string) $this->validated('position'));

        return [
            'first_name' => (string) $this->validated('firstName'),
            'last_name' => (string) $this->validated('lastName'),
            'username' => (string) $this->validated('username'),
            'document_number' => (string) $this->validated('documentNumber'),
            'email' => (string) $this->validated('email'),
            'position' => $position === '' ? null : $position,
        ];
    }

    protected function prepareForValidation(): void
    {
        // El nombre de usuario se guarda siempre en minúscula: lo impone un
        // CHECK de la tabla, y hacerlo acá evita que un alta falle por una
        // mayúscula de más en vez de por algo que importe.
        $this->merge([
            'firstName' => preg_replace('/\s+/u', ' ', trim((string) $this->input('firstName'))),
            'lastName' => preg_replace('/\s+/u', ' ', trim((string) $this->input('lastName'))),
            'username' => mb_strtolower(trim((string) $this->input('username'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
