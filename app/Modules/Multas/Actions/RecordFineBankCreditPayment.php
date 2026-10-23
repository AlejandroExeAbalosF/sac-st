<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un crédito viejo de la cuenta de Multas, identificado como pago de una
 * multa.
 *
 * Entró al banco antes de la apertura de la caja, así que está en el saldo
 * que el área traía: no entra al libro como cobro. Se
 * registra como pago anterior al sistema, con el movimiento que lo
 * respalda (DER físico §3.8 y §4), y cuenta en el saldo de la multa cuando
 * alguien con permiso de ajustar lo valida.
 *
 * **El importe es el del crédito entero**: un pago no se reparte en partes
 * ni entre multas (confirmado por el usuario). La fecha es la del banco.
 * Cada control lo repite la base (`fine_historical_bank_credit`).
 */
final class RecordFineBankCreditPayment
{
    public function __construct(
        private readonly FineBankCredits $creditos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Fine $multa, int $bankTransactionId, string $evidence, ?int $actorId): FineHistoricalMovement
    {
        return DB::transaction(function () use ($multa, $bankTransactionId, $evidence, $actorId): FineHistoricalMovement {
            $apertura = $this->creditos->book();

            if ($apertura === null) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => 'La caja de Multas todavía no se abrió con su cuenta bancaria: sin eso no se sabe qué créditos son anteriores.',
                ]);
            }

            /** @var BankTransaction|null $credito */
            $credito = BankTransaction::query()->lockForUpdate()->find($bankTransactionId);

            if ($credito === null || ! $this->creditos->eligible($apertura)->whereKey($credito->id)->exists()) {
                throw ValidationException::withMessages([
                    'bankTransactionId' => sprintf(
                        'Ese movimiento no puede respaldar un pago anterior: tiene que ser un crédito de la cuenta de Multas anterior al %s, '
                        .'sin imputar en un libro y sin otro pago anterior que lo use.',
                        $apertura->opened_on->format('d/m/Y'),
                    ),
                ]);
            }

            $antecedente = FineHistoricalMovement::query()->create([
                'fine_id' => $multa->id,
                'kind' => HistoricalMovementKind::Payment,
                'amount' => $credito->amount,
                'occurred_on' => $credito->transaction_date?->toDateString(),
                'occurred_precision' => DatePrecision::Day,
                'evidence_text' => trim($evidence),
                'bank_transaction_id' => $credito->id,
                'status' => HistoricalMovementStatus::PendingReview,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle(
                'multa.antecedente-registrado',
                $multa,
                metadata: [
                    'tipo' => HistoricalMovementKind::Payment->label(),
                    'amount' => $antecedente->amount,
                    'respaldo' => sprintf(
                        '%s · crédito del %s por $ %s',
                        $antecedente->evidence_text,
                        $credito->transaction_date?->format('d/m/Y') ?? 'sin fecha',
                        Decimal::format($credito->amount),
                    ),
                ],
                actorId: $actorId,
            );

            return $antecedente;
        });
    }
}
