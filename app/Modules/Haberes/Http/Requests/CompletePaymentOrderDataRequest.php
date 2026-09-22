<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los huecos del maestro que el modal de la Orden deja completar.
 *
 * Todos opcionales: el modal manda lo que el operador escribió y el Action
 * escribe lo que cambió. Un campo ausente no es un campo a vaciar —el
 * beneficiario puede tener el domicilio cargado y faltarle el teléfono— y
 * exigirlos todos obligaría a reenviar datos que ya estaban bien.
 *
 * Lo que sí es obligatorio para emitir lo decide `PaymentOrderEligibility`,
 * que es quien sabe qué imprime el papel. Acá solo se valida la forma.
 */
final class CompletePaymentOrderDataRequest extends FormRequest
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
            'beneficiaryDocument' => ['nullable', 'string', 'max:20'],
            'beneficiaryAddress' => ['nullable', 'string', 'max:300'],
            'beneficiaryPhone' => ['nullable', 'string', 'max:40'],
            'employerDocument' => ['nullable', 'string', 'max:20'],
            'employerAddress' => ['nullable', 'string', 'max:300'],
            'employerPhone' => ['nullable', 'string', 'max:40'],
            /*
             * La «FECHA INI.» del formulario. No puede ser futura: es
             * cuándo entró el expediente, y un expediente que todavía no
             * llegó no tiene fondos en custodia.
             */
            'custodyStartDate' => ['nullable', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'custodyStartDate.before_or_equal' => 'La fecha de ingreso del expediente no puede ser futura.',
        ];
    }
}
