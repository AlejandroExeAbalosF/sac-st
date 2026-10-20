<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de una multa con su expediente, su resolución y sus actas.
 *
 * Los documentos pueden ser del expediente ya cargado (por id) o nuevos (por
 * texto). Lo que exige la base —número y año de una resolución manual, tipo
 * de un acta— lo dice `AttachFineDocument` con el mensaje legible.
 */
final class StoreFineRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = BusinessDate::today()->toDateString();

        return [
            'caseNumber' => ['required', 'string', 'max:60'],
            'caseNotes' => ['nullable', 'string', 'max:500'],

            'resolutionId' => ['nullable', 'integer'],
            'resolution' => ['nullable', 'array'],
            'resolution.text' => ['required_without:resolutionId', 'nullable', 'string', 'max:120'],
            'resolution.issuedOn' => ['required_without:resolutionId', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],

            'actIds' => ['array', 'max:20'],
            'actIds.*' => ['integer', 'distinct'],
            'acts' => ['array', 'max:20'],
            'acts.*.actType' => ['required', 'in:LAB,HYS'],
            'acts.*.text' => ['required', 'string', 'max:120'],
            'acts.*.issuedOn' => ['nullable', new PartialDateRule],

            'caption' => ['required', 'string', 'max:300'],
            'personId' => ['nullable', 'integer', 'exists:people,id'],
            'registeredOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'caseNumber' => 'número de expediente',
            'resolutionId' => 'resolución',
            'resolution.text' => 'resolución',
            'resolution.issuedOn' => 'fecha de la resolución',
            'acts.*.actType' => 'tipo de acta',
            'acts.*.text' => 'acta',
            'acts.*.issuedOn' => 'fecha del acta',
            'caption' => 'carátula',
            'personId' => 'persona',
            'registeredOn' => 'fecha de registro',
            'amount' => 'importe',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $numero = (string) $this->input('caseNumber');

                if ($numero !== '' && ! FineCaseNumber::isValid($numero)) {
                    $validator->errors()->add(
                        'caseNumber',
                        'El número de expediente se escribe como en el papel: 169607/24-0 o 120276/25.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'caseNumber' => trim((string) $this->input('caseNumber')),
            'caption' => trim((string) $this->input('caption')),
        ]);
    }
}
