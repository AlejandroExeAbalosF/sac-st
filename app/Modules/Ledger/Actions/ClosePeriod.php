<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra el período de una caja y congela sus totales.
 *
 * Reproduce el anverso de la planilla: tres saldos de apertura, los
 * movimientos del período abiertos por hecho, y tres saldos de cierre que
 * la base calcula sola. Nada de eso viaja desde el navegador — todo sale
 * de `journal_lines`, que es la única fuente que no se puede maquillar.
 *
 * **Los movimientos pertenecen al período por su fecha operativa** y no
 * por un puntero guardado en cada uno (regla 1 del §9.9). Por eso el mismo
 * cobro integra el cierre diario y el mensual sin contarse dos veces, y por
 * eso la apertura se calcula como el saldo del libro al día anterior en vez
 * de encadenarse contra el cierre previo: si el encadenamiento fuera un
 * puntero, un cierre corregido dejaría a todos los siguientes mintiendo.
 */
final class ClosePeriod
{
    /**
     * Qué fila de la planilla recibe cada hecho.
     *
     * Es el mapa entre el libro y el papel. Un tipo de evento que no esté
     * acá **frena el cierre** en vez de desaparecer del cuadro: si un hecho
     * mueve la caja y la planilla no tiene renglón para él, lo que falta es
     * el renglón, y eso se decide con el área.
     *
     * @var array<string, 'opening'|'received'|'disbursed'|'deposited'>
     */
    private const DESTINO = [
        /*
         * La apertura no es un ingreso del día: es el saldo con el que el
         * día empieza. Cuando el asiento lleva la fecha de la víspera la
         * distinción no se nota —entra por el saldo al cierre anterior—,
         * pero abrir los libros y operar el mismo día es lo normal el día
         * que el sistema arranca, y ahí la planilla mostraba «SALDO
         * INICIAL $ 0,00» con ocho millones colgando de «INGRESOS».
         */
        FinancialEventType::OpeningBalance->value => 'opening',
        FinancialEventType::FundsReceived->value => 'received',
        FinancialEventType::CashDepositCredited->value => 'received',
        FinancialEventType::CashDisbursement->value => 'disbursed',
        FinancialEventType::BankDisbursement->value => 'disbursed',
        FinancialEventType::LegacyDisbursement->value => 'disbursed',
        FinancialEventType::CashDepositedToBank->value => 'deposited',
        /*
         * La asignación no mueve ninguna de las tres columnas: cambia de
         * quién es la plata, no dónde está. Aparece en el mapa para que su
         * ausencia no frene el cierre, y sus importes dan cero sobre las
         * cuentas de ubicación.
         */
        FinancialEventType::FundsAllocated->value => 'received',
    ];

    public function __construct(
        private readonly CashBalance $cashBalance,
        private readonly RecordAuditEvent $recordAuditEvent,
    ) {}

    /** @throws ValidationException */
    public function handle(
        int $cashBoxId,
        CarbonInterface $date,
        PeriodType $type = PeriodType::Daily,
        Currency $currency = Currency::Ars,
        ?int $actorId = null,
        ?string $notes = null,
    ): PeriodClosing {
        return DB::transaction(function () use (
            $cashBoxId, $date, $type, $currency, $actorId, $notes
        ): PeriodClosing {
            /*
             * Todas las escrituras que cambian la caja toman este candado
             * antes de leer saldos. Así un asiento no puede colarse entre el
             * snapshot y el cierre, ni un arqueo quedar contra un libro que
             * ya cambió.
             */
            CashBox::query()->lockForUpdate()->findOrFail($cashBoxId);

            $desde = $type->startsOn($date);
            $hasta = $type->endsOn($date);

            $this->assertClosable($cashBoxId, $desde, $hasta, $type, $currency);

            $vispera = $desde->subDay();

            $apertura = [
                'cash' => $this->cashBalance->of(LedgerAccount::CashOnHand, $cashBoxId, $currency, $vispera),
                'cheques' => $this->cashBalance->of(LedgerAccount::ChequesInCustody, $cashBoxId, $currency, $vispera),
                'bank' => $this->cashBalance->of(LedgerAccount::BankAccount, $cashBoxId, $currency, $vispera),
            ];

            $efectivo = $this->summarise(LedgerAccount::CashOnHand, $cashBoxId, $desde, $hasta, $currency);
            $cheques = $this->summarise(LedgerAccount::ChequesInCustody, $cashBoxId, $desde, $hasta, $currency);
            $banco = $this->summarise(LedgerAccount::BankAccount, $cashBoxId, $desde, $hasta, $currency);

            /*
             * Una apertura fechada dentro del período se suma al saldo
             * inicial, no a los ingresos. El saldo final no cambia —la
             * base lo calcula como apertura más ingresos menos egresos—,
             * cambia de qué renglón sale.
             */
            $apertura['cash'] = Decimal::add($apertura['cash'], $efectivo['opening']);
            $apertura['cheques'] = Decimal::add($apertura['cheques'], $cheques['opening']);
            $apertura['bank'] = Decimal::add($apertura['bank'], $banco['opening']);

            return DB::transaction(function () use (
                $cashBoxId, $currency, $type, $desde, $hasta, $apertura,
                $efectivo, $cheques, $banco, $actorId, $notes
            ): PeriodClosing {
                $cierre = PeriodClosing::query()->updateOrCreate(
                    [
                        'cash_box_id' => $cashBoxId,
                        'currency' => $currency,
                        'period_type' => $type,
                        'period_from' => $desde,
                        'period_to' => $hasta,
                    ],
                    [
                        'opening_cash' => $apertura['cash'],
                        'opening_cheques' => $apertura['cheques'],
                        'opening_bank_deposits' => $apertura['bank'],

                        'received_cash' => $efectivo['received'],
                        'received_cheques' => $cheques['received'],
                        'received_bank_deposits' => $banco['received'],

                        'disbursed_cash' => $efectivo['disbursed'],
                        'disbursed_cheques' => $cheques['disbursed'],
                        'disbursed_bank_deposits' => $banco['disbursed'],

                        'deposited_to_bank_cash' => $efectivo['deposited'],
                        'deposited_to_bank_cheques' => $cheques['deposited'],

                        'total_unassigned' => $this->cashBalance->of(
                            LedgerAccount::UnassignedFunds, $cashBoxId, $currency, $hasta
                        ),

                        'status' => PeriodClosingStatus::Closed,
                        'closed_by' => $actorId,
                        'closed_at' => now(),
                        'reopened_by' => null,
                        'reopened_at' => null,
                        'reopen_reason' => null,
                        'notes' => $notes,

                        /*
                         * Cada cierre estrena planilla. La del cierre anterior
                         * queda en `attachments` —pudo imprimirse y firmarse—
                         * pero deja de ser la de este período: sus números
                         * corresponden a un snapshot que ya no rige.
                         */
                        'sheet_attachment_id' => null,
                    ],
                );

                $cierre->refresh();

                $this->assertSnapshotMatchesLedger($cierre, $cashBoxId, $currency, $hasta);

                /*
                 * El arqueo que respalda el día se congela con él. Dejarlo
                 * revisado permitiría corregirlo después de archivado el papel,
                 * y el papel y la base dejarían de decir lo mismo.
                 */
                CashCount::query()
                    ->where('cash_box_id', $cashBoxId)
                    ->where('currency', $currency)
                    ->whereBetween('counted_on', [$desde, $hasta])
                    ->whereIn('status', [CashCountStatus::Reviewed, CashCountStatus::Adjusted])
                    ->update(['status' => CashCountStatus::Closed]);

                $this->recordAuditEvent->handle(
                    action: 'periodo.cerrado',
                    subject: $cierre,
                    after: [
                        'closing_cash' => $cierre->closing_cash,
                        'closing_cheques' => $cierre->closing_cheques,
                        'closing_bank_deposits' => $cierre->closing_bank_deposits,
                    ],
                    metadata: [
                        'periodo' => $type->value,
                        'desde' => $desde->toDateString(),
                        'hasta' => $hasta->toDateString(),
                    ],
                    actorId: $actorId,
                );

                return $cierre;
            });
        });
    }

    /**
     * Los movimientos de una cuenta, repartidos en las filas de la planilla.
     *
     * @return array{opening: numeric-string, received: numeric-string, disbursed: numeric-string, deposited: numeric-string}
     *
     * @throws ValidationException
     */
    private function summarise(
        LedgerAccount $account,
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency,
    ): array {
        $totales = ['opening' => '0.00', 'received' => '0.00', 'disbursed' => '0.00', 'deposited' => '0.00'];

        foreach ($this->cashBalance->movementsByEvent($account, $cashBoxId, $from, $to, $currency) as $hecho => $importes) {
            /*
             * El ajuste de arqueo no tiene renglón propio en la planilla, y
             * tampoco lo necesita: se suma al lado que corresponda según si
             * apareció o faltó plata. Es lo único honesto — el papel del
             * área nunca tuvo una fila «diferencia» y agregarla haría que el
             * Excel dejara de parecerse al que firman.
             */
            $destino = self::DESTINO[$hecho] ?? match ($hecho) {
                FinancialEventType::CashAdjustment->value,
                FinancialEventType::AuthorizedAdjustment->value => Decimal::equals($importes['debit'], '0')
                    ? 'disbursed'
                    : 'received',
                default => null,
            };

            if ($destino === null) {
                throw ValidationException::withMessages([
                    'period' => sprintf(
                        'El hecho «%s» movió la caja en este período y la planilla no tiene fila para él. '
                        .'Definí dónde va antes de cerrar.',
                        $hecho,
                    ),
                ]);
            }

            /*
             * Entra por el débito y sale por el crédito en las cuentas de
             * ubicación. Se suma el neto de la fila y no el bruto: un cobro
             * revertido llega acá con débito y crédito juntos —el original y
             * su reversión comparten hecho— y la planilla muestra el ingreso
             * que quedó, no los dos asientos.
             */
            // La apertura suma como un ingreso; solo cae en otra fila.
            $neto = $destino === 'received' || $destino === 'opening'
                ? Decimal::sub($importes['debit'], $importes['credit'])
                : Decimal::sub($importes['credit'], $importes['debit']);

            $totales[$destino] = Decimal::add($totales[$destino], $neto);
        }

        return $totales;
    }

    /**
     * El snapshot tiene que dar lo mismo que el libro.
     *
     * Es la red de seguridad de todo lo anterior. Si el reparto por filas
     * pierde o duplica un peso, el saldo final del cierre y el saldo real de
     * la cuenta se separan — y esto lo detecta antes de que el período quede
     * congelado con un número que nadie va a poder explicar después.
     *
     * @throws ValidationException
     */
    private function assertSnapshotMatchesLedger(
        PeriodClosing $closing,
        int $cashBoxId,
        Currency $currency,
        CarbonImmutable $to,
    ): void {
        $controles = [
            'efectivo' => [LedgerAccount::CashOnHand, $closing->closing_cash],
            'cheques' => [LedgerAccount::ChequesInCustody, $closing->closing_cheques],
            'depósitos directos' => [LedgerAccount::BankAccount, $closing->closing_bank_deposits],
        ];

        foreach ($controles as $nombre => [$cuenta, $snapshot]) {
            $libro = $this->cashBalance->of($cuenta, $cashBoxId, $currency, $to);

            if (Decimal::equals($libro, $snapshot)) {
                continue;
            }

            throw ValidationException::withMessages([
                'period' => sprintf(
                    'El cierre no cuadra en %s: la planilla da %s y el libro %s. No se cerró nada.',
                    $nombre,
                    Decimal::format($snapshot),
                    Decimal::format($libro),
                ),
            ]);
        }
    }

    /**
     * Las tres condiciones del invariante 30.
     *
     * **Acá viven tres, y el mensaje de las cuatro.** La regla completa
     * —el período terminado, sin eventos en borrador, sin importaciones en
     * curso, sin arqueos sin resolver— la impone el trigger
     * `period_closings_ready_to_close`, que es el que realmente no se puede
     * saltear. La del período terminado se comprueba en los dos lados con
     * relojes distintos, y la migración explica por qué el de la base tiene
     * un día de tolerancia.
     *
     * La de las importaciones no se comprueba en PHP y no es un olvido:
     * `bank_statement_imports` es de Banking, y **Ledger no puede depender
     * de Banking** —lo verifica un arch test—. En la base no hay módulos,
     * así que el trigger la alcanza sin romper nada.
     *
     * @throws ValidationException
     */
    private function assertClosable(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        PeriodType $type,
        Currency $currency,
    ): void {
        /*
         * Un período que no terminó no se cierra.
         *
         * El controlador ya rechaza una fecha futura, y para el cierre
         * diario alcanza. Para el mensual no: cerrar con la fecha de hoy
         * produce un período que llega hasta fin de mes, y desde ahí el
         * trigger de período cerrado rechaza **cada operación de los días
         * que faltan**. Es la diferencia entre un cierre equivocado —que
         * se reabre— y una caja que no admite trabajar.
         */
        if ($to->greaterThan(BusinessDate::today())) {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    'El cierre %s va hasta el %s, y esa fecha todavía no llegó. Un período se cierra cuando termina.',
                    mb_strtolower($type->label()),
                    $to->format('d/m/Y'),
                ),
            ]);
        }

        $cierreExistente = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', $type)
            ->whereDate('period_from', $from)
            ->whereDate('period_to', $to)
            ->lockForUpdate()
            ->first();

        if ($cierreExistente?->status === PeriodClosingStatus::Closed) {
            throw ValidationException::withMessages([
                'period' => 'Este período ya está cerrado. Reabrilo con motivo si hay que rehacerlo.',
            ]);
        }

        /*
         * Regla 5 del §9.9: no se cierra un período con eventos en
         * borrador. Un asiento a medio escribir no pesa en ningún saldo,
         * así que congelar los totales con uno pendiente sería archivar un
         * número que va a cambiar.
         *
         * Se miran los de esta moneda: un borrador en dólares no cambia
         * ningún total en pesos. El que todavía no tiene líneas frena las
         * dos, porque sin líneas no hay moneda a la cual atribuirlo.
         */
        $enBorrador = FinancialEvent::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('status', FinancialEventStatus::Draft)
            ->whereBetween('event_date', [$from, $to])
            ->where(function (Builder $query) use ($currency): void {
                $query
                    ->whereHas(
                        'journalLines',
                        fn (Builder $linea) => $linea->where('currency', $currency),
                    )
                    ->orWhereDoesntHave('journalLines');
            })
            ->count();

        if ($enBorrador > 0) {
            throw ValidationException::withMessages([
                'period' => $enBorrador === 1
                    ? 'Hay un asiento en borrador dentro del período. Asentalo o descartalo antes de cerrar.'
                    : "Hay {$enBorrador} asientos en borrador dentro del período. Asentalos o descartalos antes de cerrar.",
            ]);
        }

        /*
         * Y tampoco con arqueos sin resolver. El arqueo es lo que prueba
         * que el saldo del libro existe en billetes; cerrar el día con uno
         * a medio contar sería firmar el papel sin haber abierto el cajón.
         */
        $arqueoAbierto = CashCount::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->whereBetween('counted_on', [$from, $to])
            ->where('status', CashCountStatus::Draft)
            ->exists();

        if ($arqueoAbierto) {
            throw ValidationException::withMessages([
                'period' => 'Hay un arqueo en borrador dentro del período. Revisalo antes de cerrar.',
            ]);
        }

        if ($type === PeriodType::Monthly) {
            $this->assertEveryOperatedDayIsClosed($cashBoxId, $from, $to, $currency);
        }

        if ($type === PeriodType::Daily) {
            $this->assertDailyCountSupportsClosing(
                cashBoxId: $cashBoxId,
                date: $to,
                currency: $currency,
                reopenedAt: $cierreExistente?->status === PeriodClosingStatus::Reopened
                    ? $cierreExistente->reopened_at
                    : null,
            );
        }
    }

    /**
     * El mes no cierra sobre días que nunca se cerraron.
     *
     * El cierre mensual congela los totales del período, y hasta ahora los
     * calculaba del libro sin mirar si cada jornada había pasado por su
     * arqueo. Un mes podía quedar cerrado con quince días que nadie contó:
     * los números cerraban igual —salen de `journal_lines`— pero el control
     * diario, que es donde se detecta un faltante, no había ocurrido.
     *
     * **Se exigen los días con movimiento, no los del calendario.** Un
     * sábado sin un solo asiento no tiene nada que arquear ni que cerrar, y
     * pedirle una planilla obligaría a inventar treinta cierres vacíos por
     * mes. El día que hubo plata en juego es el día que tiene que estar
     * cerrado.
     *
     * @throws ValidationException
     */
    private function assertEveryOperatedDayIsClosed(
        int $cashBoxId,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Currency $currency,
    ): void {
        $conMovimiento = DB::table('financial_events')
            ->join('journal_lines', 'journal_lines.financial_event_id', '=', 'financial_events.id')
            ->where('financial_events.cash_box_id', $cashBoxId)
            ->whereIn('financial_events.status', [
                FinancialEventStatus::Posted->value,
                FinancialEventStatus::Reversed->value,
            ])
            ->whereBetween('financial_events.event_date', [$from->toDateString(), $to->toDateString()])
            ->where('journal_lines.currency', $currency->value)
            ->distinct()
            ->pluck('financial_events.event_date')
            ->map(fn (string $fecha): string => CarbonImmutable::parse($fecha)->toDateString())
            ->all();

        $cerrados = PeriodClosing::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->where('period_type', PeriodType::Daily)
            ->where('status', PeriodClosingStatus::Closed)
            ->whereBetween('period_from', [$from->toDateString(), $to->toDateString()])
            ->pluck('period_from')
            ->map(fn (mixed $fecha): string => CarbonImmutable::parse((string) $fecha)->toDateString())
            ->all();

        $faltan = array_values(array_diff($conMovimiento, $cerrados));
        sort($faltan);

        if ($faltan === []) {
            return;
        }

        $muestra = array_slice($faltan, 0, 5);
        $legibles = array_map(
            fn (string $fecha): string => CarbonImmutable::parse($fecha)->format('d/m'),
            $muestra,
        );

        throw ValidationException::withMessages([
            'period' => sprintf(
                'El mes no se cierra con %d día(s) con movimiento sin cerrar: %s%s. Cerralos antes.',
                count($faltan),
                implode(', ', $legibles),
                count($faltan) > count($muestra) ? '…' : '',
            ),
        ]);
    }

    /**
     * El cierre diario necesita una foto vigente del cajón.
     *
     * Un arqueo cerrado pertenece al snapshot anterior. Después de reabrir
     * y registrar una corrección no se lo puede reutilizar: se hace otro
     * conteo, con otra secuencia, y recién ese respalda el nuevo cierre.
     *
     * @throws ValidationException
     */
    private function assertDailyCountSupportsClosing(
        int $cashBoxId,
        CarbonImmutable $date,
        Currency $currency,
        ?CarbonInterface $reopenedAt,
    ): void {
        $arqueo = CashCount::query()
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', $currency)
            ->whereDate('counted_on', $date)
            ->orderByDesc('sequence')
            ->lockForUpdate()
            ->first();

        if ($arqueo === null) {
            throw ValidationException::withMessages([
                'period' => 'Antes de cerrar el día hay que contar el cajón y revisar el arqueo.',
            ]);
        }

        if (! in_array($arqueo->status, [CashCountStatus::Reviewed, CashCountStatus::Adjusted], true)) {
            throw ValidationException::withMessages([
                'period' => $arqueo->status === CashCountStatus::Closed
                    ? 'El arqueo pertenece al cierre anterior. Volvé a contar el cajón y revisá el nuevo arqueo antes de cerrar.'
                    : 'El último arqueo todavía no está revisado.',
            ]);
        }

        /*
         * Una diferencia sin resolver no se cierra encima.
         *
         * El cierre congela el saldo **del libro**, no el contado: cerrar
         * con una diferencia viva dejaba el día archivado diciendo que
         * había una plata que el conteo no encontró, y limpiarlo después
         * exige reabrir el período. El área definió que el día se cierra
         * cuando el arqueo está resuelto: o cuadra, o la diferencia se
         * imputó y el libro ya describe el cajón real.
         *
         * Un arqueo imputado llega acá como `adjusted` y su diferencia
         * quedó explicada en `CASH_DIFFERENCE`, así que no se lo vuelve a
         * mirar: lo que se exige es que un `reviewed` cuadre.
         */
        if ($arqueo->status === CashCountStatus::Reviewed && ! $arqueo->isBalanced()) {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    'El arqueo del día cierra con una diferencia de %s %s sin imputar. Imputala o volvé a contar el cajón antes de cerrar.',
                    $currency->symbol(),
                    Decimal::format(Decimal::abs($arqueo->difference_amount)),
                ),
            ]);
        }

        if ($reopenedAt !== null && $arqueo->counted_at->lessThan($reopenedAt)) {
            throw ValidationException::withMessages([
                'period' => 'El cierre fue reabierto después de este arqueo. Volvé a contar el cajón antes de cerrar otra vez.',
            ]);
        }

        $saldoActual = $this->cashBalance->of(
            LedgerAccount::CashOnHand,
            $cashBoxId,
            $currency,
            $date,
        );

        /*
         * Contra qué se compara el libro.
         *
         * **Imputar la diferencia mueve el libro a propósito**: el asiento
         * contra `CASH_DIFFERENCE` lo corre hasta igualar lo contado. Si
         * después se comparara contra el `expected_amount` —congelado
         * antes de la imputación— el sistema leería su propia
         * reconciliación como «el libro cambió» y pediría recontar un
         * cajón que ya está conciliado.
         *
         * Así que un arqueo imputado se compara contra lo que concluyó que
         * hay —contado más lo no recontado— y uno sin imputar, contra el
         * saldo que tenía el libro cuando se contó.
         */
        $saldoDelArqueo = $arqueo->status === CashCountStatus::Adjusted
            ? Decimal::add($arqueo->counted_amount, $arqueo->uncounted_amount)
            : $arqueo->expected_amount;

        if (! Decimal::equals($saldoDelArqueo, $saldoActual)) {
            throw ValidationException::withMessages([
                'period' => sprintf(
                    'El saldo del libro cambió desde el último arqueo: ahora es %s %s. Volvé a contar el cajón.',
                    $currency->symbol(),
                    Decimal::format($saldoActual),
                ),
            ]);
        }
    }
}
