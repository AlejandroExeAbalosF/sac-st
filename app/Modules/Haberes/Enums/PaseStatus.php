<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * El ciclo documental propio de la nota de Pase — §9.7 del DER.
 *
 * **Solo el de la nota.** El ciclo de envío y devolución no vive acá sino
 * en `remisiones`: lo que se repite ante una devolución del organismo es
 * el viaje, no el documento. La misma nota puede remitirse dos veces
 * conservando su estado.
 */
enum PaseStatus: string
{
    case Draft = 'draft';

    /** Generada junto con su Orden. Es donde nace en este circuito. */
    case Generated = 'generated';

    /** Firmada por quien corresponde. */
    case Signed = 'signed';

    case Archived = 'archived';

    /** Dada de baja junto con la Orden que acompañaba. */
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Generated => 'Generada',
            self::Signed => 'Firmada',
            self::Archived => 'Archivada',
            self::Voided => 'Anulada',
        };
    }
}
