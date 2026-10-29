<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un ajuste de la obligación por resolución: el importe nuevo, el que se veía
 * al cargarlo, la resolución que lo dispone —ya cargada o nueva— y el motivo.
 * Si es aumento o reducción lo deduce el Action.
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
            ...self::importes(),
            'effectiveOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'documentId' => ['nullable', 'integer'],
            'document' => ['nullable', 'array'],
            'document.text' => ['required_without:documentId', 'nullable', 'string', 'max:120'],
            'document.issuedOn' => ['required_without:documentId', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            ...Reason::rules(FineReasonAct::Adjustment),
        ];
    }

    /**
     * El importe nuevo y el vigente que vio el formulario: los comparte con la
     * corrección del importe.
     *
     * @return array<string, list<string>>
     */
    public static function importes(): array
    {
        return [
            'newAmount' => ['required', 'numeric', 'gte:0', 'decimal:0,2', 'max:99999999999999999'],
            'expectedAmount' => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
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
            'newAmount' => 'nuevo importe',
            'effectiveOn' => 'fecha',
            'documentId' => 'resolución',
            'document.text' => 'resolución',
            'document.issuedOn' => 'fecha de la resolución',
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
        ];
    }
}
