<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * El estado de un comprobante — §9.8 del DER.
 *
 * **Ninguno de estos estados libera el número.** Un talonario es papel
 * numerado: si una hoja se arruina, ese número existió y no vuelve a
 * usarse. Por eso el número del anulado se conserva y el reemplazante
 * apunta al anterior con `replaces_receipt_id`.
 */
enum ReceiptStatus: string
{
    /** Emitido y vigente. */
    case Issued = 'issued';

    /** Anulado después de emitido, con motivo y responsable. */
    case Voided = 'voided';

    /**
     * El papel se arruinó antes de completarse.
     *
     * No es lo mismo que anulado: acá no llegó a documentar nada. Se
     * registra igual porque el número del talonario se consumió.
     */
    case Spoiled = 'spoiled';

    /** Anulado y ya reemplazado por otro que lo referencia. */
    case Replaced = 'replaced';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Emitido',
            self::Voided => 'Anulado',
            self::Spoiled => 'Inutilizado',
            self::Replaced => 'Reemplazado',
        };
    }

    /** Si documenta algo vigente. Solo lo emitido cuenta. */
    public function isActive(): bool
    {
        return $this === self::Issued;
    }
}
