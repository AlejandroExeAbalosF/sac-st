<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un pago por el banco que alguien presentó, todavía sin verificar.
 *
 * La presentación de un día anterior lleva su respaldo; eso lo exige el
 * Action, que sabe qué día es hoy para la caja.
 */
final class ReportFineBankPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = BusinessDate::today()->toDateString();

        return [
            'medium' => ['required', 'in:bank_transfer,direct_deposit,echeq'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'presentedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'presentationEvidence' => ['nullable', 'string', 'max:300'],
            'declaredPaidOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'judicialReference' => ['nullable', 'string', 'max:120'],
            'idempotencyKey' => ['required', 'string', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'medium' => 'medio',
            'amount' => 'importe',
            'presentedOn' => 'fecha de presentación',
            'presentationEvidence' => 'respaldo de la presentación',
            'declaredPaidOn' => 'fecha del pago',
            'judicialReference' => 'orden de pago judicial',
        ];
    }
}
