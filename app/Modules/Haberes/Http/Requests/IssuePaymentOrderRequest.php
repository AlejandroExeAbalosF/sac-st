<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\ReceiptNumberSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que el formulario de emisión puede decidir.
 *
 * Deliberadamente corto: el importe, el beneficiario, la tabla de
 * depósitos y la cuenta del organismo no están acá porque no vienen del
 * navegador. Los deriva el Action de la cuota.
 */
final class IssuePaymentOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ordenes.emitir') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'beneficiaryBankAccountId' => ['nullable', 'integer', 'exists:person_bank_accounts,id'],
            /*
             * La foja donde el expediente informa el CBU.
             *
             * **Obligatoria**, y llegó a serlo dos veces. La primera
             * versión la exigía; se relajó porque frenaba la emisión cuando
             * el dato no estaba a mano. Lo que cambió después es cuánto
             * pesa: desde que redacta también el renglón OBS de la Orden
             * (D-003), una emisión sin foja saca **dos** renglones en
             * blanco en vez de uno, y el área pidió volver a exigirla.
             *
             * Es una regla del formulario y no de la base a propósito: las
             * Órdenes emitidas mientras fue opcional tienen la columna nula
             * y son válidas. Un `NOT NULL` las volvería ilegales
             * retroactivamente para describir una política que el área ya
             * cambió de opinión una vez.
             */
            'cbuFolio' => ['required', 'string', 'max:40'],
            'incomeReceiptNumberSource' => ['required', Rule::enum(ReceiptNumberSource::class)],
            'paseDestination' => ['required', 'string', 'max:160'],
            'treasurerId' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'incomeReceiptNumberSource.required' => 'Hay que elegir qué número de recibo se imprime.',
            'paseDestination.required' => 'La nota de Pase necesita un destinatario.',
            'cbuFolio.required' => 'Falta la foja donde el expediente informa el CBU: '
                .'la nota la cita y el renglón OBS de la Orden la imprime.',
        ];
    }
}
