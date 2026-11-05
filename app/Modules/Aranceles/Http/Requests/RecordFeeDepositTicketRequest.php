<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/** El comprobante del banco, transcripto, con su foto. */
final class RecordFeeDepositTicketRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'depositDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'depositTime' => ['nullable', 'date_format:H:i'],
            'operationNumber' => ['nullable', 'string', 'max:40'],
            'terminal' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ticket' => ScannedDocument::rules(required: true),
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ticket.required' => 'La foto del comprobante es obligatoria: es la prueba del depósito.',
            'depositDate.before_or_equal' => 'La fecha del depósito no puede ser futura.',
            ...ScannedDocument::messages('ticket'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => 'importe',
            'depositDate' => 'fecha del depósito',
            'depositTime' => 'hora',
            'operationNumber' => 'número de operación',
            'terminal' => 'terminal',
            'notes' => 'observaciones',
        ];
    }

    /**
     * @return array{depositDate: CarbonImmutable, depositTime: string|null, operationNumber: string|null, terminal: string|null, notes: string|null}
     */
    public function ticket(): array
    {
        $datos = $this->validated();

        return [
            'depositDate' => CarbonImmutable::parse((string) $datos['depositDate']),
            'depositTime' => isset($datos['depositTime']) ? $datos['depositTime'].':00' : null,
            'operationNumber' => $datos['operationNumber'] ?? null,
            'terminal' => $datos['terminal'] ?? null,
            'notes' => $datos['notes'] ?? null,
        ];
    }
}
