<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Excel\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Tests\TestCase;

/**
 * El extracto se lee dentro de un tope de filas y columnas.
 *
 * Sin tope, un `.xlsx` de pocos KB con una celda en la última fila y la
 * última columna obligaba a recorrer diecisiete mil millones de celdas
 * vacías: cada subida mataba un proceso del servidor.
 */
class SpreadsheetReaderTest extends TestCase
{
    /** @var list<string> */
    private array $archivos = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos as $archivo) {
            @unlink($archivo);
        }

        parent::tearDown();
    }

    public function test_una_celda_en_la_esquina_de_la_hoja_no_se_recorre(): void
    {
        $archivo = $this->xlsx(function (Spreadsheet $libro): void {
            $libro->getActiveSheet()->setCellValue('A1', 'Fecha');
            $libro->getActiveSheet()->setCellValue('XFD1048576', 'trampa');
        });

        $inicio = microtime(true);
        $filas = app(SpreadsheetReader::class)->spreadsheet($archivo);

        $this->assertSame([['Fecha']], $filas);
        $this->assertLessThan(5.0, microtime(true) - $inicio);
    }

    public function test_las_columnas_mas_alla_del_tope_se_descartan(): void
    {
        $archivo = $this->xlsx(function (Spreadsheet $libro): void {
            $libro->getActiveSheet()->setCellValue('A1', 'Fecha');
            $libro->getActiveSheet()->setCellValue('BZ1', 'formateada de más');
        });

        $filas = app(SpreadsheetReader::class)->spreadsheet($archivo);

        $this->assertSame([['Fecha']], $filas);
    }

    public function test_mas_filas_con_datos_que_el_tope_no_es_un_extracto(): void
    {
        // Con un tope de 50 filas: la regla es la misma que con 20.000.
        $archivo = $this->xlsx(function (Spreadsheet $libro): void {
            $hoja = $libro->getActiveSheet();

            for ($fila = 1; $fila <= 51; $fila++) {
                $hoja->setCellValue('A'.$fila, 'movimiento '.$fila);
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('más de 50 filas con datos');

        (new SpreadsheetReader(maxRows: 50))->spreadsheet($archivo);
    }

    public function test_hasta_el_tope_se_lee_entero(): void
    {
        $archivo = $this->xlsx(function (Spreadsheet $libro): void {
            $hoja = $libro->getActiveSheet();

            for ($fila = 1; $fila <= 50; $fila++) {
                $hoja->setCellValue('A'.$fila, 'movimiento '.$fila);
            }
        });

        $filas = (new SpreadsheetReader(maxRows: 50))->spreadsheet($archivo);

        $this->assertCount(50, $filas);
        $this->assertSame(['movimiento 50'], $filas[49]);
    }

    /**
     * @param  callable(Spreadsheet): void  $armar
     */
    private function xlsx(callable $armar): string
    {
        $libro = new Spreadsheet;
        $armar($libro);

        $ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('lector-', true).'.xlsx';
        (new Xlsx($libro))->save($ruta);
        $libro->disconnectWorksheets();
        $this->archivos[] = $ruta;

        return $ruta;
    }
}
