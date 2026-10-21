<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * En término o fuera de término (M08, DER físico §6.5).
 *
 * El período de origen es el mes en que se registró la multa, no el del
 * pago. Se mira solo el cierre mensual de la caja de Multas de ese mes, en
 * la moneda de la multa, y se compara contra la **presentación** del
 * comprobante, no contra su carga en el sistema:
 *
 * - sin cierre de ese mes todavía → en término;
 * - presentado antes del cierre → en término; después → fuera de término;
 * - sin fecha de registro, sin fecha de presentación, o presentado el mismo
 *   día del cierre cuando solo se sabe el día → a revisar (P-T1).
 *
 * Se calcula una vez, al aplicar el pago, y se guarda con la versión de la
 * evidencia del cierre contra la que se juzgó: reabrir el mes después no
 * reclasifica lo ya aplicado. Un mes reabierto, mientras siga abierto, es un
 * mes sin cierre.
 */
final class FinePaymentTimeliness
{
    /**
     * @return array{timeliness: FineTimeliness, evidenceId: int|null, closedAt: CarbonInterface|null}
     */
    public function judge(Fine $multa, ?CarbonInterface $presentedAt, PresentationPrecision $precision): array
    {
        if ($multa->registered_on === null || $multa->registered_precision === DatePrecision::Unknown) {
            return $this->result(FineTimeliness::Undetermined);
        }

        $mes = CarbonImmutable::parse($multa->registered_on->toDateString())->startOfMonth();

        $cierre = PeriodClosing::query()
            ->where('cash_box_id', CashBox::query()->where('code', CashBox::MULTAS)->value('id'))
            ->where('currency', $multa->currency)
            ->where('period_type', PeriodType::Monthly)
            ->whereDate('period_from', $mes->toDateString())
            ->first();

        if ($cierre === null || $cierre->status !== PeriodClosingStatus::Closed || $cierre->closed_at === null) {
            return $this->result(FineTimeliness::OnTime);
        }

        $evidencia = (int) DB::table('period_closing_evidence')
            ->where('period_closing_id', $cierre->id)
            ->max('id');
        $cerradoEl = CarbonImmutable::instance($cierre->closed_at);

        // Un cierre sin evidencia congelada no deja citar contra qué se juzgó.
        if ($evidencia === 0) {
            return $this->result(FineTimeliness::Undetermined);
        }

        if ($presentedAt === null || $precision === PresentationPrecision::Unknown) {
            return $this->result(FineTimeliness::Undetermined, $evidencia, $cerradoEl);
        }

        if ($precision === PresentationPrecision::Day) {
            $dia = BusinessDate::fromInstant($presentedAt)->toDateString();
            $diaDelCierre = BusinessDate::fromInstant($cerradoEl)->toDateString();

            return $this->result(match (true) {
                $dia < $diaDelCierre => FineTimeliness::OnTime,
                $dia > $diaDelCierre => FineTimeliness::Late,
                default => FineTimeliness::Undetermined,
            }, $evidencia, $cerradoEl);
        }

        return $this->result(
            $presentedAt->lessThan($cerradoEl) ? FineTimeliness::OnTime : FineTimeliness::Late,
            $evidencia,
            $cerradoEl,
        );
    }

    /**
     * @return array{timeliness: FineTimeliness, evidenceId: int|null, closedAt: CarbonInterface|null}
     */
    private function result(FineTimeliness $termino, ?int $evidencia = null, ?CarbonInterface $cerradoEl = null): array
    {
        return [
            'timeliness' => $termino,
            'evidenceId' => $evidencia,
            'closedAt' => $cerradoEl,
        ];
    }
}
