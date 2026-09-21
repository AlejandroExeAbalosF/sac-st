<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

/**
 * En qué punto del circuito está una cuota.
 *
 * **No reemplaza a `InstallmentWorkflowStatus`, lo completa.** Aquel dice
 * si la cuota está vigente, anulada o pagada —tres respuestas para todo el
 * recorrido—, y por eso una cuota con la Orden ya emitida figura como
 * «Pendiente», igual que una que todavía no cobró un peso. Esto contesta la
 * otra pregunta: *dónde está el dinero hoy*.
 *
 * ── Hechos, no permisos ────────────────────────────────────────────────
 *
 * Cada etapa se reconoce leyendo datos —si llegó el dinero, si hay recibo,
 * si hay traslado, si hay Orden, en qué estado está el egreso—, nunca
 * preguntando si se puede operar. Esa otra pregunta la contesta
 * `DisbursementEligibility`, que además evalúa la traba del §2.2.7 y el
 * orden de los comprobantes, y resolverla cuota por cuota costaría una
 * tanda de consultas por fila en pantallas que listan decenas.
 *
 * Es el mismo criterio de `TransferStage`, que deriva el estado del egreso
 * de dos fechas y de nada más.
 */
enum InstallmentStage: string
{
    /* ── Fuera del circuito ──────────────────────────────────────────── */

    case Cancelled = 'cancelled';
    case Blocked = 'blocked';
    case Suspended = 'suspended';

    /* ── Antes de que el dinero esté completo ────────────────────────── */

    /** Falta que llegue todo lo que la cuota espera. */
    case Unfunded = 'unfunded';

    /** Llegó completo y falta el comprobante que lo asienta. */
    case AwaitingReceipt = 'awaiting_receipt';

    /* ── El dinero, y dónde está ─────────────────────────────────────── */

    /** En el cajón, listo para entregarse en mano. */
    case InCashBox = 'in_cash_box';

    /**
     * Depositado y todavía sin acreditar.
     *
     * Ni en la caja ni en la cuenta: el §2.4.7 lo llama tránsito y no es
     * una formalidad —en esa ventana no se puede pagar de ninguna de las
     * dos formas—.
     */
    case DepositInTransit = 'deposit_in_transit';

    /** Acreditado en la cuenta del organismo, sin Orden todavía. */
    case AtBank = 'at_bank';

    /* ── El circuito bancario del egreso (§2.3) ──────────────────────── */

    case OrderIssued = 'order_issued';
    case TransferReported = 'transfer_reported';
    case DebitObserved = 'debit_observed';
    case ReadyToValidate = 'ready_to_validate';

    /** El egreso está confirmado: el dinero salió y el libro lo dice. */
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Cancelled => 'Anulada',
            self::Blocked => 'Bloqueada',
            self::Suspended => 'Suspendida',
            self::Unfunded => 'Sin financiar',
            self::AwaitingReceipt => 'Falta el recibo',
            self::InCashBox => 'En caja',
            self::DepositInTransit => 'Depositada, en tránsito',
            self::AtBank => 'En el banco',
            self::OrderIssued => 'Orden emitida',
            self::TransferReported => 'Transferencia informada',
            self::DebitObserved => 'Débito observado',
            self::ReadyToValidate => 'Lista para validar',
            self::Paid => 'Pagada',
        };
    }

    /**
     * Si la etapa espera que alguien haga algo ahora.
     *
     * Sirve para pintarla: lo que está esperando al banco no pide lo mismo
     * que lo que está esperando a una persona. `Unfunded` queda afuera
     * a propósito —esperar que el empleador deposite no es tarea nuestra—.
     */
    public function needsAction(): bool
    {
        return in_array($this, [
            self::AwaitingReceipt,
            self::InCashBox,
            self::AtBank,
            self::ReadyToValidate,
        ], true);
    }
}
