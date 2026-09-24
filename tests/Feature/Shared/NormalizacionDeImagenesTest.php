<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use GdImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Toda foto que sube una persona se guarda como WebP, derecha y liviana.
 *
 * Los archivos se arman con `archivoSubido()` y no con `fake()`: el falso
 * informa el tipo según el nombre, y lo que se prueba acá es justamente lo
 * que dice el contenido.
 */
class NormalizacionDeImagenesTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_foto_grande_se_guarda_achicada_en_webp(): void
    {
        $adjunto = $this->guardar($this->archivoSubido('IMG_1234.jpg', $this->jpeg(3000, 2000)));

        $this->assertSame('image/webp', $adjunto->mime_type);
        $this->assertSame('IMG_1234.webp', $adjunto->original_filename);
        $this->assertStringEndsWith('.webp', $adjunto->object_key);

        $guardado = $this->contenido($adjunto);

        $this->assertSame([2400, 1600], $this->medidas($guardado));
        // La huella es la del archivo que quedó, no la del que se descartó.
        $this->assertSame(hash('sha256', $guardado), $adjunto->sha256);
        $this->assertSame(strlen($guardado), $adjunto->size_bytes);
    }

    /**
     * La foto sacada con el teléfono en vertical sale derecha.
     *
     * El sensor la guarda acostada y anota en el EXIF que hay que girarla
     * 90° en sentido horario. La mitad izquierda es roja: después del
     * giro tiene que quedar arriba.
     */
    public function test_la_foto_vertical_del_celular_sale_derecha(): void
    {
        $adjunto = $this->guardar($this->archivoSubido('vertical.jpg', $this->conOrientacion($this->jpeg(300, 200), 6)));

        $guardado = $this->contenido($adjunto);

        $this->assertSame([200, 300], $this->medidas($guardado));

        $imagen = $this->decodificar($guardado);
        $arriba = imagecolorsforindex($imagen, imagecolorat($imagen, 100, 20));
        $abajo = imagecolorsforindex($imagen, imagecolorat($imagen, 100, 280));

        $this->assertGreaterThan(200, $arriba['red']);
        $this->assertGreaterThan(200, $abajo['blue']);
    }

    /** Ni la ubicación ni ningún otro metadato de la cámara llegan al disco. */
    public function test_el_archivo_guardado_no_conserva_los_metadatos(): void
    {
        $original = $this->conOrientacion($this->jpeg(300, 200), 6);
        $this->assertStringContainsString('Exif', $original);

        $adjunto = $this->guardar($this->archivoSubido('ticket.jpg', $original));

        $this->assertStringNotContainsString('Exif', $this->contenido($adjunto));
    }

    /** Una captura con fondo transparente no sale con fondo negro. */
    public function test_un_png_conserva_la_transparencia(): void
    {
        $imagen = imagecreatetruecolor(40, 40);
        imagealphablending($imagen, false);
        imagesavealpha($imagen, true);
        imagefill($imagen, 0, 0, (int) imagecolorallocatealpha($imagen, 0, 0, 0, 127));

        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();

        $adjunto = $this->guardar($this->archivoSubido('captura.png', $png));

        $this->assertSame('image/webp', $adjunto->mime_type);

        $guardado = $this->decodificar($this->contenido($adjunto));

        $this->assertSame(127, imagecolorsforindex($guardado, imagecolorat($guardado, 20, 20))['alpha']);
    }

    /** Lo que no es una imagen se guarda tal cual. */
    public function test_un_pdf_se_guarda_intacto(): void
    {
        $pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

        $adjunto = $this->guardar($this->archivoSubido('comprobante.pdf', $pdf));

        $this->assertSame('application/pdf', $adjunto->mime_type);
        $this->assertSame('comprobante.pdf', $adjunto->original_filename);
        $this->assertSame(hash('sha256', $pdf), $adjunto->sha256);
        $this->assertSame($pdf, $this->contenido($adjunto));
    }

    /** Lo que dibujó el sistema ya sale como tiene que salir. */
    public function test_lo_generado_por_el_sistema_no_se_toca(): void
    {
        $jpeg = $this->jpeg(3000, 2000);

        $adjunto = $this->guardar($this->archivoSubido('grafico.jpg', $jpeg), AttachmentSource::Generated);

        $this->assertSame('image/jpeg', $adjunto->mime_type);
        $this->assertSame(hash('sha256', $jpeg), $adjunto->sha256);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function guardar(UploadedFile $archivo, AttachmentSource $origen = AttachmentSource::Scanned): Attachment
    {
        return app(StoreAttachment::class)->handle(
            file: $archivo,
            subject: AttachmentSubject::DepositTicket,
            subjectId: 1,
            documentType: 'deposit_ticket',
            userId: null,
            source: $origen,
        );
    }

    /** Mitad izquierda roja, mitad derecha azul. */
    private function jpeg(int $ancho, int $alto): string
    {
        $imagen = imagecreatetruecolor($ancho, $alto);
        imagefilledrectangle($imagen, 0, 0, intdiv($ancho, 2) - 1, $alto - 1, (int) imagecolorallocate($imagen, 255, 0, 0));
        imagefilledrectangle($imagen, intdiv($ancho, 2), 0, $ancho - 1, $alto - 1, (int) imagecolorallocate($imagen, 0, 0, 255));

        ob_start();
        imagejpeg($imagen, null, 90);

        return (string) ob_get_clean();
    }

    /**
     * Le agrega al JPEG un bloque EXIF con la etiqueta de orientación.
     *
     * Es lo que escribe la cámara del teléfono: un segmento APP1 justo
     * después del inicio del archivo, con una sola entrada (0x0112).
     */
    private function conOrientacion(string $jpeg, int $orientacion): string
    {
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientacion).pack('v', 0)
            .pack('V', 0);
        $payload = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function contenido(Attachment $adjunto): string
    {
        return (string) Storage::disk('local')->get($adjunto->object_key);
    }

    /** @return array{int, int} */
    private function medidas(string $bytes): array
    {
        $imagen = $this->decodificar($bytes);

        return [imagesx($imagen), imagesy($imagen)];
    }

    private function decodificar(string $bytes): GdImage
    {
        $imagen = imagecreatefromstring($bytes);
        $this->assertInstanceOf(GdImage::class, $imagen);

        return $imagen;
    }
}
