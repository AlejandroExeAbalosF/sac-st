<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use App\Modules\Multas\Support\FineCaseNumber;
use App\Modules\Multas\Support\TwoDigitYear;
use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta de una multa con su expediente, su resolución y sus actas.
 *
 * Los documentos pueden ser del expediente ya cargado (por id) o nuevos (por
 * texto), y cada papel puede traer su foto o su PDF. Lo que exige la base —número y año de una resolución manual, tipo
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
            // Desde la ficha del expediente llega el id; desde Nueva multa, el número.
            'fineCaseId' => ['nullable', 'integer', 'exists:fine_cases,id'],
            'caseNumber' => ['required_without:fineCaseId', 'nullable', 'string', 'max:60'],
            'caseNotes' => ['nullable', 'string', 'max:500'],

            'resolutionId' => ['nullable', 'integer'],
            'resolution' => ['nullable', 'array'],
            'resolution.text' => ['required_without:resolutionId', 'nullable', 'string', 'max:120'],
            'resolution.issuedOn' => ['required_without:resolutionId', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$hoy],

            /*
             * Los documentos fijos del «+» de la ficha: si vienen, la multa se
             * carga con exactamente esos (ver `respetaLosDocumentosFijos`).
             */
            'fixedDocuments' => ['nullable', 'array'],
            'fixedDocuments.resolutionId' => ['nullable', 'integer'],
            'fixedDocuments.actIds' => ['array', 'max:20'],
            'fixedDocuments.actIds.*' => ['integer'],

            'actIds' => ['array', 'max:20'],
            'actIds.*' => ['integer', 'distinct'],
            'acts' => ['array', 'max:20'],
            'acts.*.actType' => ['required', 'in:LAB,HYS'],
            'acts.*.text' => ['required', 'string', 'max:120'],
            'acts.*.issuedOn' => ['nullable', new PartialDateRule],

            // El archivo de cada papel, opcional (M11): se completa cuando aparece.
            'caseFile' => ScannedDocument::rules(required: false),
            'resolutionFile' => ScannedDocument::rules(required: false),
            'acts.*.file' => ScannedDocument::rules(required: false),

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
            'fineCaseId' => 'expediente',
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
            'caseFile' => 'archivo del expediente',
            'resolutionFile' => 'archivo de la resolución',
            'acts.*.file' => 'archivo del acta',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            // Sin resolución elegida ni escrita: se dice qué hacer, no qué campo falta.
            'caseNumber.required_without' => 'Falta el número de expediente, como figura en el papel.',
            'resolution.text.required_without' => 'Elegí la resolución del expediente o cargá la que no está cargada.',
            'resolution.issuedOn.required_without' => 'Falta la fecha de la resolución: es la fecha del importe original.',
            ...ScannedDocument::messages('caseFile', 'El archivo del expediente'),
            ...ScannedDocument::messages('resolutionFile', 'El archivo de la resolución'),
            ...ScannedDocument::messages('acts.*.file', 'El archivo del acta'),
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

                if ($this->filled('fineCaseId')) {
                    return;
                }

                if ($numero !== '' && ! FineCaseNumber::isValid($numero)) {
                    $validator->errors()->add(
                        'caseNumber',
                        'El número de expediente se escribe como en el papel: 169607/24-0 o 120276/25.',
                    );

                    return;
                }

                $leido = FineCaseNumber::parse($numero);

                if ($leido !== null && ! TwoDigitYear::inRange($leido->year)) {
                    $validator->errors()->add('caseNumber', TwoDigitYear::outOfRangeMessage('El expediente', $leido->year));
                }
            },
            $this->respetaLosDocumentosFijos(...),
        ];
    }

    /**
     * Con documentos fijos, la multa lleva exactamente esos: las mismas
     * actas, ninguna nueva, y la resolución fija si vino una.
     *
     * Lo que se protege es la coherencia entre lo que la pantalla mostró
     * fijo y lo que se guarda. No es un permiso: el formulario libre de
     * «Agregar multa» deja elegir cualquier documento del expediente, y
     * `RegisterFine` exige igual que cada uno sea de ese expediente.
     */
    private function respetaLosDocumentosFijos(Validator $validator): void
    {
        if (! is_array($this->input('fixedDocuments'))) {
            return;
        }

        $fijas = array_map(intval(...), (array) $this->input('fixedDocuments.actIds', []));
        $elegidas = array_map(intval(...), (array) $this->input('actIds', []));
        sort($fijas);
        sort($elegidas);

        // Con archivos el pedido viaja como FormData, y ahí un nulo llega vacío.
        $resolucionFija = $this->filled('fixedDocuments.resolutionId') ? $this->integer('fixedDocuments.resolutionId') : null;
        $resolucionRespetada = $resolucionFija === null
            || ($this->integer('resolutionId') === $resolucionFija && ! $this->filled('resolution.text'));

        if ($fijas !== $elegidas || (array) $this->input('acts', []) !== [] || ! $resolucionRespetada) {
            $validator->errors()->add(
                'fixedDocuments',
                'Los documentos de esta multa vienen fijos desde la ficha del expediente: no se cambian ni se agregan. Para otra combinación, usá «Agregar multa».',
            );
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'caseNumber' => trim((string) $this->input('caseNumber')),
            'caption' => trim((string) $this->input('caption')),
        ]);
    }
}
