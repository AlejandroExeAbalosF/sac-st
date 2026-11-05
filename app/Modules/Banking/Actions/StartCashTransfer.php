<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Banking\Support\DepositableCash;
use App\Modules\Banking\Support\WholeReceiptDeposits;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El primer paso del depósito en dos pasos: el efectivo sale de la caja.
 *
 * Se registra cuando sale, no cuando vuelve el comprobante: así el arqueo
 * coincide con el cajón y un comprobante pendiente no frena el cierre del día
 * ni los cobros siguientes. El dinero queda en `CASH_IN_TRANSIT` y el traslado,
 * esperando el comprobante (`RecordTransferTicket`).
 *
 * El importe se reparte desde la recepción más antigua. Toma las recepciones y
 * recién después asienta, que bloquea la caja: es el orden de anular y corregir.
 */
final class StartCashTransfer
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RecordAuditEvent $auditar,
        private readonly DepositableCash $efectivo,
        private readonly CashBalance $saldos,
        private readonly WholeReceiptDeposits $cuentas,
    ) {}

    /** @throws ValidationException */
    public function handle(
        int $cashBoxId,
        Currency $currency,
        string $importe,
        CarbonInterface $fecha,
        ?int $cuentaElegida,
        ?string $notas,
        string $idempotencyKey,
        ?int $actorId,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($cashBoxId, $currency, $importe, $fecha, $cuentaElegida, $notas, $idempotencyKey, $actorId): CashToBankTransfer {
            $this->efectivo->lock($cashBoxId, $currency, $fecha);

            /*
             * Un segundo envío del mismo formulario: el traspaso ya está. Se busca
             * después del bloqueo, porque un envío simultáneo recién se ve cuando
             * el primero confirma y suelta las recepciones.
             */
            $yaIniciado = CashToBankTransfer::query()
                ->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaIniciado !== null) {
                return $yaIniciado;
            }

            if (! (bool) CashBox::query()->whereKey($cashBoxId)->value('deposits_in_two_steps')) {
                throw ValidationException::withMessages(['amount' => 'Esta caja no deposita en dos pasos.']);
            }

            $importe = Decimal::scale($importe);

            if (! Decimal::isNegative(Decimal::sub('0', $importe))) {
                throw ValidationException::withMessages(['amount' => 'El importe tiene que ser mayor que cero.']);
            }

            $cuenta = $this->cuentas->depositAccount($cashBoxId, $currency, $cuentaElegida, $fecha)
                ?? throw ValidationException::withMessages([
                    'bankAccountId' => 'Esa cuenta no está habilitada para la caja en esa fecha.',
                ]);

            $disponibles = $this->efectivo->available($cashBoxId, $currency, $fecha);
            $total = $this->efectivo->total($disponibles);

            if (Decimal::isNegative(Decimal::sub($total, $importe))) {
                throw ValidationException::withMessages([
                    'amount' => 'Al '.$fecha->format('d/m/Y').' hay $ '.Decimal::format($total).' de efectivo para depositar.',
                ]);
            }

            $evento = $this->asentar->handle(
                type: FinancialEventType::CashDepositedToBank,
                idempotencyKey: $idempotencyKey,
                lines: [
                    EntryLine::debit(LedgerAccount::CashInTransit, $importe)->in($currency)->onBankAccount($cuenta)->onCashBox($cashBoxId),
                    EntryLine::credit(LedgerAccount::CashOnHand, $importe)->in($currency)->onCashBox($cashBoxId),
                ],
                date: $fecha,
                cashBoxId: $cashBoxId,
                description: $notas,
                actorId: $actorId,
            );

            // Con la caja ya bloqueada: un faltante ajustado no deja sacar más de lo que hay.
            $this->assertSaldoAlcanza($cashBoxId, $currency, $fecha);

            $traslado = CashToBankTransfer::query()->create([
                'deposit_event_id' => $evento->id,
                'cash_box_id' => $cashBoxId,
                'bank_account_id' => $cuenta,
                'amount' => $importe,
                'deposit_date' => $fecha,
                'deposited_by' => $actorId,
                'notes' => $notas,
                'status' => CashTransferStatus::AwaitingTicket,
            ]);

            foreach ($this->efectivo->distribute($disponibles, $importe) as $parte) {
                CashToBankTransferItem::query()->create([
                    'cash_to_bank_transfer_id' => $traslado->id,
                    'fund_receipt_id' => $parte['receipt']->id,
                    'amount' => $parte['amount'],
                ]);
            }

            $this->auditar->handle('traslado.iniciado', $traslado, after: [
                'amount' => $traslado->amount,
                'bank_account_id' => $traslado->bank_account_id,
                'deposit_date' => $traslado->deposit_date->toDateString(),
            ], actorId: $actorId);

            return $traslado;
        });
    }

    /**
     * El libro no queda en negativo ningún día desde el traspaso: uno con fecha
     * anterior también baja todos los días que vinieron después.
     *
     * @throws ValidationException
     */
    private function assertSaldoAlcanza(int $cashBoxId, Currency $currency, CarbonInterface $fecha): void
    {
        $minimo = $this->saldos->lowestSince(LedgerAccount::CashOnHand, $cashBoxId, $currency, $fecha);

        if (Decimal::isNegative($minimo['balance'])) {
            throw ValidationException::withMessages([
                'amount' => 'El libro de la caja no tiene tanto efectivo: al '.CarbonImmutable::parse($minimo['date'])->format('d/m/Y')
                    .' faltan $ '.Decimal::format(Decimal::abs($minimo['balance'])).'.',
            ]);
        }
    }
}
