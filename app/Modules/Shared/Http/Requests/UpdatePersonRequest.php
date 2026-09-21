<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Http\Requests\Concerns\ValidatesPersonDocuments;
use App\Modules\Shared\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Corrección de una ficha del maestro.
 *
 * El tipo no está: una persona con roles queda atada a su tipo por la FK
 * compuesta de `person_roles`, y convertir una organización en persona
 * física no es una corrección sino decir que la ficha estaba mal desde el
 * principio. Como el tipo lo manda la ficha y no el formulario, los campos
 * que se piden salen de ella.
 *
 * El documento tampoco se puede quitar si la persona es beneficiaria de
 * alguien: es con lo que se le paga.
 */
final class UpdatePersonRequest extends FormRequest
{
    use ValidatesPersonDocuments;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $esPersona = $this->tipo() === 'individual';

        return [
            'firstName' => $esPersona ? ['required', 'string', 'min:2', 'max:80'] : ['prohibited'],
            'lastName' => $esPersona ? ['required', 'string', 'min:2', 'max:80'] : ['prohibited'],
            'legalName' => $esPersona ? ['prohibited'] : ['required', 'string', 'min:2', 'max:160'],
            'document' => [
                $this->esBeneficiaria() ? 'required' : 'nullable',
                'string',
                $esPersona ? 'between:6,8' : 'size:11',
                'regex:/^\d+$/',
            ],
            // Derivado de `document` en `prepareDocuments`, nunca tipeado.
            'taxIdentifier' => ['nullable', 'string', 'size:11', 'regex:/^\d+$/'],
            ...$this->ownerPersonIdRules($esPersona),
            'isActive' => ['required', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validarCuit(...),
            $this->validarDocumentoLibre(...),
            $this->validarNombreLibre(...),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->documentAttributes($this->tipo());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->documentMessages(),
            'document.required' => 'Es beneficiaria de un haber, así que no puede quedarse sin documento: es con lo que se le paga.',
        ];
    }

    public function persona(): Person
    {
        $persona = $this->route('person');

        return $persona instanceof Person ? $persona : new Person;
    }

    /**
     * Los atributos corregidos, con el nombre que tienen en la tabla.
     *
     * @return array{first_name: string|null, last_name: string|null, legal_name: string|null, owner_person_id: int|null, document: string|null, tax_identifier: string|null, is_active: bool}
     */
    public function personAttributes(): array
    {
        return [
            'first_name' => $this->texto('firstName'),
            'last_name' => $this->texto('lastName'),
            'legal_name' => $this->texto('legalName'),
            ...$this->ownerAttributes(),
            'document' => $this->documento(),
            'tax_identifier' => $this->identificadorTributarioAGuardar(),
            'is_active' => $this->boolean('isActive'),
        ];
    }

    /**
     * El CUIL que queda guardado después de la corrección.
     *
     * Si esta carga trajo el número entero, manda ese. Si trajo solo el DNI
     * y el DNI no cambió, el que ya estaba sigue siendo el suyo: borrarlo
     * perdería el prefijo, que es lo único que no se puede volver a
     * deducir. Si el DNI cambió, el CUIL viejo ya no le corresponde a nadie.
     */
    private function identificadorTributarioAGuardar(): ?string
    {
        $nuevo = $this->identificadorTributario();

        if ($nuevo !== null) {
            return $nuevo;
        }

        return $this->documento() === $this->persona()->document
            ? $this->persona()->tax_identifier
            : null;
    }

    /** El nombre para mostrar, tal como lo va a armar la base. */
    public function nombre(): string
    {
        return Person::composeName(
            $this->tipo(),
            $this->texto('firstName'),
            $this->texto('lastName'),
            $this->texto('legalName'),
        );
    }

    /** El documento en dígitos, o `null` si se dejó vacío. */
    public function documento(): ?string
    {
        return $this->texto('document');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'firstName' => $this->normalizar('firstName'),
            'lastName' => $this->normalizar('lastName'),
            'legalName' => $this->normalizar('legalName'),
            ...$this->prepareDocuments($this->tipo()),
            'isActive' => $this->boolean('isActive'),
        ]);
    }

    /** El tipo lo manda la ficha, no el formulario: no se puede cambiar. */
    private function tipo(): string
    {
        $persona = $this->route('person');

        return $persona instanceof Person ? $persona->type : 'individual';
    }

    private function esBeneficiaria(): bool
    {
        return $this->persona()->roles->contains('role', 'beneficiary');
    }

    /**
     * Un CUIT corregido a mano tiene que seguir siendo un CUIT de una
     * organización. La ficha que ya lo traía mal desde una importación se
     * deja pasar: cambiarlo en silencio seria peor que conservarlo.
     */
    private function validarCuit(Validator $validator): void
    {
        $cuit = (string) $this->input('document');

        if ($this->tipo() !== 'company' || $cuit === '') {
            return;
        }

        if ($cuit === $this->persona()->document) {
            return;
        }

        $this->addCuitError($validator, $cuit);
    }

    private function validarDocumentoLibre(Validator $validator): void
    {
        $documento = $this->input('document');

        if ($documento === null) {
            return;
        }

        $ocupado = Person::query()
            ->where('document', $documento)
            ->whereKeyNot($this->persona()->id)
            ->exists();

        if ($ocupado) {
            $validator->errors()->add('document', 'Ese documento ya pertenece a otra ficha del maestro.');
        }
    }

    /**
     * Sin documento, el nombre es lo único que distingue una ficha de otra,
     * y hay un índice parcial que lo impone. El mensaje llega antes que el
     * error de la base.
     */
    private function validarNombreLibre(Validator $validator): void
    {
        if ($this->input('document') !== null) {
            return;
        }

        $ocupado = Person::query()
            ->whereNull('document')
            ->where('search_name', Person::normalizeName($this->nombre()))
            ->whereKeyNot($this->persona()->id)
            ->exists();

        if ($ocupado) {
            $validator->errors()->add(
                $this->tipo() === 'company' ? 'legalName' : 'lastName',
                'Ya existe otra ficha sin documento con ese mismo nombre. Cargale el documento a alguna de las dos para diferenciarlas.',
            );
        }
    }
}
