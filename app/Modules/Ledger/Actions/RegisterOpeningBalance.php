<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Actions;

use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre los libros con lo que ya está en el cajón.
 *
 * El día que el sistema arranque va a haber plata física en la caja y
 * cheques en custodia que él no conoce. Sin este asiento, el saldo teórico
 * empieza en cero y **el primer arqueo da una diferencia igual a todo el
 * saldo histórico** — un número de ocho cifras que nadie puede explicar
 * porque no corresponde a ningún hecho.
 *
 * El contraasiento va a `LEGACY_FUNDS`, que es exactamente para esto:
 * *«saldos que vienen del sistema anterior sin respaldo documental
 * completo, separados para no ensuciar lo que sí se puede probar»*. Su
 * saldo baja a cero a medida que los casos viejos se pagan con
 * `legacy_disbursement`, y ahí se apaga la operación en paralelo.
 *
 * Con la planilla del 01/06/2026, el asiento es:
 *
 * ```text
 * Débito   CASH_ON_HAND         9.852.300,00
 * Débito   CHEQUES_IN_CUSTODY     673.804,70
 * Débito   BANK_ACCOUNT         1.902.907,01
 * Crédito  LEGACY_FUNDS        12.429.011,71
 * ```
 *
 * **Las tres columnas de la planilla, y las tres son saldos.** La tercera
 * —«DEPOSITOS DIRECTOS»— es lo que las empresas depositaron derecho en la
 * cuenta de la Secretaría: el área emite el recibo de ingreso cuando toma
 * conocimiento del depósito y el trabajador ya declaró su CBU, y desde ahí
 * el expediente sigue el circuito de la transferencia. Estuvo congelada
 * los veinte días de junio porque no hubo depósitos nuevos, no porque sea
 * un acumulado histórico: la planilla le calcula ingresos, egresos y saldo
 * final igual que al efectivo.
 *
 * Va como pata de `BANK_ACCOUNT` **con su cuenta bancaria**. Sin ella la
 * línea no dice dónde está el dinero, y `journal_lines` es append-only: no
 * se corrige después, se revierte.
 */
final class RegisterOpeningBalance
{
    public function __construct(
        private readonly PostJournalEntry $postJournalEntry,
        private readonly RecordCashCount $contarElCajon,
        private readonly ReviewCashCount $revisarElConteo,
    ) {}

    /**
     * @param  array<string, string>  $balances  Saldo inicial por cuenta, indexado
     *                                           por el valor de `LedgerAccount`. Solo cuentas de ubicación.
     * @param  list<array{number: string, bank: string, issueDate: string, amount: string, expediente?: ?string, company?: ?string, beneficiary?: ?string}>  $cheques
     * @param  array<int|string, int|string>  $denominations  Los billetes del cajón, por denominación.
     *
     * @throws ValidationException
     */
    public function handle(
        int $cashBoxId,
        array $balances,
        CarbonInterface $date,
        Currency $currency = Currency::Ars,
        ?int $bankAccountId = null,
        ?int $actorId = null,
        ?string $notes = null,
        array $cheques = [],
        array $denominations = [],
    ): FinancialEvent {
        $saldos = $this->assertUsable($balances, $cashBoxId, $currency, $bankAccountId);
        $carteraDeCheques = $this->assertChequesMatchTheBalance($cheques, $saldos);
        $billetes = $this->assertCashIsCounted($denominations, $saldos);

        $lineas = [];
        $total = '0.00';

        /*
         * Detallada la cartera, los cheques no van en este asiento: cada
         * uno trae el suyo. `fund_receipts.financial_event_id` es único
         * —una recepción por hecho— y es la restricción correcta: un
         * cheque en custodia es una recepción, acá y en el resto del
         * sistema. Sin detalle, el saldo entra como un total y el
         * inventario del reverso arranca vacío.
         */
        if ($carteraDeCheques !== []) {
            unset($saldos[LedgerAccount::ChequesInCustody->value]);
        }

        foreach ($saldos as $cuenta => $importe) {
            $lineas[] = EntryLine::debit(LedgerAccount::from($cuenta), $importe)
                ->in($currency)
                ->onCashBox($cashBoxId)
                /*
                 * Solo la pata bancaria lleva cuenta; en las demás el dato
                 * no existe y `onBankAccount(null)` la deja como estaba.
                 */
                ->onBankAccount($cuenta === LedgerAccount::BankAccount->value ? $bankAccountId : null)
                ->describedAs('Saldo inicial del sistema');

            $total = Decimal::add($total, $importe);
        }

        $lineas[] = EntryLine::credit(LedgerAccount::LegacyFunds, $total)
            ->in($currency)
            ->onCashBox($cashBoxId)
            ->describedAs('Fondos del sistema anterior');

        return DB::transaction(function () use (
            $lineas, $cashBoxId, $currency, $date, $notes, $actorId,
            $carteraDeCheques, $billetes
        ): FinancialEvent {
            $evento = $this->postOpeningEntry($lineas, $cashBoxId, $currency, $date, $notes, $actorId);

            foreach ($carteraDeCheques as $cheque) {
                $this->storeCheque($cheque, $cashBoxId, $currency, $date, $actorId);
            }

            $this->countTheDrawer($billetes, $cashBoxId, $currency, $date, $actorId);

            return $evento;
        });
    }

    /**
     * @param  list<EntryLine>  $lineas
     */
    private function postOpeningEntry(
        array $lineas,
        int $cashBoxId,
        Currency $currency,
        CarbonInterface $date,
        ?string $notes,
        ?int $actorId,
    ): FinancialEvent {
        return $this->postJournalEntry->handle(
            type: FinancialEventType::OpeningBalance,
            /*
             * La clave lo impide sin depender de que alguien se acuerde de
             * mirar antes: un segundo intento devuelve el asiento que ya
             * existe en vez de duplicar el saldo histórico.
             */
            idempotencyKey: self::keyFor($cashBoxId, $currency),
            lines: $lineas,
            date: $date,
            cashBoxId: $cashBoxId,
            description: $notes ?? 'Apertura de los libros con el saldo existente',
            actorId: $actorId,
        );
    }

    /**
     * Un cheque del cajón, con su asiento y su recepción.
     *
     * El saldo de `CHEQUES_IN_CUSTODY` dice cuánto valen; esto dice cuáles
     * son, que es lo que el reverso lista y lo único que permite
     * cotejarlos contra el papel.
     *
     * Lleva su propia clave de idempotencia con el número adentro: repetir
     * la apertura devuelve los mismos cheques en vez de duplicar la
     * cartera, igual que el asiento principal.
     *
     * @param  array{number: string, bank: string, issueDate: string, amount: numeric-string, expediente: ?string, company: ?string, beneficiary: ?string}  $cheque
     */
    private function storeCheque(
        array $cheque,
        int $cashBoxId,
        Currency $currency,
        CarbonInterface $date,
        ?int $actorId,
    ): void {
        $evento = $this->postJournalEntry->handle(
            type: FinancialEventType::OpeningBalance,
            idempotencyKey: sprintf(
                '%s:cheque:%s',
                self::keyFor($cashBoxId, $currency),
                $cheque['number'],
            ),
            lines: [
                EntryLine::debit(LedgerAccount::ChequesInCustody, $cheque['amount'])
                    ->in($currency)
                    ->onCashBox($cashBoxId)
                    ->describedAs('Cheque en cartera al abrir los libros'),
                EntryLine::credit(LedgerAccount::LegacyFunds, $cheque['amount'])
                    ->in($currency)
                    ->onCashBox($cashBoxId)
                    ->describedAs('Fondos del sistema anterior'),
            ],
            date: $date,
            cashBoxId: $cashBoxId,
            description: sprintf('Cheque %s %s', $cheque['bank'], $cheque['number']),
            actorId: $actorId,
        );

        if (FundReceipt::query()->where('financial_event_id', $evento->id)->exists()) {
            return;
        }

        FundReceipt::query()->create([
            'financial_event_id' => $evento->id,
            'cash_box_id' => $cashBoxId,
            'currency' => $currency,
            'medium' => PaymentMedium::Cheque,
            'amount' => $cheque['amount'],
            'received_date' => $date,
            'received_by' => $actorId,
            'cheque_number' => $cheque['number'],
            'cheque_bank' => $cheque['bank'],
            'cheque_issue_date' => $cheque['issueDate'],
            'cheque_status' => ChequeStatus::InCustody,
            'expediente_number_snapshot' => $cheque['expediente'],
            'counterparty_name_snapshot' => $cheque['company'],
            'beneficiary_name_snapshot' => $cheque['beneficiary'],
        ]);
    }

    /**
     * El efectivo de la apertura se cuenta billete por billete.
     *
     * Es la única vez que sale barato: se hace una sola vez, y de ahí en
     * más ese fajo se arrastra sin recontarse. Sin su composición, el día
     * que alguien lo abra para buscar un faltante no tiene contra qué
     * comparar —el sistema sabría cuánto vale y no de qué está hecho—.
     *
     * Se exige el detalle, no un total: el importe declarado tiene que
     * salir de los billetes, igual que en cualquier arqueo.
     *
     * @param  array<int|string, int|string>  $denominations
     * @param  array<string, numeric-string>  $saldos
     * @return array<int, int>
     *
     * @throws ValidationException
     */
    private function assertCashIsCounted(array $denominations, array $saldos): array
    {
        $billetes = [];
        $contado = '0.00';

        foreach ($denominations as $denominacion => $cantidad) {
            $cuantos = (int) $cantidad;

            if ($cuantos <= 0) {
                continue;
            }

            $billetes[(int) $denominacion] = $cuantos;
            $contado = Decimal::add(
                $contado,
                Decimal::scale((string) ((int) $denominacion * $cuantos)),
            );
        }

        $efectivo = $saldos[LedgerAccount::CashOnHand->value] ?? null;

        if ($efectivo === null) {
            return [];
        }

        if (Decimal::equals($contado, '0')) {
            throw ValidationException::withMessages([
                'denominations' => 'El efectivo de la apertura se cuenta por denominación: sin los billetes, el fajo arranca sin composición y después no hay contra qué recontarlo.',
            ]);
        }

        if (! Decimal::equals($contado, $efectivo)) {
            throw ValidationException::withMessages([
                'denominations' => sprintf(
                    'Los billetes suman %s y el efectivo declarado es %s. Tienen que coincidir.',
                    $contado,
                    $efectivo,
                ),
            ]);
        }

        return $billetes;
    }

    /**
     * El arqueo que deja escrita la composición del cajón.
     *
     * Abrir los libros **es** contar el cajón, así que se guarda como lo
     * que es: un arqueo del día de apertura, en las mismas tablas que
     * cualquier otro. De ahí sale la composición del fajo que después se
     * arrastra, y contra la que un recuento futuro se puede comparar.
     *
     * **Nace revisado por quien abrió**, con la marca de que no hubo
     * segunda firma. Dejarlo en borrador trabaría el cierre del primer
     * período hasta que alguien lo revisara, y abrir los libros ya es un
     * acto reservado al administrador: es él quien atestigua ese conteo.
     *
     * @param  array<int, int>  $denominations
     */
    private function countTheDrawer(
        array $denominations,
        int $cashBoxId,
        Currency $currency,
        CarbonInterface $date,
        ?int $actorId,
    ): void {
        if ($denominations === []) {
            return;
        }

        $arqueo = $this->contarElCajon->handle(
            cashBoxId: $cashBoxId,
            countedOn: $date,
            denominations: $denominations,
            currency: $currency,
            actorId: $actorId,
        );

        if ($actorId !== null) {
            $this->revisarElConteo->handle($arqueo, $actorId);
        }
    }

    /**
     * La cartera declarada tiene que sumar el saldo declarado.
     *
     * Es el mismo control cruzado que la base impone al arqueo, donde el
     * total tiene que coincidir con sus denominaciones: dos formas de
     * decir lo mismo que no coinciden significan que una de las dos está
     * mal, y no hay manera de saber cuál.
     *
     * Cargar el saldo sin detallar los cheques sigue permitido --el área
     * puede no tenerlos a mano el día que abre--, y en ese caso el
     * inventario del reverso arranca vacío hasta que cada cheque se cobre.
     *
     * @param  list<array{number: string, bank: string, issueDate: string, amount: string, expediente?: ?string, company?: ?string, beneficiary?: ?string}>  $cheques
     * @param  array<string, numeric-string>  $saldos
     * @return list<array{number: string, bank: string, issueDate: string, amount: numeric-string, expediente: ?string, company: ?string, beneficiary: ?string}>
     *
     * @throws ValidationException
     */
    private function assertChequesMatchTheBalance(array $cheques, array $saldos): array
    {
        if ($cheques === []) {
            return [];
        }

        $declarado = $saldos[LedgerAccount::ChequesInCustody->value] ?? null;

        if ($declarado === null) {
            throw ValidationException::withMessages([
                'cheques' => 'Se detallaron cheques pero el saldo de cheques en cartera quedó en cero.',
            ]);
        }

        $cartera = [];
        $suma = '0.00';

        foreach ($cheques as $indice => $cheque) {
            foreach (['number', 'bank', 'issueDate'] as $campo) {
                if (trim($cheque[$campo]) === '') {
                    throw ValidationException::withMessages([
                        "cheques.{$indice}.{$campo}" => 'Un cheque sin este dato no se puede identificar en el cajón.',
                    ]);
                }
            }

            $importe = Decimal::scale($cheque['amount']);

            if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
                throw ValidationException::withMessages([
                    "cheques.{$indice}.amount" => 'El importe del cheque tiene que ser mayor que cero.',
                ]);
            }

            $suma = Decimal::add($suma, $importe);
            $cartera[] = [
                'number' => trim($cheque['number']),
                'bank' => trim($cheque['bank']),
                'issueDate' => trim($cheque['issueDate']),
                'amount' => $importe,
                'expediente' => self::texto($cheque['expediente'] ?? null),
                'company' => self::texto($cheque['company'] ?? null),
                'beneficiary' => self::texto($cheque['beneficiary'] ?? null),
            ];
        }

        if (! Decimal::equals($suma, $declarado)) {
            throw ValidationException::withMessages([
                'cheques' => sprintf(
                    'Los cheques detallados suman %s y el saldo declarado es %s. Tienen que coincidir.',
                    $suma,
                    $declarado,
                ),
            ]);
        }

        return $cartera;
    }

    /** Lo que vino vacío se guarda como ausente, no como cadena vacía. */
    private static function texto(?string $valor): ?string
    {
        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * @param  array<string, string>  $balances
     * @return array<string, numeric-string>
     *
     * @throws ValidationException
     */
    private function assertUsable(
        array $balances,
        int $cashBoxId,
        Currency $currency,
        ?int $bankAccountId,
    ): array {
        $saldos = [];

        foreach ($balances as $codigo => $importe) {
            $cuenta = LedgerAccount::tryFrom($codigo);

            if ($cuenta === null) {
                throw ValidationException::withMessages([
                    'balances' => "«{$codigo}» no es una cuenta del plan.",
                ]);
            }

            /*
             * Solo se abren cuentas de ubicación: dónde está la plata. Las
             * de atribución —de quién es— no se declaran en la apertura,
             * porque decir «esto es del beneficiario X» exige el expediente
             * y la cuota que el sistema todavía no tiene. Ese es justamente
             * el trabajo que `LEGACY_FUNDS` posterga.
             */
            if (! $cuenta->isLocation()) {
                throw ValidationException::withMessages([
                    'balances' => sprintf(
                        'La apertura declara dónde está el dinero, no de quién es: «%s» no puede llevar saldo inicial.',
                        $cuenta->label(),
                    ),
                ]);
            }

            $escalado = Decimal::scale($importe);

            if (Decimal::isNegative($escalado)) {
                throw ValidationException::withMessages([
                    'balances' => sprintf('El saldo inicial de «%s» no puede ser negativo.', $cuenta->label()),
                ]);
            }

            if (Decimal::equals($escalado, '0')) {
                continue;
            }

            $saldos[$cuenta->value] = $escalado;
        }

        if ($saldos === []) {
            throw ValidationException::withMessages([
                'balances' => 'La apertura necesita al menos un saldo distinto de cero.',
            ]);
        }

        /*
         * «DEPOSITOS DIRECTOS» abre una pata bancaria, y una línea de
         * `BANK_ACCOUNT` sin cuenta no dice dónde está el dinero. El libro
         * no admite corregirla: es append-only.
         */
        if (isset($saldos[LedgerAccount::BankAccount->value]) && $bankAccountId === null) {
            throw ValidationException::withMessages([
                'bankAccountId' => 'El saldo de depósitos directos tiene que decir en qué cuenta bancaria está.',
            ]);
        }

        /*
         * Abrir dos veces duplicaría el saldo histórico, y la clave de
         * idempotencia ya lo impide. Esto existe para decirlo con un
         * mensaje entendible en vez de devolver en silencio un asiento
         * viejo que el operador no pidió.
         *
         * Se busca **el asiento de apertura**, no el saldo de
         * `LEGACY_FUNDS`: ese saldo baja a cero a medida que los casos
         * viejos se pagan con `legacy_disbursement`, y el día que llegara a
         * cero una comprobación por saldo daría vía libre para abrir la
         * caja de nuevo.
         */
        $yaAbierta = FinancialEvent::query()
            ->where('idempotency_key', self::keyFor($cashBoxId, $currency))
            ->exists();

        if ($yaAbierta) {
            throw ValidationException::withMessages([
                'balances' => sprintf(
                    'Esta caja ya tiene una apertura registrada en %s. Revertila antes de volver a abrirla.',
                    $currency->label(),
                ),
            ]);
        }

        return $saldos;
    }

    /**
     * La apertura de una caja, si ya se hizo.
     *
     * Es pública porque la pantalla necesita la misma respuesta que el
     * Action: si la caja ya está abierta no hay formulario que mostrar,
     * hay un asiento que leer. Preguntarlo por separado —contando líneas,
     * mirando saldos— abriría la puerta a que las dos den distinto.
     */
    public function existingFor(int $cashBoxId, Currency $currency = Currency::Ars): ?FinancialEvent
    {
        return FinancialEvent::query()
            ->where('idempotency_key', self::keyFor($cashBoxId, $currency))
            ->first();
    }

    /** Una caja se abre una sola vez por moneda. */
    private static function keyFor(int $cashBoxId, Currency $currency): string
    {
        return "opening-balance:{$cashBoxId}:{$currency->value}";
    }
}
