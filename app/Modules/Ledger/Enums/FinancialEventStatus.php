<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * El estado de un hecho monetario.
 *
 * Son tres y el camino entre ellos es de una sola dirección: un borrador
 * se postea, un posteado se revierte. Lo que **no** existe es la vuelta
 * atrás —de posteado a borrador—, y lo impide un trigger, no una
 * validación: ese camino sería el que permite maquillar un asiento ya
 * asentado.
 */
enum FinancialEventStatus: string
{
    /**
     * Armado pero sin efecto contable.
     *
     * Es el único estado en el que el asiento puede no cerrar, porque
     * todavía se está escribiendo. Ningún saldo lo cuenta.
     */
    case Draft = 'draft';

    /** Asentado. Balancea, cuenta para todos los saldos y ya no se edita. */
    case Posted = 'posted';

    /** Anulado por un evento de reversión, que queda apuntando a este. */
    case Reversed = 'reversed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Posted => 'Asentado',
            self::Reversed => 'Revertido',
        };
    }

    /** Si el evento pesa en los saldos. Solo lo asentado cuenta. */
    public function affectsBalances(): bool
    {
        return $this === self::Posted;
    }
}
