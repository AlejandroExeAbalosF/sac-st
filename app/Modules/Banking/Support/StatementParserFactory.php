<?php

declare(strict_types=1);

namespace App\Modules\Banking\Support;

use App\Modules\Banking\Enums\SourceFormat;
use App\Support\Excel\SpreadsheetReader;

/** Elige el parser que corresponde al formato del archivo. */
final class StatementParserFactory
{
    public function __construct(private readonly SpreadsheetReader $reader) {}

    public function for(SourceFormat $format): StatementParser
    {
        return match ($format) {
            SourceFormat::MacroOnlineCsv => new MacroOnlineCsvParser($this->reader),
            SourceFormat::MacroExcel => new MacroExcelParser($this->reader),
        };
    }
}
