<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Support\Cuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Buscar a una persona por su documento mientras se lo tipea.
 *
 * Existe porque el buscador general no sirve para esto: busca por
 * coincidencia parcial y no sabe qué hacer con once dígitos. Acá el número
 * se resuelve —si vino un CUIL, se le saca el DNI con la misma clase de
 * siempre— y se contesta con la ficha o con nada.
 */
final class ResolvePersonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'between:6,8', 'regex:/^\d+$/'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->validarNumeroLargo(...)];
    }

    /** El DNI ya normalizado. */
    public function documento(): string
    {
        return (string) $this->validated('document');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['document' => 'DNI'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.between' => 'El DNI debe tener entre 6 y 8 dígitos.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $digitos = preg_replace('/\D+/', '', (string) $this->input('document')) ?? '';

        $this->merge([
            'document' => Cuit::toDocumentNumber($digitos) ?? $digitos,
        ]);
    }

    /**
     * Once dígitos que no se convirtieron en DNI son un CUIT que no cierra
     * o el de una organización. Decirlo acá evita que el operador vea «el
     * DNI debe tener entre 6 y 8 dígitos» después de pegar un CUIL.
     */
    private function validarNumeroLargo(Validator $validator): void
    {
        // Ya pasó por `prepareForValidation`: si sigue teniendo once
        // dígitos es porque no se pudo convertir en un DNI.
        $numero = (string) $this->input('document');

        if (strlen($numero) !== 11) {
            return;
        }

        $validator->errors()->forget('document');

        $validator->errors()->add('document', Cuit::isOrganization($numero)
            ? 'Ese es el CUIT de una organización, no el de una persona.'
            : 'Ese CUIL no es válido: el dígito verificador no cierra.');
    }
}
