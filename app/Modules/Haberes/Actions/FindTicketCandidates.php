<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Support\TicketCandidate;
use App\Modules\Haberes\Support\TicketSearchResult;
use App\Support\Money\Decimal;

/**
 * Busca en el extracto el movimiento que corresponde a un ticket.
 *
 * No vincula nada: propone. Quien confirma es una persona, porque plata
 * mal atribuida es un trabajador que cobra lo que no le toca.
 */
final class FindTicketCandidates
{
    /**
     * Cuántos días después del depósito se sigue buscando.
     *
     * La ventana es asimétrica y eso no es un detalle: **el dinero no
     * puede acreditarse antes de depositarse**. Hacia adelante hay margen
     * —depósito después del cierre, fin de semana, feriado largo—; hacia
     * atrás solo se admite un día, y únicamente por si quien cargó el
     * ticket tipeó mal la fecha.
     */
    private const DAYS_FORWARD = 5;

    private const DAYS_BACKWARD = 1;

    public function handle(DepositTicket $ticket): TicketSearchResult
    {
        $desde = $ticket->deposited_at->copy()->subDays(self::DAYS_BACKWARD);
        $hasta = $ticket->deposited_at->copy()->addDays(self::DAYS_FORWARD);

        /*
         * Filtros duros. Lo que no los cumple no es «peor candidato»: no
         * es candidato.
         *
         * El importe se compara exacto, sin tolerancia, y conviene decir
         * por qué: en el extracto real las comisiones son movimientos
         * separados —causal 3914—, así que el crédito entra completo. Si
         * el importe difiere, es otro depósito.
         */
        $movimientos = BankTransaction::query()
            ->where('bank_account_id', $ticket->bank_account_id)
            ->where('direction', TransactionDirection::Credit->value)
            ->whereBetween('transaction_date', [$desde->toDateString(), $hasta->toDateString()])
            ->whereRaw('amount = ?::numeric', [$ticket->amount])
            // Un depósito es un movimiento y un ticket es un depósito: lo
            // que ya tiene dueño no se ofrece de nuevo.
            ->whereNotExists(function ($query) use ($ticket): void {
                $query->selectRaw('1')
                    ->from('deposit_tickets')
                    ->whereColumn('deposit_tickets.bank_transaction_id', 'bank_transactions.id')
                    ->where('deposit_tickets.id', '!=', $ticket->id);
            })
            ->orderBy('transaction_date')
            ->get();

        /*
         * El documento del empleador del expediente. Es lo que permite
         * decir «este crédito viene de quien tiene que venir» —o avisar
         * que no—, y es el único de los datos del sistema que el banco a
         * veces informa: aparece embebido en el concepto de las
         * transferencias, nunca en un depósito por ventanilla.
         */
        $documentoEmpleador = $ticket->expediente->employer?->document;

        /** @var list<TicketCandidate> $candidatos */
        $candidatos = array_values(
            $movimientos
                ->map(fn (BankTransaction $movimiento): TicketCandidate => $this->describe(
                    $ticket,
                    $movimiento,
                    $documentoEmpleador,
                ))
                ->sortBy(fn (TicketCandidate $candidato): int => $candidato->rank())
                ->all()
        );

        return new TicketSearchResult(
            candidates: $candidatos,
            periodImported: $this->periodIsImported($ticket),
            warnings: $this->coherenceWarnings($ticket),
        );
    }

    private function describe(
        DepositTicket $ticket,
        BankTransaction $movimiento,
        ?string $documentoEmpleador,
    ): TicketCandidate {
        $fecha = $movimiento->transaction_date;
        $dias = $fecha === null ? 0 : (int) $ticket->deposited_at->diffInDays($fecha, false);

        $operacion = $this->operationMatches($ticket, $movimiento);

        $signals = ['importe' => 'exacto'];

        $signals['fecha'] = match (true) {
            $dias === 0 => 'el mismo día',
            $dias === 1 => 'el día siguiente',
            $dias > 1 => "{$dias} días después",
            default => 'un día antes',
        };

        if ($operacion !== null) {
            $signals['operación'] = $operacion;
        }

        /*
         * El CUIT del concepto contra el del empleador del expediente.
         *
         * Que coincida es la confirmación más fuerte que puede dar el
         * banco. Que **no** coincida no descarta el movimiento —un
         * empleador puede depositar desde la cuenta de su contador, de un
         * apoderado o de una empresa vinculada— pero quien confirma tiene
         * que verlo antes de hacerlo, no enterarse después.
         */
        $cuitMovimiento = $movimiento->counterparty_identifier;
        $cuitCoincide = false;

        if ($cuitMovimiento !== null) {
            $cuitCoincide = $documentoEmpleador !== null
                && $this->onlyDigits($cuitMovimiento) === $this->onlyDigits($documentoEmpleador);

            $signals['CUIT del concepto'] = $cuitCoincide
                ? $cuitMovimiento.' — es el del empleador'
                : $cuitMovimiento.($documentoEmpleador === null
                    ? ' — el empleador del expediente no tiene documento cargado'
                    : ' — NO es el del empleador del expediente');
        }

        return new TicketCandidate(
            transaction: $movimiento,
            dayGap: $dias,
            operationMatches: $operacion !== null,
            employerMatches: $cuitCoincide,
            signals: $signals,
        );
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * ¿El número del ticket aparece en el movimiento?
     *
     * Se busca en dos lugares porque el extracto lo esconde de dos formas:
     * en la columna `Referencia` y, a veces, dentro del propio concepto
     * (`995979830 - Numero de Operacion`).
     *
     * **Esto es una prueba, no una certeza.** No hay evidencia de que el
     * número del ticket y el del extracto pertenezcan al mismo espacio de
     * numeración; se registra cada coincidencia para poder responder con
     * datos, más adelante, si este campo sirvió alguna vez.
     */
    private function operationMatches(DepositTicket $ticket, BankTransaction $movimiento): ?string
    {
        $numero = trim((string) $ticket->operation_number);

        if ($numero === '') {
            return null;
        }

        if ($movimiento->operation_id !== null && trim($movimiento->operation_id) === $numero) {
            return 'coincide con la referencia del extracto';
        }

        if ($movimiento->description !== null && str_contains($movimiento->description, $numero)) {
            return 'aparece dentro del concepto';
        }

        return null;
    }

    /**
     * ¿Hay algún extracto importado que cubra la fecha del ticket?
     *
     * Es lo que separa «todavía no llegó» de «no aparece».
     */
    private function periodIsImported(DepositTicket $ticket): bool
    {
        return BankStatementImport::query()
            ->where('bank_account_id', $ticket->bank_account_id)
            ->whereNotNull('period_from')
            ->whereNotNull('period_to')
            ->whereDate('period_from', '<=', $ticket->deposited_at)
            ->whereDate('period_to', '>=', $ticket->deposited_at)
            ->exists();
    }

    /**
     * Incoherencias entre el ticket y lo que el expediente dice.
     *
     * No impiden vincular —un empleador puede depositar desde la cuenta de
     * un tercero, y una cuota puede recibir varios ingresos— pero quien
     * confirma tiene que verlas antes, no después.
     *
     * @return list<string>
     */
    private function coherenceWarnings(DepositTicket $ticket): array
    {
        $avisos = [];

        $cuota = $ticket->installment;

        if ($cuota !== null && ! Decimal::equals($cuota->expected_amount, $ticket->amount)) {
            $avisos[] = sprintf(
                'El ticket es por $%s y la cuota espera $%s. Puede ser un pago parcial, '
                .'o la cuota equivocada.',
                Decimal::format($ticket->amount),
                Decimal::format($cuota->expected_amount),
            );
        }

        return $avisos;
    }
}
