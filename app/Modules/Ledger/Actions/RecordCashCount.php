<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra el conteo físico del efectivo de una caja.
 *
 * El reverso de la planilla: cantidad por denominación, y el total sale de
 * la suma. **Nunca se tipea el total** —el DER es explícito: *«un total
 * tipeado no se puede auditar; un desglose por denominación sí»*— y la
 * base lo verifica con un trigger diferido por si alguien lo intenta.
 *
 * El saldo teórico tampoco viaja desde el navegador: lo calcula
 * `CashBalance` sobre `CASH_ON_HAND` de esa caja, esa moneda y hasta esa
 * fecha. Que el número contra el que se compara lo ponga el operador sería
 * pedirle que apruebe su propio examen.
 *
 * Mientras el arqueo está en borrador se puede volver a llamar: reemplaza
 * el conteo entero. Contar billetes es cargar veinte números y equivocarse
 * en uno es normal.
 */
final class RecordCashCount
{
    public function __construct(
        private readonly CashBalance $cashBalance,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  array<int|string, int>  $denominations  Cantidad de billetes, indexada
     *                                                 por denominación. Las cantidades en cero se descartan.
     *
     * @throws ValidationException
     */
    public function handle(
        int $cashBoxId,
        CarbonInterface $countedOn,
        array $denominations,
        Currency $currency = Currency::Ars,
        ?int $actorId = null,
        string $uncountedAmount = '0.00',
        ?string $uncountedReason = null,
        ?string $explanation = null,
        ?int $sequence = null,
    ): CashCount {
        $fecha = CarbonImmutable::parse($countedOn)->startOfDay();
        $lineas = $this->assertCountable($denominations);
        $noRecontado = $this->assertUncounted($uncountedAmount, $uncountedReason);

        $contado = '0.00';

        foreach ($lineas as $denominacion => $cantidad) {
            $contado = Decimal::add(
                $contado,
                bcmul(Decimal::scale((string) $denominacion), (string) $cantidad, 2),
            );
        }

        return DB::transaction(function () use (
            $cashBoxId, $fecha, $currency, $lineas, $contado,
            $noRecontado, $uncountedReason, $explanation, $actorId, $sequence
        ): CashCount {
            CashBox::query()->lockForUpdate()->findOrFail($cashBoxId);

            $this->assertPeriodOpen($cashBoxId, $currency, $fecha);

            /*
             * El saldo se lee después del candado común de Caja: así ningún
             * asiento puede modificar el libro entre el cálculo y el guardado
             * de este arqueo.
             */
            $teorico = $this->cashBalance->of(
                LedgerAccount::CashOnHand,
                $cashBoxId,
                $currency,
                $fecha,
            );
            $diferencia = Decimal::sub(Decimal::add($contado, $noRecontado), $teorico);

            if (! Decimal::equals($diferencia, '0') && ($explanation === null || trim($explanation) === '')) {
                throw ValidationException::withMessages([
                    'explanation' => sprintf(
                        'El conteo no coincide con el libro por %s %s. Una diferencia exige explicación.',
                        $currency->symbol(),
                        Decimal::format(Decimal::abs($diferencia)),
                    ),
                ]);
            }

            $turno = $sequence ?? $this->nextSequence($cashBoxId, $fecha, $currency);

            $arqueo = CashCount::query()
                ->where('cash_box_id', $cashBoxId)
                ->whereDate('counted_on', $fecha)
                ->where('currency', $currency)
                ->where('sequence', $turno)
                ->lockForUpdate()
                ->first();

            if ($arqueo !== null && ! $arqueo->status->isEditable()) {
                throw ValidationException::withMessages([
                    'counted_on' => sprintf(
                        'El arqueo del %s ya está %s y no admite cambios.',
                        $fecha->format('d/m/Y'),
                        mb_strtolower($arqueo->status->label()),
                    ),
                ]);
            }

            $anterior = $arqueo?->only(['counted_amount', 'uncounted_amount', 'expected_amount']);

            $arqueo ??= new CashCount;

            $arqueo->fill([
                'cash_box_id' => $cashBoxId,
                'counted_on' => $fecha,
                'sequence' => $turno,
                'counted_at' => now(),
                'currency' => $currency,
                'expected_amount' => $teorico,
                'counted_amount' => $contado,
                'uncounted_amount' => $noRecontado,
                'uncounted_reason' => Decimal::equals($noRecontado, '0') ? null : $uncountedReason,
                'status' => CashCountStatus::Draft,
                'explanation' => $explanation,
                'performed_by' => $actorId,
            ])->save();

            /*
             * El conteo se reemplaza entero y no se parchea línea por
             * línea: un arqueo es una foto del cajón en un momento, y
             * mezclar denominaciones de dos conteos distintos daría un
             * total que nunca estuvo sobre la mesa.
             */
            $arqueo->lines()->delete();

            $arqueo->lines()->createMany(
                array_map(
                    fn (int $denominacion): array => [
                        'denomination' => Decimal::scale((string) $denominacion),
                        'quantity' => $lineas[$denominacion],
                    ],
                    array_keys($lineas),
                )
            );

            $this->recordAuditEvent->handle(
                action: $anterior === null ? 'arqueo.registrado' : 'arqueo.corregido',
                subject: $arqueo,
                before: $anterior,
                after: $arqueo->only(['counted_amount', 'uncounted_amount', 'expected_amount']),
                metadata: ['denominaciones' => count($lineas)],
                actorId: $actorId,
            );

            return $arqueo->refresh();
        });
    }

    /**
     * @param  array<int|string, int>  $denominations
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function assertCountable(array $denominations): array
    {
        $lineas = [];

        foreach ($denominations as $denominacion => $cantidad) {
            $valor = (int) $denominacion;

            if ($valor <= 0) {
                throw ValidationException::withMessages([
                    'denominations' => "«{$denominacion}» no es una denominación válida.",
                ]);
            }

            if ($cantidad < 0) {
                throw ValidationException::withMessages([
                    'denominations' => "La cantidad de billetes de {$valor} no puede ser negativa.",
                ]);
            }

            // Un cero es «no había de ese billete», que es lo mismo que no
            // escribir la fila. La planilla los deja en blanco.
            if ($cantidad === 0) {
                continue;
            }

            $lineas[$valor] = $cantidad;
        }

        krsort($lineas);

        return $lineas;
    }

    /**
     * No se arquea un día que ya está cerrado.
     *
     * **El cierre congela la jornada, y el arqueo es parte de ella.** Un
     * conteo cargado después dejaría la planilla ya emitida diciendo una
     * cosa y el último arqueo del día diciendo otra; y como el cierre lee
     * el último, al reabrir y recerrar tomaría como respaldo un conteo
     * que nadie revisó contra ese snapshot.
     *
     * Es el tercer lado de la misma regla: no se cierra sin arqueo, no se
     * cierra con una diferencia viva, y no se arquea lo ya cerrado.
     *
     * Se comprueba adentro del candado de la caja: un cierre concurrente
     * no puede colarse entre esta lectura y el guardado.
     *
     * @throws ValidationException
     */
    private function assertPeriodOpen(int $cashBoxId, Currency $currency, CarbonImmutable $date): void
    {
        $cerrado = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('status', PeriodClosingStatus::Closed)
            ->whereDate('period_from', '<=', $date)
            ->whereDate('period_to', '>=', $date)
            ->orderByDesc('period_to')
            ->first();

        if ($cerrado === null) {
            return;
        }

        throw ValidationException::withMessages([
            'counted_on' => sprintf(
                'El %s pertenece a un período cerrado (%s al %s). Para volver a contar el cajón hay que reabrirlo.',
                $date->format('d/m/Y'),
                $cerrado->period_from->format('d/m/Y'),
                $cerrado->period_to->format('d/m/Y'),
            ),
        ]);
    }

    /**
     * @return numeric-string
     *
     * @throws ValidationException
     */
    private function assertUncounted(string $amount, ?string $reason): string
    {
        $importe = Decimal::scale($amount);

        if (Decimal::isNegative($importe)) {
            throw ValidationException::withMessages([
                'uncounted_amount' => 'Lo no recontado no puede ser negativo.',
            ]);
        }

        if (! Decimal::equals($importe, '0') && ($reason === null || trim($reason) === '')) {
            throw ValidationException::withMessages([
                'uncounted_reason' => 'Declarar un importe sin recontar exige decir por qué no se contó.',
            ]);
        }

        return $importe;
    }

    /**
     * El turno siguiente del día, o el primero.
     *
     * Hoy devuelve 1 siempre porque el área hace un arqueo por día. El día
     * que haga dos, devuelve 2 sin que haya que tocar nada.
     */
    private function nextSequence(int $cashBoxId, CarbonImmutable $date, Currency $currency): int
    {
        $ultimo = CashCount::query()
            ->where('cash_box_id', $cashBoxId)
            ->whereDate('counted_on', $date)
            ->where('currency', $currency)
            ->max('sequence');

        if ($ultimo === null) {
            return 1;
        }

        /*
         * Si el último todavía está en borrador se reemplaza; solo se
         * abre un turno nuevo cuando el anterior quedó firme.
         */
        $enBorrador = CashCount::query()
            ->where('cash_box_id', $cashBoxId)
            ->whereDate('counted_on', $date)
            ->where('currency', $currency)
            ->where('sequence', $ultimo)
            ->where('status', CashCountStatus::Draft)
            ->exists();

        return $enBorrador ? (int) $ultimo : (int) $ultimo + 1;
    }
}
