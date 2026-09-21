<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta de un haber dentro de un expediente ya cargado.
 *
 * Las sumas se comparan con bcmath, nunca con punto flotante: `0.1 + 0.2`
 * no da `0.3`, y una validación de importes hecha con float rechazaría
 * cargas correctas o —peor— dejaría pasar diferencias de centavos.
 */
final class StoreHaberRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'beneficiaryId' => ['required', 'integer'],
            'defaultBankAccountId' => ['nullable', 'integer'],
            'assignedAmount' => ['required', 'numeric', 'gt:0'],
            'expectedInstallmentCount' => ['required', 'integer', 'min:1', 'max:60'],
            'concept' => ['required', 'string', 'max:255'],
            'legalDate' => ['nullable', 'date'],
            'resolutionReference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // Al menos una: el expediente llega con la primera cuota ya
            // depositada. Las que todavía no tienen importe no se crean,
            // porque una cuota con importe cero no es un derecho válido.
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.number' => ['required', 'integer', 'min:1'],
            'installments.*.amount' => ['required', 'numeric', 'gt:0'],
            // Contra el catálogo: sin esto, una etiqueta inexistente
            // llega hasta la FK y el operador ve un 500 en vez de un
            // mensaje.
            'installments.*.managementLabelId' => [
                'nullable',
                'integer',
                Rule::exists('haber_management_labels', 'id')->where('is_active', true),
            ],
            'installments.*.concept' => ['nullable', 'string', 'max:255'],
            'installments.*.dueDate' => ['nullable', 'date'],
            /*
             * Previsión, no decisión: el medio real lo fija la primera
             * recepción. Pero declararlo es obligatorio —el área confirmó
             * que al cargar el expediente siempre se sabe—, porque una
             * cuota sin medio no se puede leer: no dice si se cobra por
             * mostrador o si se espera un depósito, y de eso depende todo
             * el circuito que viene después.
             */
            'installments.*.expectedMedium' => [
                'required',
                Rule::enum(ExpectedMedium::class),
            ],
            'installments.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'beneficiaryId' => 'beneficiario',
            'defaultBankAccountId' => 'cuenta bancaria preferida',
            'assignedAmount' => 'importe total reconocido',
            'expectedInstallmentCount' => 'cantidad de cuotas',
            'concept' => 'concepto',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'installments.required' => 'Cargá al menos la primera cuota.',
            'installments.min' => 'Cargá al menos la primera cuota.',
            'installments.*.managementLabelId.exists' => 'Esa etiqueta de gestión no existe o está dada de baja.',
            /*
             * El índice va en el mensaje: con tres cuotas cargadas, «el
             * medio previsto es obligatorio» no dice cuál de las tres.
             */
            'installments.*.expectedMedium.required' => 'Indicá el medio previsto de cada cuota.',
        ];
    }

    /**
     * Los campos opcionales de la cuota llegan como cadena vacía desde el
     * formulario, no como `null`.
     *
     * La diferencia no es cosmética: el Action decide con `??` si la cuota
     * hereda el concepto del haber, y `''` no dispara ese respaldo. Sin
     * esta normalización, dejar el concepto en blanco —que la pantalla
     * anuncia como «el mismo del haber»— guardaba una cuota sin concepto.
     */
    protected function prepareForValidation(): void
    {
        $cuotas = $this->cuotas();

        if ($cuotas === []) {
            return;
        }

        $this->merge([
            'installments' => array_map(
                function (array $cuota): array {
                    foreach (['concept', 'notes', 'dueDate', 'expectedMedium'] as $campo) {
                        if (($cuota[$campo] ?? null) === '') {
                            $cuota[$campo] = null;
                        }
                    }

                    return $cuota;
                },
                $cuotas,
            ),
        ]);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validarBeneficiario(...),
            $this->validarCuentaBancaria(...),
            $this->validarBeneficiarioUnico(...),
            $this->validarSumaDeCuotas(...),
            $this->validarNumerosDeCuota(...),
        ];
    }

    /**
     * Un beneficiario tiene un solo haber dentro de un mismo expediente
     * (DER §9.2, `UNIQUE (expediente_id, beneficiary_id)`). Si hicieran
     * falta dos conceptos para la misma persona, eso son dos cuotas del
     * mismo haber, como en el caso Tinte.
     */
    private function validarBeneficiarioUnico(Validator $validator): void
    {
        $expediente = $this->route('expediente');
        $expedienteId = $expediente instanceof Expediente ? $expediente->id : 0;
        $beneficiarioId = $this->integer('beneficiaryId');

        if ($expedienteId === 0 || $beneficiarioId === 0) {
            return;
        }

        $yaTiene = Haber::query()
            ->where('expediente_id', $expedienteId)
            ->where('beneficiary_id', $beneficiarioId)
            ->exists();

        if ($yaTiene) {
            $validator->errors()->add(
                'beneficiaryId',
                'Este beneficiario ya tiene un haber en el expediente. Si son dos conceptos distintos, cargalos como dos cuotas del mismo haber.',
            );
        }
    }

    private function validarBeneficiario(Validator $validator): void
    {
        $id = $this->integer('beneficiaryId');

        if ($id === 0) {
            return;
        }

        $exists = Person::query()
            ->active()
            ->forRole('beneficiary')
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            $validator->errors()->add('beneficiaryId', 'Elegí un beneficiario registrado y activo.');
        }
    }

    /** La cuenta preferida nunca puede pertenecer a otra persona. */
    private function validarCuentaBancaria(Validator $validator): void
    {
        $cuentaId = $this->integer('defaultBankAccountId');
        $beneficiarioId = $this->integer('beneficiaryId');

        if ($cuentaId === 0 || $beneficiarioId === 0) {
            return;
        }

        $exists = PersonBankAccount::query()
            ->whereKey($cuentaId)
            ->where('person_id', $beneficiarioId)
            ->where('is_active', true)
            ->where('verification_status', '!=', 'rejected')
            ->exists();

        if (! $exists) {
            $validator->errors()->add(
                'defaultBankAccountId',
                'La cuenta elegida no pertenece al beneficiario o ya no está disponible.',
            );
        }
    }

    /**
     * La suma de las cuotas cargadas nunca supera el importe del haber, y
     * tiene que darle exacto cuando ya están las que se preveían.
     */
    private function validarSumaDeCuotas(Validator $validator): void
    {
        $total = (string) $this->input('assignedAmount', '0');

        if (! is_numeric($total)) {
            return;
        }

        $previstas = (int) $this->input('expectedInstallmentCount', 0);
        $cuotas = $this->cuotas();
        $suma = '0';

        foreach ($cuotas as $cuota) {
            $importe = (string) ($cuota['amount'] ?? '0');

            if (is_numeric($importe)) {
                $suma = bcadd($suma, $importe, 2);
            }
        }

        $comparacion = bccomp($suma, bcadd($total, '0', 2), 2);

        if ($comparacion === 1) {
            $validator->errors()->add(
                'installments',
                'La suma de las cuotas supera el importe del haber.',
            );

            return;
        }

        if (count($cuotas) >= $previstas && $comparacion !== 0) {
            $validator->errors()->add(
                'installments',
                'Ya están cargadas todas las cuotas previstas, así que la suma tiene que dar exactamente el importe del haber.',
            );
        }
    }

    /**
     * Los números de cuota no se repiten ni exceden lo previsto
     * (`UNIQUE (haber_id, installment_number)`).
     */
    private function validarNumerosDeCuota(Validator $validator): void
    {
        $previstas = (int) $this->input('expectedInstallmentCount', 0);
        $vistos = [];

        foreach ($this->cuotas() as $i => $cuota) {
            $numero = (int) ($cuota['number'] ?? 0);

            if (isset($vistos[$numero])) {
                $validator->errors()->add(
                    "installments.{$i}.number",
                    'Hay dos cuotas con el mismo número.',
                );
            }

            if ($previstas > 0 && $numero > $previstas) {
                $validator->errors()->add(
                    "installments.{$i}.number",
                    "El haber prevé {$previstas} cuotas.",
                );
            }

            $vistos[$numero] = true;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cuotas(): array
    {
        $cuotas = $this->input('installments');

        return is_array($cuotas) ? $cuotas : [];
    }
}
