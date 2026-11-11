<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Models\User;
use App\Modules\Aranceles\Data\FeeCashShareData;
use App\Modules\Aranceles\Data\FeeDepositData;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\DepositableCash;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Attachments;
use Carbon\CarbonInterface;

/**
 * El efectivo de la caja de Aranceles camino al banco, como lo muestra la
 * pantalla: cuánto hay, qué lleva cada traspaso y de qué recibos sale.
 *
 * @phpstan-import-type Share from DepositableCash
 */
final class FeeDeposits
{
    public function __construct(
        private readonly FeeChargeFunds $fondos,
        private readonly DepositableCash $efectivo,
    ) {}

    public function cashBoxId(): int
    {
        return $this->fondos->cashBoxId();
    }

    public function owns(CashToBankTransfer $traslado): bool
    {
        return $traslado->cash_box_id === $this->cashBoxId();
    }

    /** @return list<Share> */
    public function available(CarbonInterface $fecha): array
    {
        return $this->efectivo->available($this->cashBoxId(), Currency::Ars, $fecha);
    }

    /**
     * @param  list<Share>  $partes
     * @return list<FeeCashShareData>
     */
    public function shares(array $partes): array
    {
        $cobros = $this->cobrosDe(array_map(fn (array $p): int => $p['receipt']->id, $partes));

        return array_map(
            fn (array $p): FeeCashShareData => $this->parte($p['receipt'], $p['amount'], $cobros),
            $partes,
        );
    }

    /**
     * Los traspasos en ese estado, del más viejo al más nuevo si esperan algo y
     * del más nuevo al más viejo si ya terminaron.
     *
     * @return list<FeeDepositData>
     */
    public function inStatus(CashTransferStatus $estado, ?User $viewer, ?int $limite = null): array
    {
        $traslados = CashToBankTransfer::query()
            ->with(['bankAccount', 'depositedBy:id,name', 'creditEvent', 'items.fundReceipt'])
            ->where('cash_box_id', $this->cashBoxId())
            ->where('status', $estado)
            ->orderBy('id', $limite === null ? 'asc' : 'desc')
            ->when($limite !== null, fn ($q) => $q->limit((int) $limite))
            ->get();

        $cobros = $this->cobrosDe(array_values($traslados
            ->flatMap(fn (CashToBankTransfer $t) => $t->items->pluck('fund_receipt_id'))
            ->unique()
            ->all()));

        // El vigente de cada traspaso: si el ticket se volvió a cargar, el último.
        $comprobantes = app(Attachments::class)->latestFor(
            AttachmentSubject::CashTransfer,
            $traslados->modelKeys(),
            $viewer,
            'cash_transfer_ticket',
        );

        return array_values($traslados->map(function (CashToBankTransfer $t) use ($cobros, $comprobantes): FeeDepositData {

            return new FeeDepositData(
                id: $t->id,
                status: $t->status,
                amount: $t->amount,
                depositDate: $t->deposit_date->toDateString(),
                registeredAt: $t->created_at->toIso8601String(),
                accountLabel: $t->bankAccount->label,
                depositedByName: $t->depositedBy?->name,
                ticketDate: $t->ticket_date?->toDateString(),
                operationNumber: $t->deposit_operation_number,
                terminal: $t->deposit_terminal,
                notes: $t->notes,
                creditedOn: $t->creditEvent?->event_date->toDateString(),
                ticketAttachment: $comprobantes[$t->id] ?? null,
                shares: array_values($t->items
                    ->sortBy('id')
                    ->map(fn ($item): FeeCashShareData => $this->parte($item->fundReceipt, $item->amount, $cobros))
                    ->all()),
            );
        })->all());
    }

    /** @param array<int, array{payerName: string|null, receiptNumber: string}> $cobros */
    private function parte(FundReceipt $recepcion, string $importe, array $cobros): FeeCashShareData
    {
        return new FeeCashShareData(
            amount: $importe,
            receivedOn: $recepcion->received_date->toDateString(),
            opening: $recepcion->origin === FundReceiptOrigin::Opening,
            receiptNumber: $cobros[$recepcion->id]['receiptNumber'] ?? null,
            payerName: $cobros[$recepcion->id]['payerName'] ?? null,
        );
    }

    /**
     * El recibo vigente del cobro que cada recepción respalda.
     *
     * @param  list<int>  $recepciones
     * @return array<int, array{payerName: string|null, receiptNumber: string}>
     */
    private function cobrosDe(array $recepciones): array
    {
        $recibos = Receipt::query()
            ->join('fee_charge_receipts AS cr', 'cr.receipt_id', '=', 'receipts.id')
            ->join('fee_charge_fundings AS f', 'f.fee_charge_id', '=', 'cr.fee_charge_id')
            ->whereIn('f.fund_receipt_id', $recepciones)
            ->whereNull('f.released_at')
            ->where('receipts.status', ReceiptStatus::Issued)
            ->select('receipts.*', 'f.fund_receipt_id')
            ->get();

        $cobros = [];

        foreach ($recibos as $recibo) {
            $cobros[(int) $recibo->getAttribute('fund_receipt_id')] = [
                'payerName' => $recibo->counterparty_name_snapshot,
                'receiptNumber' => $recibo->talonario_number ?? $recibo->formatted_number,
            ];
        }

        return $cobros;
    }
}
