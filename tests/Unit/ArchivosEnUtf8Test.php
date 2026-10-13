<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Todo archivo de texto del repo está en UTF-8, y sano.
 *
 * El menú salió con «Numeraci?n de comprobantes», con un rombo negro donde
 * iba la «ó»: alguna herramienta la leyó con otra codificación y la
 * reemplazó por U+FFFD, el carácter de reemplazo. Una vez guardado, la letra original no se recupera. Ningún
 * otro control lo vio venir: para prettier, eslint y tsc ese carácter es
 * texto válido, y `.editorconfig` lo respetan los editores, no los scripts.
 *
 * En esta máquina el sospechoso de siempre es Windows PowerShell 5.1:
 * `Set-Content` escribe en la página de códigos de Windows si no se le
 * pide `-Encoding utf8`, y `Out-File` antepone un BOM, que en un archivo
 * PHP queda antes de `declare(strict_types=1)` y lo vuelve un error fatal.
 *
 * Se recorre lo que lista `git ls-files` —lo versionado más lo nuevo que no
 * está ignorado—, así un archivo recién creado se revisa antes del commit
 * y lo generado (`vendor`, `public/build`, Wayfinder) queda afuera solo.
 * Los binarios se descartan por tener bytes nulos, el mismo criterio que
 * usa git.
 */
class ArchivosEnUtf8Test extends TestCase
{
    /**
     * Archivos que no son nuestros y se versionan byte por byte: los
     * extractos del banco pueden venir en Latin-1, y normalizarlos los
     * convertiría en otra cosa.
     */
    private const EXCLUIDOS = ['tests/Fixtures/'];

    public function test_ningun_archivo_de_texto_tiene_la_codificacion_rota(): void
    {
        $raiz = dirname(__DIR__, 2);
        $git = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], $raiz);
        $git->run();

        if (! $git->isSuccessful()) {
            // CI siempre tiene git; esto solo pasa en un entorno sin repo.
            $this->markTestSkipped('git no pudo listar los archivos: '.trim($git->getErrorOutput()));
        }

        $hallazgos = [];
        $revisados = 0;

        foreach (array_filter(explode("\0", $git->getOutput())) as $ruta) {
            if ($this->excluido($ruta) || ! is_file("{$raiz}/{$ruta}")) {
                continue;
            }

            $contenido = (string) file_get_contents("{$raiz}/{$ruta}");

            if (str_contains(substr($contenido, 0, 8000), "\0")) {
                continue;
            }

            $revisados++;
            array_push($hallazgos, ...$this->problemas($ruta, $contenido));
        }

        $this->assertGreaterThan(100, $revisados, 'Se revisaron muy pocos archivos: se rompió la prueba, no el repo.');

        $this->assertSame(
            [],
            $hallazgos,
            'Estos archivos tienen la codificación rota. Corregí el texto a mano —la letra '
            .'original no se recupera sola— y si los escribió PowerShell, usá -Encoding utf8.',
        );
    }

    /**
     * @return list<string>
     */
    private function problemas(string $ruta, string $contenido): array
    {
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            return ["{$ruta}: no es UTF-8 válido"];
        }

        $problemas = [];

        if (str_starts_with($contenido, "\u{FEFF}")) {
            $problemas[] = "{$ruta}: empieza con un BOM";
        }

        foreach (explode("\n", $contenido) as $indice => $linea) {
            $numero = $indice + 1;

            if (str_contains($linea, "\u{FFFD}")) {
                $problemas[] = "{$ruta}:{$numero}: carácter de reemplazo (U+FFFD) en lugar de una letra";
            }

            // UTF-8 leído como Latin-1 y vuelto a guardar: cada letra con
            // tilde queda partida en dos, una «A» con tilde o circunflejo
            // seguida de un signo (U+00C3 + U+00B3 en vez de «ó»), y la raya
            // queda como U+00E2 + U+20AC + otro signo. En castellano ninguna
            // de esas secuencias aparece escrita a propósito.
            if (preg_match('/[\x{00C2}\x{00C3}][\x{0080}-\x{00BF}]|\x{00E2}\x{20AC}/u', $linea) === 1) {
                $problemas[] = "{$ruta}:{$numero}: acentos codificados dos veces";
            }
        }

        return $problemas;
    }

    private function excluido(string $ruta): bool
    {
        foreach (self::EXCLUIDOS as $prefijo) {
            if (str_starts_with($ruta, $prefijo)) {
                return true;
            }
        }

        return false;
    }
}
