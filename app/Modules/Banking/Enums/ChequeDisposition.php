<?php

declare(strict_types=1);

namespace App\Modules\Banking\Enums;

/** Qué se hizo con el papel del cheque rechazado (Multas M03). */
enum ChequeDisposition: string
{
    /** Se anuló y se devolvió a la empresa. */
    case VoidedReturned = 'voided_returned';

    /** Se documentó con un acta de cheque rechazado. */
    case Act = 'act';

    public function label(): string
    {
        return match ($this) {
            self::VoidedReturned => 'Anulado y devuelto',
            self::Act => 'Acta de cheque rechazado',
        };
    }
}
