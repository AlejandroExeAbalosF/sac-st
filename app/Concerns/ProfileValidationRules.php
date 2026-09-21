<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|Unique|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'firstName' => $this->firstNameRules(),
            'lastName' => $this->lastNameRules(),
            'username' => $this->usernameRules($userId),
            'documentNumber' => $this->documentNumberRules($userId),
            'email' => $this->emailRules($userId),
        ];
    }

    /**
     * El nombre de pila del operador.
     *
     * El largo replica el de la columna. Los dos campos son obligatorios
     * porque `users.name` —lo que se muestra en pantalla y se firma al pie
     * de un comprobante— es una columna generada sobre ellos: sin uno de
     * los dos, el usuario se queda sin nombre.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function firstNameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:80'];
    }

    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function lastNameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:80'];
    }

    /**
     * Reglas del nombre de usuario.
     *
     * El formato replica el CHECK de la tabla: minúsculas, sin espacios,
     * al menos tres caracteres. Se admiten punto, guion y guion bajo
     * porque el área usa nombres del estilo `g.sosa`.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function usernameRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'min:3',
            'max:60',
            'regex:/^[a-z0-9._-]+$/',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * DNI del operador: solo dígitos, entre 7 y 9. El formato replica el
     * CHECK de la tabla.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function documentNumberRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'regex:/^[0-9]{7,9}$/',
            $userId === null
                ? Rule::unique(User::class, 'document_number')
                : Rule::unique(User::class, 'document_number')->ignore($userId),
        ];
    }

    /**
     * El email es obligatorio: el área confirmó que todo el personal tiene
     * casilla institucional. Es además lo que permite que cualquiera
     * restablezca su contraseña sin depender de un administrador.
     *
     * @return array<int, ValidationRule|Unique|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }
}
