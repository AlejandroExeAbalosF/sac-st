<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Los créditos del extracto que pagan un cobro pendiente. */
final class IdentifyFeeCreditsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'credits' => ['required', 'array', 'min:1', 'max:50'],
            'credits.*' => ['integer', 'distinct'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            'idempotencyKey' => ['required', 'string', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['credits' => 'créditos', 'signedById' => 'firma'];
    }

    /** @return list<int> */
    public function credits(): array
    {
        return array_values(array_map('intval', (array) $this->validated('credits')));
    }
}
