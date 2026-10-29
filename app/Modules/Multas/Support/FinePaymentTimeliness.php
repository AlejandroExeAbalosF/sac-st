<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * En término o fuera de término (M08).
 *
 * El período de origen es el mes en que se registró la multa, no el del
 * pago, y termina su **último día del calendario**: lo presentado después ya
 * es del mes siguiente, se haya cerrado la caja o no (decisión del usuario,
 * 29/10/2026; antes se cortaba en el cierre mensual). Se compara contra la
 * **presentación** del comprobante, no contra su carga en el sistema:
 *
 * - presentado hasta el último día del mes de registro → en término;
 * - después → fuera de término;
 * - sin fecha de registro, o sin fecha de presentación → a revisar.
 *
 * Un registro conocido solo por el mes alcanza: el mes es lo que importa.
 * Se calcula una vez, al aplicar el pago, y se guarda con el día contra el
 * que se juzgó.
 */
final class FinePaymentTimeliness
{
    /**
     * @return array{timeliness: FineTimeliness, termEndsOn: CarbonImmutable|null}
     */
    public function judge(Fine $multa, ?CarbonInterface $presentedAt, PresentationPrecision $precision): array
    {
        if ($multa->registered_on === null || $multa->registered_precision === DatePrecision::Unknown) {
            return ['timeliness' => FineTimeliness::Undetermined, 'termEndsOn' => null];
        }

        $vence = CarbonImmutable::parse($multa->registered_on->toDateString())->endOfMonth()->startOfDay();

        if ($presentedAt === null || $precision === PresentationPrecision::Unknown) {
            return ['timeliness' => FineTimeliness::Undetermined, 'termEndsOn' => $vence];
        }

        $presentado = BusinessDate::fromInstant($presentedAt)->toDateString();

        return [
            'timeliness' => $presentado <= $vence->toDateString() ? FineTimeliness::OnTime : FineTimeliness::Late,
            'termEndsOn' => $vence,
        ];
    }
}
