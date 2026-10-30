<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Ledger\Models\CashBookOpening;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y corrección de una cuenta del organismo.
 *
 * El número de cuenta importa más de lo que parece: es lo que se compara
 * contra la cabecera del extracto para rechazar el archivo equivocado.
 * Cargarlo mal no rompe nada hoy y desactiva ese control para siempre.
 */
final class SaveBankAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $id = $this->route('account');
        $accountId = is_object($id) && method_exists($id, 'getKey') ? $id->getKey() : $id;

        return [
            'label' => ['required', 'string', 'max:120'],
            'bankName' => ['required', 'string', 'max:120'],
            'accountNumber' => [
                'nullable', 'string', 'max:40',
                Rule::unique('bank_accounts', 'account_number')
                    ->where('bank_name', (string) $this->input('bankName'))
                    ->ignore($accountId),
            ],
            'cbu' => [
                'nullable', 'string', 'size:22', 'regex:/^\d{22}$/',
                Rule::unique('bank_accounts', 'cbu')->ignore($accountId),
            ],
            'alias' => ['nullable', 'string', 'max:80'],
            // Cómo la escribe el área en sus notas: la rendición de Multas la cita así.
            'documentCitation' => ['nullable', 'string', 'max:120'],
            'currency' => [
                'required', 'string', Rule::in(['ARS', 'USD']),
                $this->currencyIsFrozen(...),
            ],
            'isActive' => ['required', 'boolean'],
        ];
    }

    /**
     * La moneda se congela apenas la cuenta tiene movimientos, un vínculo con
     * una caja o una apertura de libro.
     *
     * Los movimientos no llevan moneda propia: la heredan de la cuenta.
     * Cambiarla acá reinterpretaría todos los importes ya
     * importados. La base lo impide con un trigger; esto existe para que
     * el operador reciba una explicación en vez de una excepción.
     *
     * @param  Closure(string): void  $fail
     */
    private function currencyIsFrozen(string $attribute, mixed $value, Closure $fail): void
    {
        $account = $this->route('account');

        if (! $account instanceof BankAccount || $account->currency === $value) {
            return;
        }

        if ($account->transactions()->exists()) {
            $fail(
                'La cuenta ya tiene movimientos importados. Cambiar su moneda ahora haría que '
                .'esos importes se lean en la moneda nueva. Si la cuenta se cargó con la moneda '
                .'equivocada, revertí sus importaciones antes.'
            );

            return;
        }

        // El vínculo con una caja y la apertura de un libro tomaron la moneda de la cuenta.
        if (CashBoxBankAccount::query()->where('bank_account_id', $account->id)->exists()
            || CashBookOpening::query()->where('bank_account_id', $account->id)->exists()) {
            $fail(
                'La cuenta está vinculada a una caja en '.$account->currency.'. Cambiar su moneda '
                .'contradiría ese vínculo: si se cargó con la moneda equivocada, cargá la cuenta '
                .'correcta y habilitala en la caja.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cbu.size' => 'El CBU tiene 22 dígitos.',
            'cbu.regex' => 'El CBU son 22 dígitos, sin espacios ni guiones.',
            'cbu.unique' => 'Ya hay una cuenta registrada con ese CBU.',
            'accountNumber.unique' => 'Ese banco ya tiene una cuenta con ese número.',
            'currency.in' => 'El sistema opera en pesos y en dólares.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'label' => 'nombre',
            'bankName' => 'banco',
            'accountNumber' => 'número de cuenta',
            'currency' => 'moneda',
        ];
    }
}
