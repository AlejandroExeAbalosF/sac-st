<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el módulo de una caja agrega al mes del calendario: los papeles que
 * salen de su cierre mensual, cómo emitirlos y adónde seguir mirando el mes.
 *
 * Ledger lo dibuja sin saber qué es: para Multas son las dos notas de la
 * rendición a la SAF; otra caja puede no tener nada.
 */
#[TypeScript]
final class CashMonthPanelData extends Data
{
    /**
     * @param  list<CashMonthDocumentData>  $documents  los vigentes primero
     * @param  list<CashMonthLinkData>  $links
     */
    public function __construct(
        public string $title,
        public array $documents,
        /** Por qué no hay papeles todavía, cuando no los hay. */
        public ?string $emptyText,
        /** Emitirlos, si el mes está cerrado, faltan y quien mira puede. */
        public ?CashMonthLinkData $emit,
        public array $links,
    ) {}
}
