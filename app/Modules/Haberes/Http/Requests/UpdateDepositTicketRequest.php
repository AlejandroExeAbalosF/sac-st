<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Http\Requests\Concerns\ValidatesDepositTicket;
use App\Modules\Haberes\Models\DepositTicket;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La corrección de un comprobante ya cargado.
 *
 * Mismas reglas que el alta, con una diferencia: **el expediente no se
 * cambia**. Corregir un ticket es arreglar lo que se transcribió del
 * papel; si el papel resultó ser de otro expediente, eso no es una
 * corrección sino un ticket distinto, y el que estaba se descarta.
 */
final class UpdateDepositTicketRequest extends FormRequest
{
    use ValidatesDepositTicket;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->depositTicketRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->depositTicketMessages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->depositTicketAttributes();
    }

    /**
     * El expediente sale del ticket, no de lo que llegue en el formulario.
     *
     * Es lo que impide que una corrección mude el comprobante a otro
     * expediente por un campo oculto manipulado, y de paso lo que hace que
     * las reglas de `haberId` e `installmentId` —que se validan contra el
     * expediente— sigan valiendo sin que el formulario tenga que
     * reenviarlo.
     */
    protected function prepareForValidation(): void
    {
        $ticket = $this->route('ticket');

        if ($ticket instanceof DepositTicket) {
            $this->merge(['expedienteId' => $ticket->expediente_id]);
        }
    }
}
