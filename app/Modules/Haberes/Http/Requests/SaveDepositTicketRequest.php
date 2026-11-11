<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Http\Requests\Concerns\ValidatesDepositTicket;
use App\Modules\Haberes\Support\ExcessFromRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Los datos que el operador copia del ticket.
 *
 * **La foto no es obligatoria:** a veces el papel se traspapela y el dato
 * hay que cargarlo igual. Se advierte que falta en
 * lugar de trabar la carga, como con la continuidad de saldos.
 *
 * La fecha sí lo es: junto con el importe es la señal con la que después
 * se busca el movimiento en el extracto. Sin ella el ticket no sirve para
 * lo único que existe.
 *
 * **A qué cuota apunta y de qué tipo no se validan porque no se reciben.**
 * Los arma el controlador desde la cuota de la dirección. El importe
 * también sale de ella, salvo que el papel traiga más de lo que se debe:
 * entonces llega el total con su motivo (`ExcessFromRequest`) y se
 * registra como importe mayor en el mismo acto.
 */
final class SaveDepositTicketRequest extends FormRequest
{
    use ValidatesDepositTicket;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [...$this->depositTicketPaperRules(), ...ExcessFromRequest::rules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->depositTicketMessages(), ...ExcessFromRequest::messages()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->depositTicketAttributes();
    }
}
