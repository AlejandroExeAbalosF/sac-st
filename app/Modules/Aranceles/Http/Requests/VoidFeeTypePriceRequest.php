<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Por qué se anula un precio: queda a la vista en el historial. */
final class VoidFeeTypePriceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:300']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
