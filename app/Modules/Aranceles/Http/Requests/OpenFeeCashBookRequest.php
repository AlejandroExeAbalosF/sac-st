<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Ledger\Http\Requests\RegisterOpeningBalanceRequest;
use Illuminate\Validation\Rule;

/**
 * La apertura de la caja de Aranceles: en pesos, solo con su efectivo y la cuenta
 * con la que opera. El saldo de esa cuenta es compartido con Multas.
 */
final class OpenFeeCashBookRequest extends RegisterOpeningBalanceRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $reglas = parent::rules();

        unset($reglas['cashBoxId'], $reglas['currency'], $reglas['cheques'], $reglas['cheques.*.number'], $reglas['cheques.*.bank'], $reglas['cheques.*.issueDate'], $reglas['cheques.*.amount'], $reglas['cheques.*.expediente'], $reglas['cheques.*.company'], $reglas['cheques.*.beneficiary']);

        $reglas['balances'] = ['present', 'array:'.implode(',', OpenFeeCashBook::ACCOUNTS)];
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
            'balances.array' => 'La caja de Aranceles abre solo con su efectivo. El saldo de la cuenta es compartido con Multas y no se declara al abrir.',
            'bankAccountId.required' => 'Elegí la cuenta bancaria con la que opera la caja de Aranceles.',
            'bankAccountId.exists' => 'La cuenta tiene que estar activa y ser en pesos.',
        ];
    }
}
