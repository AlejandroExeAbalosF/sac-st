<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Support\OpeningDateRule;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Una apertura de la caja de Haberes es posterior a los papeles ya cargados.
 *
 * Los papeles del sistema anterior tienen que ser anteriores a la apertura
 * de su moneda; abrir un libro con fecha igual o anterior al último papel
 * cargado en esa moneda lo volvería posterior.
 *
 * Lee lo mismo que la guarda de la base
 * (`cash_book_openings_after_legacy_papers`): los papeles y los pagos
 * vigentes, sin anular.
 */
final class LegacyPapersBeforeOpening implements OpeningDateRule
{
    public function assertAllows(int $cashBoxId, Currency $currency, CarbonInterface $openedOn): void
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
                SELECT d.issued_on AS fecha
                  FROM legacy_documents d
                  JOIN haberes h ON h.id = d.haber_id
                 WHERE d.voided_at IS NULL AND h.currency = :moneda1
                UNION ALL
                SELECT s.paid_on
                  FROM legacy_settlements s
                  JOIN haberes h ON h.id = s.haber_id
                 WHERE s.voided_at IS NULL AND s.paid_on IS NOT NULL AND h.currency = :moneda2
            ) AS papeles
        SQL, ['moneda1' => $currency->value, 'moneda2' => $currency->value]);

        if (! is_string($ultimo)) {
            return;
        }

        $papel = CarbonImmutable::parse($ultimo);

        if ($openedOn->toDateString() <= $papel->toDateString()) {
            throw ValidationException::withMessages([
                'date' => sprintf(
                    'Hay papeles del sistema anterior en %s cargados con fecha hasta el %s: la apertura tiene que ser posterior.',
                    mb_strtolower($currency->label()),
                    $papel->format('d/m/Y'),
                ),
            ]);
        }
    }
}
