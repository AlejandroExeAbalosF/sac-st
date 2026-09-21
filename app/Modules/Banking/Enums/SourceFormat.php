<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

use InvalidArgumentException;

/**
 * Formato del archivo descargado del banco.
 *
 * Queda guardado en la importación porque el banco va a cambiar el
 * formato alguna vez, y cuando pase hay que poder saber con qué lógica se
 * leyó cada archivo viejo sin adivinarlo por la extensión.
 */
enum SourceFormat: string
{
    /**
     * El CSV de MacroOnline. Viene doble comillado —la línea entera entre
     * comillas, con las internas duplicadas—, así que un parser RFC 4180
     * devuelve una sola columna por fila y hay que desenvolver dos veces.
     */
    case MacroOnlineCsv = 'macro_online_csv';

    /**
     * El Excel de MacroOnline, en BIFF8. Trae menos rarezas y más datos:
     * la cabecera identifica cuenta, moneda, operador y fecha de descarga.
     */
    case MacroExcel = 'macro_excel';

    public function label(): string
    {
        return match ($this) {
            self::MacroOnlineCsv => 'MacroOnline (CSV)',
            self::MacroExcel => 'MacroOnline (Excel)',
        };
    }

    /**
     * Deduce el formato por la extensión del archivo subido.
     */
    public static function fromExtension(string $extension): self
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => self::MacroOnlineCsv,
            'xls', 'xlsx', 'xlsm' => self::MacroExcel,
            default => throw new InvalidArgumentException(
                "No hay un lector de extractos para archivos «{$extension}»."
            ),
        };
    }
}
