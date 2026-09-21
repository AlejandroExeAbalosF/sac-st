<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SearchPeopleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            /*
             * Opcional: el titular de una organización no interviene en el
             * circuito, así que no tiene rol y hay que poder encontrarlo
             * igual. Sin rol la búsqueda recorre el maestro entero, que es
             * justo lo que hace falta en ese caso.
             */
            'role' => ['nullable', Rule::in(['employer', 'beneficiary'])],
            'type' => ['nullable', Rule::in(['individual', 'company'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => trim((string) $this->input('q')),
        ]);
    }
}
