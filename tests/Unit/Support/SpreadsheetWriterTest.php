<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Excel\SpreadsheetWriter;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class SpreadsheetWriterTest extends TestCase
{
    public function test_las_cadenas_que_parecen_formulas_se_escriben_como_texto(): void
    {
        $response = SpreadsheetWriter::download(
            'prueba.xlsx',
            ['Texto', 'Número'],
            [['=HYPERLINK("https://example.test","abrir")', 42]],
        );

        $path = tempnam(sys_get_temp_dir(), 'writer').'.xlsx';

        ob_start();
        $response->sendContent();
        $content = ob_get_clean();

        $this->assertIsString($content);
        file_put_contents($path, $content);

        try {
            $sheet = IOFactory::load($path)->getActiveSheet();

            $this->assertSame(DataType::TYPE_INLINE, $sheet->getCell('A2')->getDataType());
            $this->assertSame(
                '=HYPERLINK("https://example.test","abrir")',
                $sheet->getCell('A2')->getFormattedValue(),
            );
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        } finally {
            @unlink($path);
        }
    }
}
