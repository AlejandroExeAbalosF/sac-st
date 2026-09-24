<?php

declare(strict_types=1);

namespace App\Support\Image;

use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Lleva toda imagen subida a una misma forma: WebP, derecha y de un
 * tamaño razonable.
 *
 * Una foto de celular llega con 4000×3000 píxeles, de 3 a 6 MB y con sus
 * metadatos adentro, incluida la ubicación de donde se sacó. Para leer un
 * ticket sobra con mucho menos, y la ubicación del operador no es un dato
 * que el sistema tenga por qué guardar.
 *
 * Volver a codificar tiene además un efecto de seguridad: lo que se guarda
 * son los píxeles que GD dibujó, no el archivo que mandó el cliente, así
 * que cualquier cosa escondida detrás de la imagen se queda afuera.
 *
 * Lo que no es una imagen —un PDF, una planilla— pasa sin tocarse.
 */
final class ImageNormalizer
{
    /**
     * Tope de píxeles para decodificar, leído del encabezado antes de abrir.
     *
     * GD usa cuatro bytes por píxel: 50 MP son unos 200 MB, que entran en
     * los 512 MB del servidor. Un PNG de pocos KB que declara 40.000×40.000
     * pediría 6 GB y tiraría el proceso.
     */
    public const MAX_PIXELS = 50_000_000;

    /** El lado mayor, en píxeles. Alcanza de sobra para leer un ticket. */
    public const MAX_EDGE = 2400;

    /** Calidad WebP: a esta altura la diferencia con el original no se ve. */
    public const QUALITY = 82;

    private const NORMALIZABLE = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * La imagen normalizada, o el mismo archivo si no es una imagen.
     *
     * Cuando devuelve otro archivo, es un temporal: quien lo usa lo borra
     * después de guardarlo.
     */
    public function normalize(UploadedFile $file): UploadedFile
    {
        $mime = $file->getMimeType();

        if (! in_array($mime, self::NORMALIZABLE, true)) {
            return $file;
        }

        $this->assertSupported();

        $path = $file->getRealPath();

        if ($path === false) {
            throw new RuntimeException('No se pudo leer la imagen subida.');
        }

        $size = @getimagesize($path);

        if ($size === false) {
            throw new RuntimeException('El archivo dice ser una imagen pero no se puede leer como tal.');
        }

        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new RuntimeException('La imagen supera el tope de píxeles que se puede procesar.');
        }

        $contents = file_get_contents($path);
        $image = $contents === false ? false : @imagecreatefromstring($contents);

        if ($image === false) {
            throw new RuntimeException('No se pudo decodificar la imagen subida.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->orient($image, $path);
        }

        $image = $this->fit($image);

        $target = tempnam(sys_get_temp_dir(), 'sacst-img-');

        if ($target === false || ! imagewebp($image, $target, self::QUALITY)) {
            throw new RuntimeException('No se pudo codificar la imagen en WebP.');
        }

        return new UploadedFile(
            $target,
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME).'.webp',
            'image/webp',
            null,
            // No viene de un POST: lo escribió el servidor.
            true,
        );
    }

    /**
     * Endereza la foto según cómo estaba el teléfono.
     *
     * La cámara guarda los píxeles como salen del sensor y anota en el EXIF
     * cómo hay que girarlos. Al volver a codificar, el EXIF se pierde, así
     * que el giro tiene que quedar aplicado en los píxeles; si no, toda
     * foto sacada en vertical quedaría acostada.
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && is_int($exif['Orientation'] ?? null) ? $exif['Orientation'] : 1;

        // imagerotate gira en sentido antihorario.
        [$flip, $angle] = match ($orientation) {
            2 => [IMG_FLIP_HORIZONTAL, 0],
            3 => [null, 180],
            4 => [IMG_FLIP_VERTICAL, 0],
            5 => [IMG_FLIP_HORIZONTAL, 90],
            6 => [null, 270],
            7 => [IMG_FLIP_HORIZONTAL, 270],
            8 => [null, 90],
            default => [null, 0],
        };

        if ($flip !== null) {
            imageflip($image, $flip);
        }

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated === false) {
                throw new RuntimeException('No se pudo enderezar la imagen.');
            }

            $image = $rotated;
        }

        return $image;
    }

    /**
     * Achica hasta que el lado mayor entre en `MAX_EDGE`; nunca agranda.
     *
     * Se copia sobre un lienzo transparente para no perder el canal alfa
     * de un PNG: una captura con fondo transparente saldría negra.
     */
    private function fit(GdImage $image): GdImage
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = self::MAX_EDGE / max($width, $height);

        if ($scale >= 1) {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            return $image;
        }

        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /**
     * Sin WebP o sin EXIF, lo que se guardaría estaría roto o torcido.
     *
     * Mejor un error que diga qué falta que una foto acostada que nadie
     * sabe por qué salió así.
     */
    private function assertSupported(): void
    {
        if ((imagetypes() & IMG_WEBP) === 0) {
            throw new RuntimeException('GD no tiene soporte WebP: la extensión se compiló sin libwebp.');
        }

        if (! function_exists('exif_read_data')) {
            throw new RuntimeException('Falta la extensión exif de PHP: sin ella las fotos del celular quedan giradas.');
        }
    }
}
