<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Ledger\Http\Requests\RegisterOpeningBalanceRequest;
use App\Modules\Multas\Actions\OpenFineCashBook;
use Illuminate\Validation\Rule;

/**
 * La apertura de la caja de Multas: en pesos, con su efectivo y sus
 * cheques, y la cuenta bancaria con la que opera.
 *
 * El saldo de esa cuenta no viaja: es compartido con Aranceles y no se
 * reparte (M08, M12). Lee los importes igual que la apertura de Haberes.
 */
final class OpenFineCashBookRequest extends RegisterOpeningBalanceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reglas = parent::rules();

        unset($reglas['cashBoxId'], $reglas['currency'], $reglas['cheques.*.beneficiary']);

        $reglas['balances'] = ['present', 'array:'.implode(',', OpenFineCashBook::ACCOUNTS)];
        $reglas['bankAccountId'] = [
            'required',
            'integer',
            Rule::exists('bank_accounts', 'id')->where('is_active', true)->where('currency', 'ARS'),
        ];

        return $reglas;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'balances.array' => 'La caja de Multas abre con su efectivo y sus cheques. El saldo de la cuenta bancaria es compartido con Aranceles y no se declara al abrir.',
            'bankAccountId.required' => 'Elegí la cuenta bancaria con la que opera la caja de Multas.',
            'bankAccountId.exists' => 'La cuenta tiene que estar activa y ser en pesos.',
        ];
    }
}
