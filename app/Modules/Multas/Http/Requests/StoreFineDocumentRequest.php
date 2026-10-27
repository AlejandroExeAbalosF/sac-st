<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un documento que se agrega al expediente, o que se vincula a la multa.
 *
 * Desde la ficha de la multa se puede elegir uno que el expediente ya tiene
 * (`documentId`) con el papel que cumple; desde el expediente siempre es
 * uno nuevo. Al vincular, el documento puede llegar con su archivo, que
 * queda en el documento.
 */
final class StoreFineDocumentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $vinculaAMulta = $this->routeIs('multas.documentos.store');

        return [
            'role' => $vinculaAMulta ? ['required', 'in:origin,ratification,support'] : ['prohibited'],
            'documentId' => $vinculaAMulta ? ['nullable', 'integer'] : ['prohibited'],
            'document' => ['nullable', 'array'],
            'document.kind' => [$vinculaAMulta ? 'required_without:documentId' : 'required', 'nullable', 'in:resolution,act,other'],
            'document.actType' => ['nullable', 'in:LAB,HYS'],
            'document.text' => [$vinculaAMulta ? 'required_without:documentId' : 'required', 'nullable', 'string', 'max:120'],
            'document.issuedOn' => ['nullable', new PartialDateRule],
            'file' => $vinculaAMulta ? ScannedDocument::rules(required: false) : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role' => 'papel',
            'documentId' => 'documento',
            'document.kind' => 'tipo de documento',
            'document.actType' => 'tipo de acta',
            'document.text' => 'documento',
            'document.issuedOn' => 'fecha',
            'file' => 'archivo del documento',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ScannedDocument::messages('file', 'El archivo del documento');
    }
}
