<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Declara que un movimiento no entra en la contabilidad del circuito.
 *
 * Es para lo que nunca va a tener un evento financiero detrás: la
 * transferencia entre dos cuentas del propio organismo del 3861, o una
 * comisión que no acompaña a ningún pago del circuito. Sin esta salida esos
 * movimientos quedarían para siempre en la cola de pendientes y la
 * volverían inútil.
 *
 * La comisión del 3914 que acompaña a la transferencia de un egreso **no**
 * va acá: la absorbe el beneficiario y se imputa al validar el pago, junto
 * con la transferencia.
 *
 * **No borra ni oculta nada.** El movimiento sigue existiendo, sigue
 * sumando en el saldo y sigue siendo conciliable; lo único que cambia es
 * que deja de reclamar atención. Por eso exige motivo y responsable: es
 * una decisión de alguien, no un estado que el sistema deduce.
 *
 * **Decide con el movimiento releído bajo bloqueo**, no con el que trajo la
 * pantalla. Entre que alguien abre el diálogo y confirma, otro puede haber
 * validado el pago que lo imputa: decidir con el estado viejo dejaría fuera
 * del circuito un movimiento conciliado. El bloqueo es el mismo que toman
 * las imputaciones (`AllocatableAmount::forUpdate`), así que las dos
 * operaciones se ordenan. La base lo repite
 * (`bank_transactions_ignore_only_unused`), y además rechaza excluir lo que
 * reserva un egreso vivo, que Banking no conoce.
 */
final class IgnoreBankTransaction
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly AllocatableAmount $disponible,
    ) {}

    public function handle(BankTransaction $transaction, string $reason, int $userId): BankTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $userId): BankTransaction {
            /** @var BankTransaction $movimiento */
            $movimiento = BankTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($movimiento->reconciliation_status === ReconciliationStatus::Ignored) {
                throw new RuntimeException('El movimiento ya estaba marcado como fuera del circuito.');
            }

            if ($movimiento->reconciliation_status !== ReconciliationStatus::Pending
                || ! Decimal::equals($this->disponible->allocated($movimiento), '0')) {
                throw new RuntimeException(
                    'El movimiento ya tiene imputaciones contables: hay que revertirlas antes de dejarlo fuera del circuito.'
                );
            }

            $before = $movimiento->reconciliation_status->value;

            try {
                $movimiento->forceFill([
                    'reconciliation_status' => ReconciliationStatus::Ignored,
                    'ignored_reason' => mb_substr(trim($reason), 0, 300),
                    'ignored_by' => $userId,
                    'ignored_at' => now(),
                ])->save();
            } catch (QueryException $e) {
                // La guarda de la base: lo reserva un pago en curso.
                if ($e->getCode() !== '23001') {
                    throw $e;
                }

                throw new RuntimeException(
                    'El movimiento está vinculado a un pago en curso: hay que desvincularlo antes de dejarlo fuera del circuito.',
                    previous: $e,
                );
            }

            $this->auditar->handle(
                'banco.movimiento.fuera-del-circuito',
                $movimiento,
                before: ['reconciliation_status' => $before],
                after: [
                    'reconciliation_status' => ReconciliationStatus::Ignored->value,
                    'ignored_reason' => $movimiento->ignored_reason,
                ],
                actorId: $userId,
            );

            return $movimiento;
        });
    }
}
