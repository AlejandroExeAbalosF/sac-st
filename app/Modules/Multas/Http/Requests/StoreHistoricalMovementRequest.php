<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un pago o una devolución anterior al sistema. La fecha puede ser solo el
 * mes —es lo que da el Excel— y el respaldo dice de dónde sale el dato.
 */
final class StoreHistoricalMovementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:payment,refund'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'occurredOn' => ['nullable', new PartialDateRule],
            'evidence' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo',
            'amount' => 'importe',
            'occurredOn' => 'fecha',
            'evidence' => 'respaldo',
        ];
    }
}
