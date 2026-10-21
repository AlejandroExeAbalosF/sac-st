<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El ticket del cajero, transcripto, con su foto.
 *
 * La cuenta no se elige: es la del libro de la caja de Multas. La foto es
 * obligatoria, como en Haberes: es la única prueba de que la plata salió.
 */
final class DepositFineCollectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'depositDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'depositTime' => ['nullable', 'date_format:H:i'],
            'operationNumber' => ['nullable', 'string', 'max:40'],
            'terminal' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ticket' => ScannedDocument::rules(required: true),
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'desde' => ['nullable', 'in:caja,multa'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ticket.required' => 'El ticket del cajero es obligatorio: es la prueba de que la plata salió de la caja.',
            'depositDate.before_or_equal' => 'La fecha del depósito no puede ser futura.',
            ...ScannedDocument::messages('ticket'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
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
