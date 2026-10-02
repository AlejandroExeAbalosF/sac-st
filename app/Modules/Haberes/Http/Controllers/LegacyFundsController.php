<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\FundInstallmentFromLegacy;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Http\Requests\FundInstallmentFromLegacyRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * La plata del sistema anterior que todavía está en custodia, apartada
 * para la cuota de su expediente histórico.
 */
final class LegacyFundsController extends Controller
{
    public function store(
        FundInstallmentFromLegacyRequest $request,
        BeneficiaryInstallment $installment,
        FundInstallmentFromLegacy $apartar,
    ): RedirectResponse {
        $medio = PaymentMedium::from((string) $request->validated('medium'));
        $cuenta = $request->validated('bankAccountId');
        $numero = $request->validated('incomeNumber');

        $apartar->handle(
            installment: $installment,
            medium: $medio,
            sources: $this->sources($request, $medio, $installment),
            income: is_string($numero)
                ? new LegacyPaper(
                    kind: LegacyDocumentKind::IncomeReceipt,
                    field: 'income',
                    number: $numero,
                    issuedOn: CarbonImmutable::parse((string) $request->validated('incomeDate')),
                    amount: Decimal::parse((string) $request->validated('incomeAmount')) ?? '0.00',
                    photo: $request->file('incomePhoto'),
                )
                : null,
            idempotencyKey: (string) $request->validated('idempotencyKey'),
            bankAccountId: is_numeric($cuenta) ? (int) $cuenta : null,
            actorId: $request->user()?->id,
            confirmDuplicates: $request->boolean('confirmDuplicates'),
        );

        Toast::success('Plata apartada.', 'La cuota quedó financiada con fondos del sistema anterior.');

        return back();
    }

    /**
     * De dónde sale la plata.
     *
     * El efectivo y el depósito directo salen enteros: el importe es el de
     * la cuota y el navegador no tiene por qué proponer otro. Los cheques
     * dicen cuánto aporta cada uno.
     *
     * @return list<array{amount: numeric-string, chequeReceiptId?: int|null}>
     */
    private function sources(FundInstallmentFromLegacyRequest $request, PaymentMedium $medio, BeneficiaryInstallment $installment): array
    {
        if ($medio !== PaymentMedium::Cheque) {
            return [['amount' => $installment->importeEsperado()]];
        }

        $cheques = $request->validated('cheques');
        $fuentes = [];

        foreach (is_array($cheques) ? $cheques : [] as $cheque) {
            if (! is_array($cheque)) {
                continue;
            }

            $fuentes[] = [
                'amount' => Decimal::parse((string) ($cheque['amount'] ?? '')) ?? '0.00',
                'chequeReceiptId' => (int) ($cheque['receiptId'] ?? 0),
            ];
        }

        return $fuentes;
    }
}
