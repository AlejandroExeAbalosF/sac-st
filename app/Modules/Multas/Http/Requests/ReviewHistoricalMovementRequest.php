<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Enums\FineReasonCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validar o descartar un antecedente. Descartarlo dice por qué.
 */
final class ReviewHistoricalMovementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:validated,rejected'],
            // Descartar elige el motivo; la nota es su detalle, o la nota de la validación.
            'reasonCode' => [
                'required_if:status,rejected', 'prohibited_unless:status,rejected', 'nullable',
                Rule::enum(FineReasonCode::class)->only(FineReasonAct::HistoricalRejection->codes()),
            ],
            'note' => ['nullable', 'string', 'max:500', 'required_if:reasonCode,'.FineReasonCode::Other->value],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reasonCode.required_if' => 'Elegí por qué se descarta.',
            'reasonCode.enum' => 'Ese motivo no corresponde a descartar un antecedente.',
            'note.required_if' => 'Con «Otro», contá el motivo en el detalle.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'resultado', 'reasonCode' => 'motivo', 'note' => 'detalle'];
    }
}
