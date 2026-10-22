<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El aviso a la empresa y qué se hizo con el cheque rechazado, con su
 * constancia. Al menos uno de los dos; la disposición, siempre con la suya.
 */
final class CompleteFineChequeRejectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'companyNotifiedOn' => ['nullable', 'required_without:disposition', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'notice' => ScannedDocument::rules(required: false),
            'disposition' => ['nullable', 'in:voided_returned,act'],
            'dispositionProof' => [...ScannedDocument::rules(required: false), 'required_with:disposition'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'companyNotifiedOn.required_without' => 'Completá el aviso a la empresa o qué se hizo con el cheque.',
            'dispositionProof.required_with' => 'Adjuntá la constancia: la devolución firmada o el acta.',
            ...ScannedDocument::messages('notice'),
            ...ScannedDocument::messages('dispositionProof'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'companyNotifiedOn' => 'fecha del aviso',
            'notice' => 'constancia del aviso',
            'disposition' => 'qué se hizo con el cheque',
            'dispositionProof' => 'constancia',
        ];
    }
}
