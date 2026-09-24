<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * El nombre de usuario NO figura acá a propósito: lo asigna un
     * administrador y no se autogestiona. Es el identificador con el que
     * queda registrado cada intento de acceso en el historial, así que
     * cambiarlo por cuenta propia enturbiaría la lectura de la auditoría.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => $this->firstNameRules(),
            'lastName' => $this->lastNameRules(),
            'email' => $this->emailRules($this->user()->id),
            /*
             * Cambiar el correo exige la contraseña actual.
             *
             * El correo es a donde llega el enlace de recuperación: quien
             * se encuentra una sesión abierta y lo cambia por el suyo,
             * después pide «olvidé mi contraseña» y se queda con la cuenta.
             * El resto del perfil se sigue editando sin pedirla.
             */
            'currentPassword' => [
                Rule::requiredIf(fn (): bool => $this->changesEmail()),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'currentPassword.required' => 'Para cambiar el correo, confirmá tu contraseña actual.',
            'currentPassword.current_password' => 'La contraseña no es correcta.',
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
            'email' => 'correo electrónico',
            'currentPassword' => 'contraseña actual',
        ];
    }

    private function changesEmail(): bool
    {
        return $this->input('email') !== $this->user()->email;
    }

    /**
     * Los atributos propios, con el nombre que tienen en la tabla.
     *
     * @return array{first_name: string, last_name: string, email: string}
     */
    public function userAttributes(): array
    {
        return [
            'first_name' => (string) $this->validated('firstName'),
            'last_name' => (string) $this->validated('lastName'),
            'email' => (string) $this->validated('email'),
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
