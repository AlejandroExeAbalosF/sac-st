<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reabre un período cerrado, con motivo.
 *
 * Es la única salida de un cierre (regla 3 del §9.9) y por eso es un acto
 * con nombre y responsable, no un botón de deshacer. Mientras el período
 * está cerrado, el trigger `financial_events_period_open` rechaza cualquier
 * asiento con fecha adentro; reabrirlo levanta esa barrera.
 *
 * **La reapertura no reescribe el snapshot**: lo deja como estaba y
 * habilita a recalcularlo. Volver a cerrar es lo que produce los totales
 * nuevos, y así queda registrado que hubo dos cierres y qué pasó en el
 * medio, en vez de un solo cierre cuyos números cambiaron sin explicación.
 *
 * `reopened` es un estado propio y no un regreso a `draft`: un período que
 * se reabrió y otro que nunca se cerró no son lo mismo, y confundirlos
 * borraría justamente el rastro que esto existe para dejar.
 */
final class ReopenPeriod
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /** @throws ValidationException */
    public function handle(PeriodClosing $closing, int $actorId, string $reason): PeriodClosing
    {
        $motivo = trim($reason);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'reopen_reason' => 'Reabrir un período cerrado exige decir por qué.',
            ]);
        }

        return DB::transaction(function () use ($closing, $actorId, $motivo): PeriodClosing {
            CashBox::query()->lockForUpdate()->findOrFail($closing->cash_box_id);
            $closing = PeriodClosing::query()
                ->whereKey($closing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($closing->status !== PeriodClosingStatus::Closed) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Este período no está cerrado: está %s.',
                        mb_strtolower($closing->status->label()),
                    ),
                ]);
            }

            /* Un cierre mensual superior sigue bloqueando el día. */
            $mensualCerrado = PeriodClosing::query()
                ->where('cash_box_id', $closing->cash_box_id)
                ->whereKeyNot($closing->getKey())
                ->where('status', PeriodClosingStatus::Closed)
                ->whereDate('period_from', '<=', $closing->period_from)
                ->whereDate('period_to', '>=', $closing->period_to)
                ->first();

            if ($mensualCerrado !== null) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'El cierre %s del %s al %s sigue cerrado y abarca estas fechas. Reabrilo primero.',
                        mb_strtolower($mensualCerrado->period_type->label()),
                        $mensualCerrado->period_from->format('d/m/Y'),
                        $mensualCerrado->period_to->format('d/m/Y'),
                    ),
                ]);
            }

            $closing->fill([
                'status' => PeriodClosingStatus::Reopened,
                'reopened_by' => $actorId,
                'reopened_at' => now(),
                'reopen_reason' => $motivo,
            ])->save();

            $this->recordAuditEvent->handle(
                action: 'periodo.reabierto',
                subject: $closing,
                before: ['status' => PeriodClosingStatus::Closed->value],
                after: ['status' => PeriodClosingStatus::Reopened->value],
                metadata: [
                    'motivo' => $motivo,
                    'desde' => $closing->period_from->toDateString(),
                    'hasta' => $closing->period_to->toDateString(),
                ],
                actorId: $actorId,
            );

            return $closing->refresh();
        });
    }
}
