<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\CashCountScope;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CarryRecount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayTakings;
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
        private readonly CashDayTakings $cashDayTakings,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  array<int|string, int>  $denominations  Cantidad de billetes, indexada
     *                                                 por denominación. Las cantidades en cero se descartan.
     * @param  CarryRecount|null  $carryRecount  Si además se abrió el fajo de días
     *                                           anteriores y se lo contó.
     *
     * @throws ValidationException
     */
    public function handle(
        int $cashBoxId,
        CarbonInterface $countedOn,
        array $denominations,
        Currency $currency = Currency::Ars,
        ?int $actorId = null,
        ?string $explanation = null,
        ?int $sequence = null,
        ?CarryRecount $carryRecount = null,
    ): CashCount {
        return $this->record(
            cashBoxId: $cashBoxId,
            countedOn: $countedOn,
            denominations: $denominations,
            currency: $currency,
            actorId: $actorId,
            explanation: $explanation,
            sequence: $sequence,
            carryRecount: $carryRecount,
            openingCount: false,
        );
    }

    /**
     * Guarda el conteo con el que se abren los libros.
     *
     * En la apertura se cuenta el cajón entero: todavía no existe una
     * recaudación de la jornada que separar de un fajo anterior. Esta puerta
     * interna evita que esa excepción se pueda elegir desde el formulario.
     *
     * @param  array<int|string, int>  $denominations
     */
    public function handleOpening(
        int $cashBoxId,
        CarbonInterface $countedOn,
        array $denominations,
        Currency $currency = Currency::Ars,
        ?int $actorId = null,
    ): CashCount {
        return $this->record(
            cashBoxId: $cashBoxId,
            countedOn: $countedOn,
            denominations: $denominations,
            currency: $currency,
            actorId: $actorId,
            explanation: null,
            sequence: null,
            carryRecount: null,
            openingCount: true,
        );
    }

    /** @param array<int|string, int> $denominations */
    private function record(
        int $cashBoxId,
        CarbonInterface $countedOn,
        array $denominations,
        Currency $currency,
        ?int $actorId,
        ?string $explanation,
        ?int $sequence,
        ?CarryRecount $carryRecount,
        bool $openingCount,
    ): CashCount {
        $fecha = CarbonImmutable::parse($countedOn)->startOfDay();
        $lineas = $this->assertCountable($denominations);

        $lineasDelFajo = [];
        $contadoDelFajo = null;

        if ($carryRecount !== null) {
            $this->assertRecountIsCoherent($carryRecount);

            $lineasDelFajo = $this->assertCountable($carryRecount->denominations);
            $contadoDelFajo = $this->totalDe($lineasDelFajo);
        }

        /*
         * Los billetes del fajo están físicamente en el cajón, así que
         * entran en el total contado y la resta contra el libro no cambia.
         * Lo que las columnas del recuento agregan es **atribución** —qué
         * parte de lo contado salió del fajo—, no un segundo cálculo.
         */
        $contadoDelDia = $this->totalDe($lineas);
        $contado = Decimal::add($contadoDelDia, $contadoDelFajo ?? '0.00');

        return DB::transaction(function () use (
            $cashBoxId, $fecha, $currency, $lineas, $contadoDelDia, $contado,
            $explanation, $actorId, $sequence, $openingCount,
            $carryRecount, $lineasDelFajo, $contadoDelFajo
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

            /*
             * Lo que el libro dice que hay en el fajo: el saldo teórico
             * menos lo que entró hoy y sigue en el cajón. Es la misma
             * cuenta que la pantalla muestra como saldo del día anterior,
             * hecha acá para que el número contra el que se compara el
             * recuento no lo elija quien cuenta.
             */
            $arrastreDelLibro = $this->carryOnTheBooks($cashBoxId, $currency, $fecha, $teorico);
            $noRecontado = $openingCount || $carryRecount !== null ? '0.00' : $arrastreDelLibro;
            $esperadoDelFajo = $carryRecount === null ? null : $arrastreDelLibro;
            $esperadoDelDia = $openingCount
                ? $teorico
                : Decimal::sub($teorico, $arrastreDelLibro);
            $diferenciaDelDia = Decimal::sub($contadoDelDia, $esperadoDelDia);
            $diferenciaTotal = Decimal::sub($contado, $teorico);

            /*
             * Con el fajo abierto se contó el cajón entero. Si el total
             * cuadra, un faltante en una parte compensado por un sobrante
             * en la otra solo describe cómo se separaron billetes fungibles;
             * no es una diferencia monetaria que haya que justificar.
             */
            $exigeExplicacion = ! Decimal::equals($diferenciaDelDia, '0')
                && ($carryRecount === null || ! Decimal::equals($diferenciaTotal, '0'));

            if ($exigeExplicacion && ($explanation === null || trim($explanation) === '')) {
                throw ValidationException::withMessages([
                    'explanation' => sprintf(
                        'La recaudación del día tiene una diferencia de %s %s y exige explicación.',
                        $currency->symbol(),
                        Decimal::format(Decimal::abs($diferenciaDelDia)),
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
                'carry_recount_reason' => $carryRecount === null ? null : trim($carryRecount->reason),
                'carry_expected_amount' => $esperadoDelFajo,
                'carry_counted_amount' => $contadoDelFajo,
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
            $arqueo->allLines()->delete();

            $arqueo->allLines()->createMany([
                ...$this->rows($lineas, CashCountScope::Day),
                ...$this->rows($lineasDelFajo, CashCountScope::Carry),
            ]);

            $this->recordAuditEvent->handle(
                action: $anterior === null ? 'arqueo.registrado' : 'arqueo.corregido',
                subject: $arqueo,
                before: $anterior,
                after: $arqueo->only(['counted_amount', 'uncounted_amount', 'expected_amount']),
                metadata: [
                    'denominaciones' => count($lineas),
                    ...($carryRecount === null ? [] : [
                        'recuento_del_fajo' => [
                            'motivo' => trim($carryRecount->reason),
                            'segun_el_libro' => $esperadoDelFajo,
                            'encontrado' => $contadoDelFajo,
                        ],
                    ]),
                ],
                actorId: $actorId,
            );

            return $arqueo->refresh();
        });
    }

    /**
     * El total de un conjunto de líneas, sin pasar por punto flotante.
     *
     * @param  array<int, int>  $lineas
     * @return numeric-string
     */
    private function totalDe(array $lineas): string
    {
        $total = '0.00';

        foreach ($lineas as $denominacion => $cantidad) {
            $total = Decimal::add(
                $total,
                bcmul(Decimal::scale((string) $denominacion), (string) $cantidad, 2),
            );
        }

        return $total;
    }

    /**
     * Las filas de `cash_count_lines` de uno de los dos conteos.
     *
     * @param  array<int, int>  $lineas
     * @return list<array{scope: CashCountScope, denomination: numeric-string, quantity: int}>
     */
    private function rows(array $lineas, CashCountScope $scope): array
    {
        return array_map(
            fn (int $denominacion): array => [
                'scope' => $scope,
                'denomination' => Decimal::scale((string) $denominacion),
                'quantity' => $lineas[$denominacion],
            ],
            array_keys($lineas),
        );
    }

    /**
     * Abrir el fajo pide un motivo.
     *
     * El motivo es obligatorio justamente porque recontar es excepcional:
     * a diferencia del «por qué no se recontó» —que se llenaba todas las
     * tardes y terminó siendo ruido—, este se escribe una vez cada tanto
     * y es lo único que explica por qué ese día alguien abrió el fondo.
     *
     * @throws ValidationException
     */
    private function assertRecountIsCoherent(CarryRecount $recuento): void
    {
        if (trim($recuento->reason) === '') {
            throw ValidationException::withMessages([
                'carryRecount.reason' => 'Abrir el fajo de días anteriores exige decir por qué.',
            ]);
        }

    }

    /**
     * Lo que el libro dice que hay en el fajo de días anteriores.
     *
     * Saldo teórico menos la recaudación del día —lo que entró hoy y no
     * volvió a salir—. Nunca negativo: si se pagó más de lo que entró, el
     * resto salió del fondo y el fajo quedó más chico, no en rojo.
     *
     * @param  numeric-string  $teorico
     * @return numeric-string
     */
    private function carryOnTheBooks(
        int $cashBoxId,
        Currency $currency,
        CarbonImmutable $date,
        string $teorico,
    ): string {
        $arrastre = Decimal::sub(
            $teorico,
            $this->cashDayTakings->of($cashBoxId, $date, $currency),
        );

        return Decimal::isNegative($arrastre) ? '0.00' : $arrastre;
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
