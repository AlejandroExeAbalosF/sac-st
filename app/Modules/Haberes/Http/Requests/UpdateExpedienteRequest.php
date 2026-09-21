<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Shared\Models\Person;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Corrección de la ficha del expediente.
 *
 * Las mismas reglas que el alta, menos el número: ese identifica al
 * expediente y no se corrige, se anula y se carga el correcto.
 */
final class UpdateExpedienteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:255'],
            'receivedDate' => ['required', 'date', 'before_or_equal:today'],
            'employerId' => ['required', 'integer'],
            'employerRepresentative' => ['nullable', 'string', 'max:160'],
            'declaredTotalAmount' => ['nullable', 'numeric', 'gt:0'],
            'externalReference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [$this->validarEmpleador(...)];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subject' => 'carátula',
            'receivedDate' => 'fecha de recepción',
            'employerId' => 'empleador',
            'employerRepresentative' => 'representante',
            'declaredTotalAmount' => 'total declarado',
        ];
    }

    private function validarEmpleador(Validator $validator): void
    {
        $id = $this->integer('employerId');

        if ($id === 0) {
            return;
        }

        $existe = Person::query()
            ->active()
            ->forRole('employer')
            ->whereKey($id)
            ->exists();

        if (! $existe) {
            $validator->errors()->add('employerId', 'Elegí un empleador registrado y activo.');
        }
    }
}
