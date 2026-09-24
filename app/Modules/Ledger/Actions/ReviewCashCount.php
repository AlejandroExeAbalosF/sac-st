<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da por bueno un arqueo.
 *
 * Es el acto que lo saca de borrador: a partir de acá el conteo no se
 * corrige más y puede respaldar un cierre.
 *
 * ─── Sobre la segunda firma ──────────────────────────────────────────────
 *
 * Lo ideal es que revise **alguien distinto de quien contó**: un arqueo que
 * se aprueba solo no controla nada. Pero el área trabaja con un equipo
 * chico, y exigir dos personas dejaba trabado el día entero cuando hay una
 * sola en el mostrador — el cierre no admite arqueos en borrador.
 *
 * Así que se admite, y **se deja dicho**. Un arqueo revisado por su propio
 * autor queda marcado como «sin segunda firma» —`wasSelfReviewed()`— y eso
 * se ve en la caja del día, en la lista y en la planilla. Se prefiere un
 * registro que diga la verdad antes que un control que en la práctica se
 * saltea prestando la sesión de otro.
 *
 * El reparto de permisos sigue en pie: revisar exige `caja.revisar-arqueo`,
 * que el administrativo del mostrador no tiene. No es la misma barrera,
 * pero no es ninguna.
 */
final class ReviewCashCount
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /** @throws ValidationException */
    public function handle(CashCount $cashCount, int $reviewerId): CashCount
    {
        return DB::transaction(function () use ($cashCount, $reviewerId): CashCount {
            CashBox::query()->lockForUpdate()->findOrFail($cashCount->cash_box_id);
            $cashCount = CashCount::query()
                ->whereKey($cashCount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($cashCount->status !== CashCountStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Este arqueo ya está %s.',
                        mb_strtolower($cashCount->status->label()),
                    ),
                ]);
            }

            /*
             * Contar nada solo vale si no había nada que contar.
             *
             * La regla existe para que nadie dé por revisado un arqueo
             * donde no se cargó una sola denominación. Pero un cajón vacío
             * es un estado legítimo —el día que todo se depositó, por
             * ejemplo— y exigirle una fila lo dejaba sin forma de arquear
             * y, por lo tanto, sin forma de cerrar el día.
             *
             * Si el libro dice cero y el conteo concluye cero, contar nada
             * **es** el conteo. Cualquier otro caso sigue trabado.
             */
            $recaudacionEsperada = Decimal::sub(
                $cashCount->expected_amount,
                $cashCount->uncounted_amount,
            );

            if (
                $cashCount->allLines()->doesntExist()
                && $cashCount->carry_recount_reason === null
                && ! Decimal::equals($recaudacionEsperada, '0')
            ) {
                throw ValidationException::withMessages([
                    'lines' => 'Un arqueo sin denominaciones contadas no se puede revisar.',
                ]);
            }

            if ($cashCount->requiresDifferenceExplanation() && ($cashCount->explanation === null || trim($cashCount->explanation) === '')) {
                throw ValidationException::withMessages([
                    'explanation' => 'Una diferencia en la recaudación del día no puede aprobarse sin explicación.',
                ]);
            }

            $cashCount->fill([
                'status' => CashCountStatus::Reviewed,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ])->save();

            $this->recordAuditEvent->handle(
                action: 'arqueo.revisado',
                subject: $cashCount,
                after: [
                    'difference_amount' => $cashCount->difference_amount,
                    'uncounted_amount' => $cashCount->uncounted_amount,
                    /*
                     * Que lo haya revisado su propio autor no se deduce
                     * después sin cruzar dos columnas: acá queda escrito.
                     */
                    'self_reviewed' => $cashCount->wasSelfReviewed(),
                ],
                actorId: $reviewerId,
            );

            return $cashCount->refresh();
        });
    }
}
