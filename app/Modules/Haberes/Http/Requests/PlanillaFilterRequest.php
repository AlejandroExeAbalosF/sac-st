<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Support\PlanillaFilters;
use App\Support\Money\Decimal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Valida los mismos criterios para pantalla, PDF y Excel. */
final class PlanillaFilterRequest extends FormRequest
{
    protected $redirectRoute = 'planillas.index';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expediente' => ['nullable', 'string', 'max:120'],
            'beneficiario' => ['nullable', 'string', 'max:120'],
            'empleador' => ['nullable', 'string', 'max:120'],
            'stages' => ['nullable', 'array', 'max:16'],
            'stages.*' => ['string', 'distinct', Rule::in(array_keys(PlanillaFilters::STAGES))],
            'medio' => ['nullable', Rule::in(['cash', 'cheque', 'bank_transfer'])],
            'medioPrevisto' => ['nullable', Rule::in(['cash', 'cheque', 'bank'])],
            'retenido' => ['nullable', 'boolean'],
            'vencimientoDesde' => ['nullable', 'date_format:Y-m-d'],
            'vencimientoHasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:vencimientoDesde'],
            'pagoDesde' => ['nullable', 'date_format:Y-m-d'],
            'pagoHasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:pagoDesde'],
            'importeMin' => ['nullable', $this->importeValido(...)],
            'importeMax' => ['nullable', $this->importeValido(...), $this->importeMaximoValido(...)],
            'page' => ['nullable', 'integer', 'min:1'],
            'formato' => ['nullable', Rule::in(['pdf', 'xlsx'])],
            'descargar' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vencimientoHasta.after_or_equal' => 'El fin del vencimiento no puede ser anterior al inicio.',
            'pagoHasta.after_or_equal' => 'El fin del pago no puede ser anterior al inicio.',
            'stages.*.in' => 'Una de las situaciones elegidas no existe.',
        ];
    }

    public function filters(): PlanillaFilters
    {
        return new PlanillaFilters(
            expediente: $this->texto('expediente'),
            beneficiario: $this->texto('beneficiario'),
            empleador: $this->texto('empleador'),
            stages: array_values($this->validated('stages') ?? []),
            medio: $this->texto('medio'),
            medioPrevisto: $this->texto('medioPrevisto'),
            retenido: $this->boolean('retenido'),
            vencimientoDesde: $this->texto('vencimientoDesde'),
            vencimientoHasta: $this->texto('vencimientoHasta'),
            pagoDesde: $this->texto('pagoDesde'),
            pagoHasta: $this->texto('pagoHasta'),
            importeMin: $this->importe('importeMin'),
            importeMax: $this->importe('importeMax'),
        );
    }

    protected function prepareForValidation(): void
    {
        $data = ['retenido' => $this->boolean('retenido')];

        foreach ([
            'expediente', 'beneficiario', 'empleador', 'medio', 'medioPrevisto',
            'vencimientoDesde', 'vencimientoHasta', 'pagoDesde', 'pagoHasta',
            'importeMin', 'importeMax',
        ] as $field) {
            if (is_string($this->query($field))) {
                $data[$field] = trim((string) $this->query($field));
            }
        }

        $this->merge($data);
    }

    private function texto(string $field): string
    {
        return (string) ($this->validated($field) ?? '');
    }

    private function importe(string $field): string
    {
        $value = $this->texto($field);

        return $value === '' ? '' : (string) Decimal::parse($value);
    }

    private function importeValido(string $attribute, mixed $value, Closure $fail): void
    {
        $parsed = is_string($value) ? Decimal::parse($value) : null;

        if ($parsed === null || Decimal::isNegative($parsed)) {
            $fail('Ingresá un importe válido y no negativo.');
        }
    }

    private function importeMaximoValido(string $attribute, mixed $value, Closure $fail): void
    {
        $min = Decimal::parse((string) $this->query('importeMin'));
        $max = is_string($value) ? Decimal::parse($value) : null;

        if ($min !== null && $max !== null && Decimal::isNegative(Decimal::sub($max, $min))) {
            $fail('El importe máximo no puede ser menor al mínimo.');
        }
    }
}
