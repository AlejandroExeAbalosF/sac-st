<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests\Concerns;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Haberes\Support\HaberesCashBox;
use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Las reglas del comprobante, compartidas por el alta y la corrección.
 *
 * Van en un trait y no en una clase base porque las dos requests son
 * `final`, que es la convención del proyecto. Duplicarlas era la otra
 * salida y es peor: son las reglas que deciden si un ticket va a poder
 * cruzarse contra el extracto, y dos copias divergen.
 */
trait ValidatesDepositTicket
{
    /**
     * Con cuentas habilitadas para la caja de Haberes, la del ticket tiene que
     * ser una de ellas y valer el día del depósito. La base lo repite al
     * identificar el crédito (`bank_allocation_follows_box_accounts`).
     *
     * Lo que decide es la vigencia en la caja, no `is_active`: eso dice si la
     * cuenta recibe extractos nuevos, y un comprobante viejo de una cuenta
     * que ya no los recibe se sigue registrando. Es el mismo criterio con que
     * el formulario arma la lista. Sin cuentas habilitadas, la caja no se
     * controla y vale la regla de siempre: una cuenta activa.
     *
     * @param  Closure(string): void  $fail
     */
    private function accountServesTheBox(string $attribute, mixed $value, Closure $fail): void
    {
        $caja = app(HaberesCashBox::class)->id();
        $cuentas = app(CashBoxBankAccounts::class);
        $fecha = $this->input('depositedAt');

        if (! is_numeric($value)) {
            return;
        }

        if (! $cuentas->hasAny($caja)) {
            if (! BankAccount::query()->whereKey((int) $value)->where('is_active', true)->exists()) {
                $fail('Esa cuenta no está activa.');
            }

            return;
        }

        if (! is_string($fecha)) {
            return;
        }

        try {
            $dia = CarbonImmutable::parse($fecha);
        } catch (Throwable) {
            return;
        }

        if (! $cuentas->covers($caja, (int) $value, $dia)) {
            $fail('Esa cuenta no está habilitada para la caja de Haberes en la fecha del depósito.');
        }
    }

    /**
     * Lo que el operador copia mirando el papel.
     *
     * Es todo lo que pide el alta: a qué cuota apunta el comprobante, por
     * cuánto y de qué tipo los arma el servidor desde la cuota de la
     * dirección, así que no hay campo que pueda contradecirlos.
     *
     * @return array<string, mixed>
     */
    protected function depositTicketPaperRules(): array
    {
        return [
            'bankAccountId' => [
                'required', 'integer',
                Rule::exists('bank_accounts', 'id'),
                $this->accountServesTheBox(...),
            ],
            /*
             * No se admite una fecha futura: un ticket es el comprobante de
             * algo que ya pasó. Y `after` acota la carga de datos viejos a
             * un rango razonable sin impedir la migración de un expediente
             * de hace años.
             */
            'depositedAt' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString(), 'after:2015-01-01'],
            'depositedTime' => ['nullable', 'date_format:H:i'],
            'operationNumber' => ['nullable', 'string', 'max:40'],
            'terminal' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'photo' => ScannedDocument::rules(required: false),
        ];
    }

    /**
     * Y lo que solo la corrección puede tocar.
     *
     * El alta los deriva de la cuota, pero derivarlos no es adivinarlos
     * bien siempre: el papel puede decir «Transferencia» donde el medio
     * decía depósito, o traer unos pesos de diferencia. Esta es la puerta
     * por donde eso se arregla, y por eso acá siguen siendo campos.
     *
     * @return array<string, mixed>
     */
    protected function depositTicketRules(): array
    {
        return [
            ...$this->depositTicketPaperRules(),
            'expedienteId' => ['required', 'integer', Rule::exists('expedientes', 'id')],
            'haberId' => [
                'nullable', 'integer',
                Rule::exists('haberes', 'id')->where('expediente_id', $this->input('expedienteId')),
            ],
            'installmentId' => [
                'nullable', 'integer',
                Rule::exists('beneficiary_installments', 'id')->where('haber_id', $this->input('haberId')),
            ],
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'depositKind' => ['required', Rule::in(['cash_deposit', 'transfer', 'cheque_deposit'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function depositTicketMessages(): array
    {
        return [
            'depositedAt.before_or_equal' => 'La fecha del depósito no puede ser futura.',
            'amount.regex' => 'El importe va con punto decimal y hasta dos decimales.',
            'installmentId.exists' => 'Esa cuota no pertenece al haber elegido.',
            'haberId.exists' => 'Ese haber no pertenece al expediente.',
            ...ScannedDocument::messages('photo'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function depositTicketAttributes(): array
    {
        return [
            'depositedAt' => 'fecha del depósito',
            'amount' => 'importe',
            'depositKind' => 'tipo de depósito',
            'bankAccountId' => 'cuenta',
            'photo' => 'comprobante',
        ];
    }
}
