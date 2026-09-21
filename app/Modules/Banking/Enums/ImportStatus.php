<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/**
 * Estado de una importación de extracto.
 *
 * `Failed` no es un error del sistema: es un archivo que no pasó los
 * controles —cuenta distinta, moneda distinta, cadena de saldos rota— y
 * quedó registrado con su motivo. Se guarda igual porque saber qué se
 * intentó importar y por qué no entró es parte de la trazabilidad.
 */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Parsing = 'parsing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Cargado',
            self::Parsing => 'Procesando',
            self::Completed => 'Importado',
            self::Failed => 'Rechazado',
        };
    }
}
