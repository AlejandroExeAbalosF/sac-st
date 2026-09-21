<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Modules\Shared\Enums\SystemRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corrección de la ficha de un usuario.
 *
 * El `username` no figura: es el identificador con el que quedó registrado
 * cada acceso, y cambiarlo volvería ilegible el historial. Si de verdad
 * hubo un error de tipeo en el alta, se da de baja y se crea de nuevo.
 */
final class UpdateUserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->route('user') instanceof User
            ? $this->route('user')->id
            : null;

        return [
            'firstName' => $this->firstNameRules(),
            'lastName' => $this->lastNameRules(),
            'documentNumber' => $this->documentNumberRules($userId),
            'email' => $this->emailRules($userId),
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
            'documentNumber' => 'DNI',
            'email' => 'correo electrónico',
            'position' => 'cargo',
            'role' => 'rol',
        ];
    }

    /**
     * `name` no figura: la calcula la base a partir del apellido y el nombre.
     *
     * @return array{first_name: string, last_name: string, document_number: string, email: string, position: string|null}
     */
    public function userAttributes(): array
    {
        $position = trim((string) $this->validated('position'));

        return [
            'first_name' => (string) $this->validated('firstName'),
            'last_name' => (string) $this->validated('lastName'),
            'document_number' => (string) $this->validated('documentNumber'),
            'email' => (string) $this->validated('email'),
            'position' => $position === '' ? null : $position,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'firstName' => preg_replace('/\s+/u', ' ', trim((string) $this->input('firstName'))),
            'lastName' => preg_replace('/\s+/u', ' ', trim((string) $this->input('lastName'))),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }
}
