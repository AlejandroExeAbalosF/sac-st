<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La corrección de un importe mal cargado: el importe nuevo, el que se veía
 * al cargarlo, el motivo y el detalle, que es obligatorio porque no hay
 * resolución que lo explique.
 */
final class CorrectFineAmountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $motivo = Reason::rules(FineReasonAct::AmountCorrection);
        $motivo['reason'] = ['required', 'string', 'max:500'];

        return [
            ...AdjustFineDebtRequest::importes(),
            ...$motivo,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...Reason::messages(),
            'reason.required' => 'Contá en el detalle qué estaba mal cargado.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'newAmount' => 'nuevo importe',
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
        ];
    }
}
