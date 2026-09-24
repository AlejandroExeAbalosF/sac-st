<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests\Concerns;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Validation\Rule;

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
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
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
