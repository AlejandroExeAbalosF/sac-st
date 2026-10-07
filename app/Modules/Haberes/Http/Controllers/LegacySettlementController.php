<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\RecordLegacySettlement;
use App\Modules\Haberes\Actions\VoidLegacySettlement;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Http\Requests\RecordLegacySettlementRequest;
use App\Modules\Haberes\Http\Requests\VoidLegacySettlementRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * Las cuotas que se pagaron fuera del circuito.
 *
 * Es lo que hace posible cargar un expediente histórico completo: el
 * expediente, el haber y las cuotas se cargan como siempre, y las que ya
 * se pagaron se registran acá con sus papeles.
 */
final class LegacySettlementController extends Controller
{
    public function store(
        RecordLegacySettlementRequest $request,
        BeneficiaryInstallment $installment,
        RecordLegacySettlement $registrar,
    ): RedirectResponse {
        $registrar->handle(
            installment: $installment,
            income: $this->paper($request, 'income', LegacyDocumentKind::IncomeReceipt),
            paidOn: CarbonImmutable::parse((string) $request->validated('paidOn')),
            paymentMedium: PaymentMedium::from((string) $request->validated('paymentMedium')),
            order: $this->paper($request, 'order', LegacyDocumentKind::PaymentOrder),
            expense: $this->paper($request, 'expense', LegacyDocumentKind::ExpenseReceipt),
            notes: $request->validated('notes'),
            actorId: $request->user()?->id,
            confirmDuplicates: $request->boolean('confirmDuplicates'),
        );

        Toast::success('Pago registrado.', 'La cuota quedó pagada fuera del circuito.');

        return back();
    }

    public function void(
        VoidLegacySettlementRequest $request,
        BeneficiaryInstallment $installment,
        VoidLegacySettlement $anular,
    ): RedirectResponse {
        $anular->handle(
            installment: $installment,
            reason: (string) $request->validated('reason'),
            actorId: $request->user()?->id,
        );

        Toast::success('Registro anulado.', 'La cuota vuelve a estar pendiente.');

        return back();
    }

    /** El papel de un prefijo, si se cargó su número. */
    private function paper(RecordLegacySettlementRequest $request, string $prefijo, LegacyDocumentKind $tipo): ?LegacyPaper
    {
        $numero = $request->validated($prefijo.'Number');

        if (! is_string($numero)) {
            return null;
        }

        return new LegacyPaper(
            kind: $tipo,
            field: $prefijo,
            number: $numero,
            issuedOn: CarbonImmutable::parse((string) $request->validated($prefijo.'Date')),
            amount: Decimal::parse((string) $request->validated($prefijo.'Amount')) ?? '0.00',
            photo: $request->file($prefijo.'Photo'),
        );
    }
}
