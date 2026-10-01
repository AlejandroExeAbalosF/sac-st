<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\LegacyDocumentKind;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;

/**
 * Un papel del sistema anterior tal como lo transcribe el operador.
 *
 * `field` es el prefijo de los campos del formulario —`income`, `order`,
 * `expense`—: los errores vuelven al lado del dato que hay que corregir.
 */
final readonly class LegacyPaper
{
    /**
     * @param  numeric-string  $amount
     */
    public function __construct(
        public LegacyDocumentKind $kind,
        public string $field,
        public string $number,
        public CarbonInterface $issuedOn,
        public string $amount,
        public ?UploadedFile $photo = null,
    ) {}
}
