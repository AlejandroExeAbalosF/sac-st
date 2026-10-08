<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Support\OpeningDateRule;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Una apertura de la caja de Haberes es posterior a los papeles ya cargados.
 *
 * Los papeles del sistema anterior tienen que ser anteriores a la apertura;
 * abrir un libro con fecha igual o anterior al último cargado lo volvería
 * posterior. Pasa al abrir la segunda moneda, si se elige una fecha vieja.
 *
 * Lee lo mismo que la guarda de la base
 * (`cash_book_openings_after_legacy_papers`): los papeles y los pagos
 * vigentes, sin anular.
 */
final class LegacyPapersBeforeOpening implements OpeningDateRule
{
    public function assertAllows(int $cashBoxId, CarbonInterface $openedOn): void
    {
        $esHaberes = CashBox::query()
            ->whereKey($cashBoxId)
            ->where('code', CashBox::HABERES)
            ->exists();

        if (! $esHaberes) {
            return;
        }

        $ultimo = DB::scalar(<<<'SQL'
            SELECT MAX(fecha) FROM (
                SELECT issued_on AS fecha FROM legacy_documents WHERE voided_at IS NULL
                UNION ALL
                SELECT paid_on FROM legacy_settlements WHERE voided_at IS NULL AND paid_on IS NOT NULL
            ) AS papeles
        SQL);

        if (! is_string($ultimo)) {
            return;
        }

        $papel = CarbonImmutable::parse($ultimo);

        if ($openedOn->toDateString() <= $papel->toDateString()) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Hay papeles del sistema anterior cargados con fecha hasta el %s: la apertura tiene que ser posterior.',
                    $papel->format('d/m/Y'),
                ),
            ]);
        }
    }
}
