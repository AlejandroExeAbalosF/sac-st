<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportIssue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Qué hacer con un problema de una fila importada. Lo que vale para cada problema lo decide el Action. */
final class ResolveFineImportIssueRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'issue' => ['required', Rule::enum(FineImportIssue::class)],
            'action' => ['required', Rule::enum(FineImportAction::class)],
            'reason' => ['nullable', 'string', 'max:500'],
            'fineCaseId' => ['nullable', 'integer', 'exists:fine_cases,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['issue' => 'problema', 'action' => 'decisión', 'reason' => 'motivo', 'fineCaseId' => 'expediente'];
    }
}
