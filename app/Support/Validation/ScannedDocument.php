<?php

declare(strict_types=1);

namespace App\Support\Validation;

use App\Support\Image\ImageNormalizer;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;

/**
 * Las reglas de un comprobante que se sube escaneado o fotografiado.
 *
 * Un solo criterio para todas las pantallas que reciben la foto de un
 * ticket. Antes había dos: una miraba solo el nombre del archivo —un
 * `.exe` renombrado a `.jpg` pasaba— y la otra solo el contenido.
 *
 * Se exigen las dos cosas: el contenido, porque es lo que el archivo es,
 * y la extensión, porque es con lo que se va a abrir al descargarlo.
 */
final class ScannedDocument
{
    public const MAX_KILOBYTES = 10 * 1024;

    private const TYPES = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /**
     * @return list<mixed>
     */
    public static function rules(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            File::types(self::TYPES)
                ->extensions(self::TYPES)
                ->max(self::MAX_KILOBYTES),
            self::pixelLimit(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute): array
    {
        $formato = 'El comprobante puede ser una foto JPG, PNG o WEBP, o un PDF.';

        return [
            "{$attribute}.mimes" => $formato,
            "{$attribute}.extensions" => $formato,
            "{$attribute}.max" => 'El comprobante no puede superar los 10 MB.',
        ];
    }

    /**
     * Rechaza la imagen que declara más píxeles de los que se pueden abrir.
     *
     * Lo lee del encabezado, sin decodificarla: justamente lo que se
     * quiere evitar es abrirla.
     */
    private static function pixelLimit(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                return;
            }

            $path = $value->getRealPath();
            $size = $path === false ? false : @getimagesize($path);

            if ($size !== false && $size[0] * $size[1] > ImageNormalizer::MAX_PIXELS) {
                $fail('La imagen tiene demasiada resolución. Sacá la foto de nuevo con la cámara en calidad normal.');
            }
        };
    }
}
