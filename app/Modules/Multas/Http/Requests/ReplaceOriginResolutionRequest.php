<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use App\Modules\Shared\Support\Reason;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * El reemplazo de la resolución de origen: una del expediente
 * (`documentId`) o una nueva, con su fecha y su archivo, y el motivo.
 */
final class ReplaceOriginResolutionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'documentId' => ['nullable', 'integer'],
            'document' => ['nullable', 'array'],
            'document.text' => ['required_without:documentId', 'nullable', 'string', 'max:120'],
            'document.issuedOn' => ['required_without:documentId', 'nullable', new PartialDateRule],
            'file' => ScannedDocument::rules(required: false),
            ...Reason::rules(FineReasonAct::OriginReplacement),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'documentId' => 'resolución',
            'document.text' => 'resolución',
            'document.issuedOn' => 'fecha de la resolución',
            'file' => 'archivo de la resolución',
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document.text.required_without' => 'Elegí la resolución del expediente o cargá la que no está cargada.',
            'document.issuedOn.required_without' => 'Falta la fecha de la resolución: es la fecha del importe original.',
            ...ScannedDocument::messages('file', 'El archivo de la resolución'),
            ...Reason::messages(),
        ];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FineReasonAct::OriginReplacement)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
