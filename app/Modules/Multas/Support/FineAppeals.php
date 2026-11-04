<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Support\Money\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Lo que el trámite del recurso le permite a la multa (decisión del área,
 * 03/11/2026).
 *
 * Con el recurso en trámite la multa queda congelada en lo económico: no
 * admite otros pagos, antecedentes ni movimientos de importe. Después de que
 * prosperó, su importe tampoco se mueve: un ajuste no deshace el veredicto.
 * Los Actions consultan acá con la multa ya bloqueada; la base repite cada
 * regla.
 */
final class FineAppeals
{
    /** El recurso que no cayó, si hay: uno solo por multa. */
    public function current(Fine $multa): ?FineAppeal
    {
        return FineAppeal::query()
            ->where('fine_id', $multa->id)
            ->where('status', '<>', FineAppealStatus::Dropped)
            ->first();
    }

    /** @throws ValidationException */
    public function assertNotFrozen(Fine $multa, string $campo): void
    {
        if ($this->current($multa)?->status === FineAppealStatus::Open) {
            throw ValidationException::withMessages([
                $campo => 'La multa está en recurso: hasta resolverlo no admite otros pagos ni cambios de importe.',
            ]);
        }
    }

    /**
     * Un antecedente es un pago: además del congelamiento, no entra en una
     * multa que el recurso ya canceló.
     *
     * @throws ValidationException
     */
    public function assertAcceptsHistorical(Fine $multa, string $campo): void
    {
        $this->assertNotFrozen($multa, $campo);

        if ($this->current($multa)?->status === FineAppealStatus::Upheld) {
            throw ValidationException::withMessages([
                $campo => 'El recurso de la multa prosperó: quedó cancelada con lo cobrado.',
            ]);
        }
    }

    /** @throws ValidationException */
    public function assertAmountEditable(Fine $multa, string $campo): void
    {
        $this->assertNotFrozen($multa, $campo);

        if ($this->current($multa)?->status === FineAppealStatus::Upheld) {
            throw ValidationException::withMessages([
                $campo => 'El recurso de la multa prosperó: su importe ya no se modifica.',
            ]);
        }
    }

    /**
     * Se recurre una multa con deuda, sin pagos ni antecedentes vivos y sin
     * otro recurso que no haya caído.
     *
     * @throws ValidationException
     */
    public function assertAppealable(Fine $multa): void
    {
        if ($this->current($multa) !== null) {
            throw ValidationException::withMessages([
                'kind' => 'La multa ya tiene un recurso registrado: una multa admite uno solo. Si aquel se cargó mal, corregilo o descartalo.',
            ]);
        }

        $deuda = (string) FineBalance::query()->whereKey($multa->id)->value('debt');

        if (Decimal::equals($deuda, '0')) {
            throw ValidationException::withMessages(['amount' => 'La multa no tiene saldo pendiente: no hay recurso que pagar.']);
        }

        if ($this->hasPayments($multa)) {
            throw ValidationException::withMessages([
                'kind' => 'La multa ya tiene pagos: el recurso del 20 % se registra antes de cualquier pago.',
            ]);
        }
    }

    /** Lo que impide recurrir: un pago o un antecedente que no se descartó. */
    public function hasPayments(Fine $multa): bool
    {
        return $multa->payments()->live()->exists()
            || $multa->historicalMovements()->where('status', '<>', HistoricalMovementStatus::Rejected)->exists();
    }
}
