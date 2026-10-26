<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\UndetailedCheques;
use App\Modules\Multas\Data\FineChequeInCustodyData;
use App\Modules\Multas\Data\FineChequeInventoryData;
use App\Modules\Multas\Models\Fine;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * La cartera de cheques de la caja de Multas, con la multa que paga cada uno.
 *
 * Es el detalle del saldo «Cheques en custodia» de la caja del día. Cada
 * cheque se identifica por la aplicación de la raíz de su cadena: el
 * reemplazo de un cheque rechazado paga la misma multa. Uno de la apertura
 * que todavía no se aplicó muestra lo que declaró la apertura.
 *
 * Es la cartera **de hoy**, como la de Haberes: el inventario a una fecha
 * pasada es el del reverso de la planilla, que se congela al cerrar.
 */
final class FineChequeInventory
{
    public function __construct(
        private readonly CashBalance $saldos,
        private readonly UndetailedCheques $sinDetallar,
    ) {}

    public function for(int $cashBoxId, bool $canOpenFines): FineChequeInventoryData
    {
        $cheques = FundReceipt::query()
            ->where('medium', PaymentMedium::Cheque->value)
            ->where('cheque_status', ChequeStatus::InCustody->value)
            ->whereNull('reversal_event_id')
            ->where('cash_box_id', $cashBoxId)
            ->where('currency', Currency::Ars->value)
            ->orderBy('received_date')
            ->orderBy('id')
            ->get();

        $multas = $this->multasDe(array_map(static fn (FundReceipt $cheque): int => $cheque->id, $cheques->all()));

        // La ficha cuelga del expediente: las multas se traen con el suyo, de una vez.
        $fichas = Fine::query()
            ->with('fineCase')
            ->whereIn('id', array_values(array_unique(array_map(static fn (object $fila): int => (int) $fila->fine_id, $multas))))
            ->get()
            ->mapWithKeys(fn (Fine $multa): array => [$multa->id => route('multas.show', $multa->showParameters(), absolute: false)])
            ->all();

        $lista = [];
        $detallado = '0.00';

        foreach ($cheques as $cheque) {
            $multa = $multas[$cheque->id] ?? null;

            $lista[] = new FineChequeInCustodyData(
                id: $cheque->id,
                number: $cheque->cheque_number,
                bank: $cheque->cheque_bank,
                issueDate: $cheque->cheque_issue_date?->toDateString(),
                amount: Decimal::scale($cheque->amount),
                receivedDate: $cheque->received_date->toDateString(),
                origin: $cheque->origin,
                fineId: $multa === null ? null : (int) $multa->fine_id,
                fineHref: $multa === null ? null : ($fichas[(int) $multa->fine_id] ?? null),
                caseLabel: $multa?->case_label,
                caption: $multa?->caption,
                declaredExpediente: $cheque->expediente_number_snapshot,
                declaredDrawer: $cheque->counterparty_name_snapshot,
            );

            $detallado = Decimal::add($detallado, Decimal::scale($cheque->amount));
        }

        return new FineChequeInventoryData(
            cheques: $lista,
            detailed: $detallado,
            undetailed: $this->sinDetallar->amount($cashBoxId, Currency::Ars),
            total: $this->saldos->of(LedgerAccount::ChequesInCustody, $cashBoxId, Currency::Ars),
            canOpenFines: $canOpenFines,
        );
    }

    /**
     * La multa de cada cheque, por la raíz de su cadena, con lo que su recibo
     * congeló: el expediente y la carátula del día del cobro.
     *
     * @param  array<int>  $chequeIds
     * @return array<int, object{fine_id: int, case_label: string|null, caption: string|null}>
     */
    private function multasDe(array $chequeIds): array
    {
        if ($chequeIds === []) {
            return [];
        }

        /** @var array<int, object{fine_id: int, case_label: string|null, caption: string|null}> */
        return DB::table('fund_receipts AS cheque')
            ->join('fine_payment_applications AS aplicacion', DB::raw('aplicacion.fund_receipt_id'), '=', DB::raw('fund_receipt_root(cheque.id)'))
            ->leftJoin('fine_payment_receipts AS papel', 'papel.fine_payment_id', '=', 'aplicacion.fine_payment_id')
            ->whereIn('cheque.id', $chequeIds)
            ->select(['cheque.id', 'aplicacion.fine_id'])
            ->selectRaw("papel.snapshot->>'caseLabel' AS case_label")
            ->selectRaw("papel.snapshot->>'caption' AS caption")
            ->get()
            ->keyBy('id')
            ->all();
    }
}
