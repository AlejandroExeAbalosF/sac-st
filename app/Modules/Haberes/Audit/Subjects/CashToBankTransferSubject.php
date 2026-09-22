<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit\Subjects;

use App\Models\User;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Audit\InstallmentLinks;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;
use App\Support\Money\Decimal;

/**
 * El efectivo de una cuota, camino al banco.
 *
 * La tabla es de Banking, pero el traslado se mira en la tarjeta de la
 * cuota: Haberes es quien sabe de quién era el efectivo.
 */
final class CashToBankTransferSubject extends BaseAuditSubjectResolver
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function subjectType(): string
    {
        return 'CashToBankTransfer';
    }

    public function label(): string
    {
        return 'Traslado de efectivo al banco';
    }

    public function fields(): array
    {
        return [
            'amount' => 'Importe',
            'bank_account_id' => 'Cuenta',
            'bank_transaction_id' => 'Movimiento del extracto',
            'deposit_date' => 'Fecha del depósito',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $traslados = CashToBankTransfer::query()->whereIn('id', $ids)->get(['id', 'amount']);

        // La cuota de cada traslado, por el primer ítem que la nombra.
        $cuotaDe = CashToBankTransferItem::query()
            ->whereIn('cash_to_bank_transfer_id', $ids)
            ->whereNotNull('funding_allocation_id')
            ->orderBy('id')
            ->get(['cash_to_bank_transfer_id', 'funding_allocation_id'])
            ->unique('cash_to_bank_transfer_id')
            ->mapWithKeys(fn (CashToBankTransferItem $item): array => [
                $item->cash_to_bank_transfer_id => $item->funding_allocation_id,
            ]);

        $asignaciones = FundingAllocation::query()
            ->whereIn('id', $cuotaDe->values()->all())
            ->pluck('beneficiary_installment_id', 'id');

        $cuotas = $this->links->load(array_values(array_map('intval', $asignaciones->values()->all())));

        $descripciones = [];

        foreach ($traslados as $traslado) {
            $asignacion = $cuotaDe->get($traslado->id);
            $cuota = $cuotas[(int) $asignaciones->get($asignacion)] ?? null;

            $descripciones[$traslado->id] = new AuditSubjectDescription(
                "Traslado n.º {$traslado->id} de $ ".Decimal::format((string) $traslado->amount)
                    .($cuota === null ? '' : ' · '.$this->links->context($cuota)),
                $this->links->url($cuota, $viewer),
            );
        }

        return $descripciones;
    }
}
