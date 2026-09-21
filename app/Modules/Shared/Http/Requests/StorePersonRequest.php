<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Http\Requests\Concerns\ValidatesPersonDocuments;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Support\Cbu;
use App\Modules\Shared\Support\Cuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StorePersonRequest extends FormRequest
{
    use ValidatesPersonDocuments;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $esPersona = $this->tipo() === 'individual';

        return [
            // Opcional: el titular de una organización se da de alta sin
            // rol, porque no interviene en el circuito.
            'role' => ['nullable', Rule::in(['employer', 'beneficiary'])],
            'type' => ['required', Rule::in(['individual', 'company'])],
            /*
             * Los dos juegos de nombre se excluyen, y es la misma regla que
             * impone el CHECK de la tabla: una persona física tiene apellido
             * y nombre, una organización tiene razón social. Prohibir el que
             * no corresponde evita que un cambio de tipo a mitad del
             * formulario deje datos colgados en la fila.
             */
            'firstName' => $esPersona ? ['required', 'string', 'min:2', 'max:80'] : ['prohibited'],
            'lastName' => $esPersona ? ['required', 'string', 'min:2', 'max:80'] : ['prohibited'],
            'legalName' => $esPersona ? ['prohibited'] : ['required', 'string', 'min:2', 'max:160'],
            /*
             * Al empleador el documento no se le puede exigir: hay
             * expedientes que traen solo el nombre de la empresa, y no se
             * inventa un CUIT que no está en el papel.
             *
             * Al beneficiario sí. Es quien cobra: sin documento no hay a
             * quién pagarle ni con qué emitir el recibo.
             */
            'document' => [
                $this->input('role') === 'beneficiary' ? 'required' : 'nullable',
                'string',
                $esPersona ? 'between:6,8' : 'size:11',
                'regex:/^\d+$/',
            ],
            // Derivado de `document` en `prepareDocuments`, nunca tipeado.
            'taxIdentifier' => ['nullable', 'string', 'size:11', 'regex:/^\d+$/'],
            ...$this->ownerDocumentRules($esPersona),
            'cbu' => [
                Rule::prohibitedIf($this->input('role') !== 'beneficiary'),
                'nullable',
                'string',
                'size:22',
                'regex:/^\d+$/',
            ],
        ];
    }

    /**
     * Los atributos de la ficha, con el nombre que tienen en la tabla.
     *
     * `name` no figura: la calcula la base a partir de estas tres.
     *
     * @return array{type: string, first_name: string|null, last_name: string|null, legal_name: string|null, document: string|null, tax_identifier: string|null}
     */
    public function personAttributes(): array
    {
        return [
            'type' => (string) $this->validated('type'),
            'first_name' => $this->texto('firstName'),
            'last_name' => $this->texto('lastName'),
            'legal_name' => $this->texto('legalName'),
            'document' => $this->documento(),
            'tax_identifier' => $this->identificadorTributario(),
        ];
    }

    /** El rol que se le otorga, o `null` si esta alta no le da ninguno. */
    public function rol(): ?string
    {
        return $this->texto('role');
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

    /** El documento en dígitos, o `null` si el expediente no lo trajo. */
    public function documento(): ?string
    {
        return $this->texto('document');
    }

    /** El CBU normalizado, o `null` cuando se decidió completarlo después. */
    public function cbu(): ?string
    {
        return $this->texto('cbu');
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            $this->validateBeneficiaryType(...),
            $this->validateCuit(...),
            $this->validateOwner(...),
            $this->validateCbu(...),
            $this->validateNameCollision(...),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...$this->documentAttributes($this->tipo()),
            'cbu' => 'CBU',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->documentMessages(),
            'cbu.size' => 'El CBU debe tener 22 dígitos.',
            'cbu.regex' => 'Ingresá solamente los 22 números del CBU.',
            'cbu.prohibited' => 'El CBU solo puede cargarse para un beneficiario.',
            'document.required' => 'El beneficiario necesita el DNI: es el dato con el que después se le paga.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Se permiten separadores habituales, pero no se borran letras ni
        // otros caracteres: tienen que producir un error visible.
        $cbu = preg_replace('/[\s-]+/', '', trim((string) $this->input('cbu')));

        $this->merge([
            // `null`, no cadena vacía: es lo que hace que `nullable` y
            // `prohibited` distingan "no vino" de "vino mal".
            'firstName' => $this->normalizar('firstName'),
            'lastName' => $this->normalizar('lastName'),
            'legalName' => $this->normalizar('legalName'),
            ...$this->prepareDocuments($this->tipo()),
            ...$this->prepareOwnerDocument(),
            'cbu' => $cbu ?: null,
        ]);
    }

    /** El tipo que se está dando de alta, tal como llegó del formulario. */
    private function tipo(): string
    {
        return (string) $this->input('type');
    }

    private function validateBeneficiaryType(Validator $validator): void
    {
        if ($this->input('role') === 'beneficiary' && $this->tipo() !== 'individual') {
            $validator->errors()->add('type', 'Un beneficiario debe registrarse como persona.');
        }
    }

    /**
     * Sin documento, el nombre es lo único que distingue a una ficha de
     * otra. Si ya hay alguien con ese nombre y sí tiene documento, no se
     * puede decidir por el operador: unificarlas en silencio le atribuiría
     * plata a quien no corresponde, y crear una segunda deja el maestro con
     * dos fichas de lo mismo. El mensaje apunta a la que ya está y ofrece
     * las dos salidas.
     */
    private function validateNameCollision(Validator $validator): void
    {
        if ($this->input('document') !== null) {
            return;
        }

        $gemela = Person::query()
            ->whereNotNull('document')
            ->where('search_name', Person::normalizeName($this->nombre()))
            ->first();

        if ($gemela === null) {
            return;
        }

        $validator->errors()->add(
            $this->tipo() === 'company' ? 'legalName' : 'lastName',
            sprintf(
                'Ya existe «%s» en el maestro, con documento %s. Buscala por nombre y seleccionala; si es otra distinta, cargale su documento para diferenciarlas.',
                $gemela->name,
                $gemela->document,
            ),
        );
    }

    private function validateCuit(Validator $validator): void
    {
        if ($this->tipo() !== 'company') {
            return;
        }

        $cuit = (string) $this->input('document');

        if ($cuit === '') {
            return;
        }

        // Los catálogos históricos pueden contener documentos anteriores a
        // esta validación. Si la ficha ya existe, se reutiliza sin bloquear
        // el trabajo ni alterar silenciosamente el dato original.
        if (Person::query()->where('document', $cuit)->exists()) {
            return;
        }

        $this->addCuitError($validator, $cuit);
    }

    private function validateCbu(Validator $validator): void
    {
        $cbu = $this->input('cbu');

        if (! is_string($cbu) || $cbu === '' || strlen($cbu) !== 22 || ! ctype_digit($cbu)) {
            return;
        }

        if (! Cbu::isValid($cbu)) {
            $validator->errors()->add('cbu', 'El CBU no es válido. Revisá los dígitos ingresados.');
        }
    }
}
