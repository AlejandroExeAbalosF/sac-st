<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * El veredicto del recurso: prosperó o no. No pide resolución; si llega una,
 * se carga aparte en la multa. Trae el recurso y el pago que se estaban
 * mirando: el Action rechaza si ya no son los vigentes.
 */
final class ResolveFineAppealRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'verdict' => ['required', 'in:upheld,rejected'],
            'appealId' => ['required', 'integer'],
            'paymentId' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'verdict' => 'veredicto',
            'appealId' => 'recurso',
            'paymentId' => 'pago del recurso',
        ];
    }
}
