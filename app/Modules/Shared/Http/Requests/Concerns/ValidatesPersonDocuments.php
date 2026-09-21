<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests\Concerns;

use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Support\Cuit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Lo que el alta y la corrección de una ficha hacen igual.
 *
 * Son dos vías que llegan al mismo dato, y una regla duplicada es una regla
 * que en algún momento diverge. Acá viven la normalización de los campos de
 * texto, el reparto entre DNI y CUIT, y los campos del titular.
 *
 * @phpstan-require-extends FormRequest
 */
trait ValidatesPersonDocuments
{
    /**
     * Reglas del titular de una organización.
     *
     * Es una ficha del maestro, no un nombre copiado: quien está al frente
     * de una empresa puede ser además beneficiario de otro expediente, y dos
     * copias del mismo humano se desfasan en cuanto alguien corrige una.
     *
     * @return array<string, list<mixed>>
     */
    protected function ownerPersonIdRules(bool $esPersona): array
    {
        if ($esPersona) {
            return ['ownerPersonId' => ['prohibited']];
        }

        return [
            'ownerPersonId' => [
                'nullable',
                'integer',
                Rule::exists('people', 'id')->where('type', 'individual'),
            ],
        ];
    }

    /**
     * @return array{owner_person_id: int|null}
     */
    protected function ownerAttributes(): array
    {
        $titular = $this->validated('ownerPersonId');

        return ['owner_person_id' => is_numeric($titular) ? (int) $titular : null];
    }

    /**
     * Reglas del titular cuando se lo identifica por su documento.
     *
     * Es la vía del alta contextual: el operador tiene el papel delante y
     * tipea el número, no busca por nombre. El apellido y el nombre solo
     * hacen falta cuando ese documento todavía no está en el maestro.
     *
     * @return array<string, list<string>>
     */
    protected function ownerDocumentRules(bool $esPersona): array
    {
        if ($esPersona) {
            return [
                'ownerDocument' => ['prohibited'],
                'ownerFirstName' => ['prohibited'],
                'ownerLastName' => ['prohibited'],
            ];
        }

        return [
            'ownerDocument' => ['nullable', 'string', 'between:6,8', 'regex:/^\d+$/'],
            'ownerTaxIdentifier' => ['nullable', 'string', 'size:11', 'regex:/^\d+$/'],
            'ownerFirstName' => ['nullable', 'string', 'min:2', 'max:80', 'required_with:ownerLastName'],
            'ownerLastName' => ['nullable', 'string', 'min:2', 'max:80', 'required_with:ownerFirstName'],
        ];
    }

    /**
     * Los campos del titular, normalizados igual que los de cualquier persona.
     *
     * @return array<string, string|null>
     */
    protected function prepareOwnerDocument(): array
    {
        $documento = $this->digitos('ownerDocument');
        $identificador = null;

        if ($documento !== null) {
            $dni = Cuit::toDocumentNumber($documento);

            if ($dni !== null) {
                $identificador = $documento;
                $documento = $dni;
            }
        }

        return [
            'ownerDocument' => $documento,
            // Derivado, nunca tipeado: lo que venga del cliente se pisa.
            'ownerTaxIdentifier' => $identificador,
            'ownerFirstName' => $this->normalizar('ownerFirstName'),
            'ownerLastName' => $this->normalizar('ownerLastName'),
        ];
    }

    /**
     * Al titular hay que poder encontrarlo o registrarlo, no las dos a medias.
     *
     * Un nombre sin documento no alcanza: es justamente lo que permitiría
     * registrar dos veces a la misma persona. Y un documento que no está en
     * el maestro necesita el nombre, porque va a dar de alta una ficha.
     */
    protected function validateOwner(Validator $validator): void
    {
        $documento = $this->input('ownerDocument');

        if ($documento === null) {
            if ($this->input('ownerLastName') !== null) {
                $validator->errors()->add(
                    'ownerDocument',
                    'Cargá el DNI del titular: es con lo que se evita registrarlo dos veces.',
                );
            }

            return;
        }

        $existe = Person::query()
            ->where('type', 'individual')
            ->where('document', $documento)
            ->exists();

        if (! $existe && $this->input('ownerLastName') === null) {
            $validator->errors()->add(
                'ownerLastName',
                'Ese documento no está en el maestro. Cargá el apellido y el nombre para registrarlo.',
            );
        }
    }

    /** El DNI del titular, o `null` si el expediente no lo trajo. */
    public function documentoDelTitular(): ?string
    {
        return $this->texto('ownerDocument');
    }

    /** Su CUIL, cuando lo que se tipeó fue el número entero. */
    public function identificadorTributarioDelTitular(): ?string
    {
        return $this->texto('ownerTaxIdentifier');
    }

    public function nombreDelTitular(): ?string
    {
        return $this->texto('ownerFirstName');
    }

    public function apellidoDelTitular(): ?string
    {
        return $this->texto('ownerLastName');
    }

    /**
     * Los campos ya normalizados, listos para validarse.
     *
     * @return array<string, string|null>
     */
    protected function prepareDocuments(string $tipo): array
    {
        $documento = $this->digitos('document');
        $identificador = null;

        /*
         * El expediente muchas veces trae el CUIL y no el DNI. Hasta ahora
         * el operador tenía que sacarle el prefijo y el verificador a mano:
         * una cuenta mental por cada carga, y una que el sistema no puede
         * auditar, porque ocho dígitos mal copiados siguen siendo un DNI
         * válido. Si lo que llegó es un CUIL que cierra, se guardan los
         * dos: el DNI, que es la identidad, y el número entero, porque el
         * prefijo no se puede reconstruir a partir del DNI. Si no cierra,
         * queda como vino y la validación lo dice con todas las letras.
         */
        if ($tipo === 'individual' && $documento !== null) {
            $dni = Cuit::toDocumentNumber($documento);

            if ($dni !== null) {
                $identificador = $documento;
                $documento = $dni;
            }
        }

        return [
            'document' => $documento,
            // Derivado, nunca tipeado: lo que venga del cliente se pisa.
            'taxIdentifier' => $identificador,
            'ownerPersonId' => $this->input('ownerPersonId') ?: null,
        ];
    }

    /**
     * El CUIL, cuando lo que se tipeó fue el número entero.
     *
     * `null` no significa «no tiene»: significa que esta carga trajo solo
     * el DNI. Quien escribe decide qué hacer con eso —el alta lo deja
     * vacío, la corrección conserva el que ya estaba si sigue coincidiendo.
     */
    public function identificadorTributario(): ?string
    {
        return $this->texto('taxIdentifier');
    }

    /**
     * @return array<string, string>
     */
    protected function documentAttributes(string $tipo): array
    {
        return [
            'firstName' => 'nombre',
            'lastName' => 'apellido',
            'legalName' => 'razón social',
            'document' => $tipo === 'company' ? 'CUIT' : 'DNI',
            'ownerPersonId' => 'titular',
            'ownerDocument' => 'DNI del titular',
            'ownerFirstName' => 'nombre del titular',
            'ownerLastName' => 'apellido del titular',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function documentMessages(): array
    {
        return [
            'document.size' => 'El CUIT debe tener 11 dígitos.',
            'document.between' => $this->mensajeDeDni('document'),
            'document.regex' => 'Ingresá solamente números.',
            'firstName.prohibited' => 'Una organización no lleva nombre de pila.',
            'lastName.prohibited' => 'Una organización no lleva apellido.',
            'legalName.prohibited' => 'Una persona física no lleva razón social.',
            'ownerDocument.between' => $this->mensajeDeDni('ownerDocument'),
            'ownerDocument.regex' => 'Ingresá solamente números.',
            'ownerPersonId.prohibited' => 'El titular es un dato de las organizaciones.',
            'ownerDocument.prohibited' => 'El titular es un dato de las organizaciones.',
            'ownerFirstName.prohibited' => 'El titular es un dato de las organizaciones.',
            'ownerLastName.prohibited' => 'El titular es un dato de las organizaciones.',
            'ownerFirstName.required_with' => 'Falta el nombre del titular.',
            'ownerLastName.required_with' => 'Falta el apellido del titular.',
            'ownerPersonId.exists' => 'Esa persona no está en el maestro, o no es una persona física.',
        ];
    }

    /**
     * El CUIT de una organización tiene que ser de una organización.
     *
     * Con el verificador solo no alcanzaba: `20-29939415-9` es aritmética
     * impecable y es el CUIL de un humano. Sin esta comprobación, un
     * organismo podía quedar registrado en el maestro con el número de una
     * persona física.
     */
    protected function addCuitError(Validator $validator, string $cuit): void
    {
        if (! Cuit::isValid($cuit)) {
            $validator->errors()->add('document', 'El CUIT no es válido. Revisá los dígitos.');

            return;
        }

        if (Cuit::isOrganization($cuit)) {
            return;
        }

        $validator->errors()->add('document', Cuit::isIndividual($cuit)
            ? 'Ese número es el CUIL de una persona física, no el CUIT de una organización. Si el empleador es una persona, cargalo con el tipo «Persona».'
            : 'Ese CUIT no corresponde a una organización: el de una empresa u organismo empieza en 30, 33 o 34.');
    }

    /** El campo con los espacios de más ya colapsados, o `null` si vino vacío. */
    protected function normalizar(string $campo): ?string
    {
        $valor = preg_replace('/\s+/u', ' ', trim((string) $this->input($campo)));

        return $valor === '' || $valor === null ? null : $valor;
    }

    /** Solo los dígitos del campo, o `null` si no quedó ninguno. */
    protected function digitos(string $campo): ?string
    {
        $valor = preg_replace('/\D+/', '', (string) $this->input($campo));

        return $valor === '' || $valor === null ? null : $valor;
    }

    /** El valor validado, o `null` cuando vino vacío. */
    protected function texto(string $campo): ?string
    {
        $valor = $this->validated($campo);

        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    /**
     * Por qué ese número no sirve como DNI.
     *
     * Si llegó acá con once dígitos es porque no era un CUIL que cierra
     * —esos ya se convirtieron en `prepareDocuments`—, y decirle «el DNI
     * debe tener entre 6 y 8 dígitos» a quien copió un CUIL del expediente
     * no le dice nada de lo que pasó.
     */
    private function mensajeDeDni(string $campo): string
    {
        $valor = (string) $this->input($campo);

        if (strlen($valor) !== 11) {
            return 'El DNI debe tener entre 6 y 8 dígitos.';
        }

        if (Cuit::isOrganization($valor)) {
            return 'Ese es el CUIT de una organización, no el número de una persona.';
        }

        return 'Ese CUIL no es válido: el dígito verificador no cierra. Revisá los dígitos, o cargá directamente el DNI.';
    }
}
