<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El comprobante de un pago por banco: su fecha de pago, su archivo o los
 * dos. Que llegue al menos uno, y en qué estado se puede cambiar la fecha,
 * lo decide `RecordFinePaymentProof`.
 */
final class StoreFinePaymentProofRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'declaredPaidOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'file' => ScannedDocument::rules(required: false),
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['declaredPaidOn' => 'fecha del pago', 'file' => 'archivo', 'title' => 'descripción'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ScannedDocument::messages('file', 'El comprobante');
    }
}
