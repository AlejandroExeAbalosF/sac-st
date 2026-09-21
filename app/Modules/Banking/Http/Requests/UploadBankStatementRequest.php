<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * El archivo que se descargó del banco.
 *
 * Se valida por extensión y no por MIME: el `.xls` de MacroOnline llega
 * como `application/vnd.ms-excel`, `application/octet-stream` o
 * `application/CDFV2` según el navegador y el sistema, y rechazarlo por
 * eso sería rechazar el archivo correcto. Lo que de verdad valida el
 * formato es el parser, que falla ruidosamente si el contenido no es un
 * extracto.
 */
final class UploadBankStatementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bankAccountId' => [
                'required', 'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
            'file' => [
                'required', 'file', 'max:10240',
                'extensions:csv,txt,xls,xlsx,xlsm',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bankAccountId.exists' => 'Elegí una cuenta activa.',
            'file.extensions' => 'El extracto se descarga de MacroOnline en CSV o en Excel.',
            'file.max' => 'El archivo no puede superar los 10 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bankAccountId' => 'cuenta',
            'file' => 'archivo',
        ];
    }
}
