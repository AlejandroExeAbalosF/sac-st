<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un ajuste de la obligación: aumento o reducción, con la resolución que lo
 * dispone —ya cargada o nueva— y el motivo.
 */
final class AdjustFineDebtRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = BusinessDate::today()->toDateString();

        return [
            'kind' => ['required', 'in:increase,reduction'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'effectiveOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'documentId' => ['nullable', 'integer'],
            'document' => ['nullable', 'array'],
            'document.text' => ['required_without:documentId', 'nullable', 'string', 'max:120'],
            'document.issuedOn' => ['required_without:documentId', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            ...Reason::rules(FineReasonAct::Adjustment),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Reason::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'kind' => 'tipo de ajuste',
            'amount' => 'importe',
            'effectiveOn' => 'fecha',
            'documentId' => 'resolución',
            'document.text' => 'resolución',
            'document.issuedOn' => 'fecha de la resolución',
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
        ];
    }
}
