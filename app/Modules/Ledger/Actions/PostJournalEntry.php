<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Asienta un hecho monetario.
 *
 * Es la única puerta por la que se escribe en el libro. Todo lo que mueve
 * dinero en el sistema —la recepción de fondos, la asignación a una cuota,
 * y mañana los pagos— termina acá, y por eso los controles viven en un
 * solo lugar en vez de repetirse en cada circuito.
 *
 * El balance se comprueba dos veces a propósito: acá, para dar un mensaje
 * que el operador entienda, y en la base con un trigger diferido, que es
 * el que realmente lo impide. Si algún día alguien escribe un asiento
 * salteándose este Action, la base lo va a rechazar igual.
 */
final class PostJournalEntry
{
    /**
     * @param  list<EntryLine>  $lines
     *
     * @throws ValidationException si el asiento no cierra
     */
    public function handle(
        FinancialEventType $type,
        string $idempotencyKey,
        array $lines,
        CarbonInterface $date,
        ?int $cashBoxId = null,
        ?string $description = null,
        ?int $actorId = null,
        /*
         * Qué evento deshace, y por qué.
         *
         * Los dos van juntos o no van: la base lo impone con
         * `financial_events_reversal_check` y
         * `financial_events_reversal_reason_check`. Una reversión sin
         * motivo sería un asiento que mueve plata sin decir a cuenta de
         * qué, y eso es exactamente lo que el rastro existe para evitar.
         */
        ?int $reversalOfId = null,
        ?string $reversalReason = null,
    ): FinancialEvent {
        $this->assertBalances($lines);
        $currencies = array_values(array_unique(array_map(
            static fn (EntryLine $line): string => $line->currency->value,
            $lines,
        )));

        try {
            return DB::transaction(function () use (
                $type, $idempotencyKey, $lines, $date, $cashBoxId, $description,
                $actorId, $reversalOfId, $reversalReason, $currencies
            ): FinancialEvent {
                /*
                 * El mismo hecho no se asienta dos veces.
                 *
                 * El escenario no es hipotético: el operador hace doble
                 * clic en «Registrar recepción» y sin esto el crédito
                 * bancario queda asentado por duplicado. Devolver el
                 * evento que ya existe —en vez de fallar— es lo que hace
                 * que el segundo clic sea inocuo.
                 */
                $yaAsentado = FinancialEvent::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($yaAsentado !== null) {
                    return $yaAsentado;
                }

                if ($cashBoxId !== null) {
                    /* Ver el comentario equivalente en ClosePeriod. */
                    CashBox::query()->lockForUpdate()->findOrFail($cashBoxId);
                }

                $this->assertPeriodOpen($cashBoxId, $date, $currencies);

                $evento = FinancialEvent::query()->create([
                    'cash_box_id' => $cashBoxId,
                    'event_type' => $type,
                    'event_date' => $date,
                    'status' => FinancialEventStatus::Posted,
                    'idempotency_key' => $idempotencyKey,
                    'description' => $description,
                    'reversal_of_id' => $reversalOfId,
                    'reversal_reason' => $reversalReason,
                    'created_by' => $actorId,
                    'posted_by' => $actorId,
                    'posted_at' => now(),
                ]);

                JournalLine::query()->insert(
                    array_map(
                        fn (EntryLine $linea): array => [
                            ...$linea->toRow((int) $evento->id),
                            'created_at' => now(),
                        ],
                        $lines,
                    )
                );

                return $evento;
            });
        } catch (UniqueConstraintViolationException) {
            /*
             * Dos pedidos simultáneos con la misma clave: uno escribió y
             * el otro llegó tarde. La transacción perdedora ya revirtió,
             * así que alcanza con devolver la que ganó — que es
             * exactamente el resultado que el llamador esperaba.
             */
            return FinancialEvent::query()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /**
     * La caja no puede estar cerrada para esa fecha.
     *
     * **El trigger `financial_events_period_open` ya lo impide**, y esto no
     * lo reemplaza: lo traduce. Sin esta comprobación el operador recibía
     * una `QueryException` con la traza de PostgreSQL al intentar emitir un
     * recibo, sin enterarse de que el problema era un cierre ni de dónde se
     * arregla.
     *
     * Es el reparto de siempre —el Action da el mensaje legible, la base
     * impide el desastre—, con la vuelta de tuerca de que acá el mensaje
     * lleva el cierre entero: la pantalla necesita poder llevar hasta él.
     *
     * Un evento sin caja no se comprueba, igual que en el trigger: no hay
     * forma de saber a qué cierre pertenecería.
     *
     * @param  list<string>  $currencies
     *
     * @throws ClosedPeriodException
     */
    private function assertPeriodOpen(?int $cashBoxId, CarbonInterface $date, array $currencies): void
    {
        if ($cashBoxId === null) {
            return;
        }

        $cierre = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->whereIn('currency', $currencies)
            ->where('status', PeriodClosingStatus::Closed)
            ->whereDate('period_from', '<=', $date)
            ->whereDate('period_to', '>=', $date)
            ->orderByDesc('period_to')
            ->first();

        if ($cierre !== null) {
            throw ClosedPeriodException::covering($cierre, $date);
        }

        /*
         * Y tampoco uno que invalide un cierre posterior.
         *
         * **La apertura de cada cierre es el saldo del libro a su
         * víspera**, así que un asiento con fecha anterior cambia ese
         * saldo y deja al cierre guardado diciendo otra cosa que el
         * libro. Cerrar el 15 dejando el 14 abierto es legítimo; cargar
         * después un recibo en el 14 dejaba al 15 desactualizado por el
         * importe, en silencio y con la planilla del 15 posiblemente ya
         * impresa.
         *
         * Se nombra el más antiguo de los cierres afectados: es el que
         * hay que reabrir, y reabriéndolo caen los que le siguen.
         */
        $posterior = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->whereIn('currency', $currencies)
            ->where('status', PeriodClosingStatus::Closed)
            ->whereDate('period_from', '>', $date)
            ->orderBy('period_from')
            ->first();

        if ($posterior !== null) {
            throw ClosedPeriodException::invalidatedBy($posterior, $date);
        }
    }

    /**
     * @param  list<EntryLine>  $lines
     *
     * @throws ValidationException
     */
    private function assertBalances(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages([
                'lines' => 'Un asiento necesita al menos dos líneas: de dónde sale el dinero y a dónde va.',
            ]);
        }

        /*
         * El asiento cierra **por moneda**, no en total.
         *
         * Un débito de 100 dólares contra un crédito de 100 pesos suma
         * igual de los dos lados y no significa nada. El trigger
         * `journal_entry_must_balance()` lo rechaza en la base; acá se
         * comprueba lo mismo para poder decirlo en castellano.
         *
         * @var array<string, array{debit: string, credit: string}> $porMoneda
         */
        $porMoneda = [];

        foreach ($lines as $linea) {
            $moneda = $linea->currency->value;
            $acumulado = $porMoneda[$moneda] ?? ['debit' => '0.00', 'credit' => '0.00'];

            $porMoneda[$moneda] = [
                'debit' => Decimal::add($acumulado['debit'], $linea->debit),
                'credit' => Decimal::add($acumulado['credit'], $linea->credit),
            ];
        }

        foreach ($porMoneda as $moneda => $totales) {
            if (Decimal::equals($totales['debit'], $totales['credit'])) {
                continue;
            }

            $simbolo = Currency::from($moneda)->symbol();

            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'El asiento no cierra en %s: %s %s en débitos contra %s %s en créditos.',
                    Currency::from($moneda)->label(),
                    $simbolo,
                    Decimal::format($totales['debit']),
                    $simbolo,
                    Decimal::format($totales['credit']),
                ),
            ]);
        }
    }
}
