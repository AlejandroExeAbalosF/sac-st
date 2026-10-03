<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\FundInstallmentFromLegacy;
use App\Modules\Haberes\Actions\VoidLegacyIncomeDocument;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Http\Requests\FundInstallmentFromLegacyRequest;
use App\Modules\Haberes\Http\Requests\VoidLegacySettlementRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

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
     * Anula un recibo de papel mal cargado, para cargar el correcto en el
     * próximo apartado.
     */
    public function voidPaper(
        VoidLegacySettlementRequest $request,
        BeneficiaryInstallment $installment,
        VoidLegacyIncomeDocument $anular,
    ): RedirectResponse {
        $anular->handle(
            installment: $installment,
            reason: (string) $request->validated('reason'),
            actorId: $request->user()?->id,
        );

        Toast::success('Recibo de papel anulado.', 'Al volver a apartar se carga el correcto.');

        return back();
    }

    /**
     * De dónde sale la plata.
     *
     * El efectivo y el depósito directo salen enteros: el importe es el de
     * la cuota y el navegador no tiene por qué proponer otro. Los cheques de
     * la lista aportan su importe entero, y el que se identifica ahora cubre
     * lo que falta: ningún importe se toma del navegador.
     *
     * @return list<array{amount: numeric-string, chequeReceiptId?: int|null, newCheque?: array{number: string, bank: string|null, issueDate: string|null}|null}>
     */
    private function sources(FundInstallmentFromLegacyRequest $request, PaymentMedium $medio, BeneficiaryInstallment $installment): array
    {
        if ($medio !== PaymentMedium::Cheque) {
            return [['amount' => $installment->importeEsperado()]];
        }

        $cheques = $request->validated('cheques');
        $deLaCartera = [];
        $nuevo = null;

        foreach (is_array($cheques) ? $cheques : [] as $cheque) {
            if (! is_array($cheque)) {
                continue;
            }

            /*
             * Un cheque de la lista aporta su importe entero: el papel no se
             * reparte. Se lee del cheque, no de la pantalla.
             */
            if (isset($cheque['receiptId']) && is_numeric($cheque['receiptId'])) {
                $deLaCartera[] = [
                    'amount' => Decimal::scale((string) (FundReceipt::query()->whereKey((int) $cheque['receiptId'])->value('amount') ?? '0')),
                    'chequeReceiptId' => (int) $cheque['receiptId'],
                ];

                continue;
            }

            if ($nuevo !== null) {
                throw ValidationException::withMessages([
                    'cheques' => 'Se identifica un cheque nuevo por vez.',
                ]);
            }

            $banco = isset($cheque['bank']) && is_string($cheque['bank']) ? trim($cheque['bank']) : '';

            $nuevo = [
                'number' => (string) ($cheque['number'] ?? ''),
                'bank' => $banco === '' ? null : $banco,
                'issueDate' => isset($cheque['issueDate']) && is_string($cheque['issueDate']) ? $cheque['issueDate'] : null,
            ];
        }

        if ($nuevo === null) {
            return $deLaCartera;
        }

        // El cheque nuevo cubre lo que falta; los de la lista no pueden pasarse.

        $falta = $installment->importeEsperado();

        foreach ($deLaCartera as $fuente) {
            $falta = Decimal::sub($falta, $fuente['amount']);
        }

        if (Decimal::isNegative($falta) || Decimal::equals($falta, '0')) {
            throw ValidationException::withMessages([
                'cheques' => 'Los cheques de la lista ya cubren la cuota: el cheque nuevo sobra.',
            ]);
        }

        return [...$deLaCartera, ['amount' => $falta, 'newCheque' => $nuevo]];
    }
}
