<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ResolveFineAppeal;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Http\Requests\RegisterFineAppealRequest;
use App\Modules\Multas\Http\Requests\ResolveFineAppealRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

/**
 * El pago del 20 % para recurrir (M07) y su veredicto.
 *
 * En efectivo lo registra quien cobra; por depósito directo, también quien
 * concilia. Se cobra el importe del recibo externo. El veredicto lo da
 * quien cobra: la ruta exige `multas.cobrar`.
 */
final class FineAppealController extends Controller
{
    public function store(RegisterFineAppealRequest $request, Fine $fine, RegisterFineAppeal $registrar): RedirectResponse
    {
        $datos = $request->validated();
        $medio = FinePaymentMedium::from((string) $datos['medium']);
        $usuario = $request->user();

        abort_unless(
            ($usuario?->can('multas.cobrar') ?? false)
                || ($medio === FinePaymentMedium::DirectDeposit && ($usuario?->can('multas.verificar-pagos') ?? false)),
            403,
        );

        $pago = $registrar->handle(
            multa: $fine,
            tipo: AppealKind::from((string) $datos['kind']),
            medio: $medio,
            externalReference: $datos['externalReference'] ?? null,
            amount: (string) $datos['amount'],
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $usuario->id,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            declaredPaidOn: isset($datos['declaredPaidOn']) ? CarbonImmutable::parse((string) $datos['declaredPaidOn']) : null,
            presentedOn: isset($datos['presentedOn']) ? CarbonImmutable::parse((string) $datos['presentedOn']) : null,
            presentationEvidence: $datos['presentationEvidence'] ?? null,
            replaces: isset($datos['replacesPaymentId']) ? FinePayment::query()->findOrFail((int) $datos['replacesPaymentId']) : null,
            correctionReason: $datos['correctionReason'] ?? null,
        );

        Toast::success(
            $pago->status === FinePaymentStatus::Reported ? 'Recurso registrado.' : 'Recurso cobrado.',
            $pago->status === FinePaymentStatus::Reported ? 'El depósito queda por verificar contra el extracto.' : null,
        );

        return back();
    }

    public function resolve(ResolveFineAppealRequest $request, Fine $fine, ResolveFineAppeal $resolver): RedirectResponse
    {
        $recurso = $resolver->handle(
            $fine,
            FineAppealStatus::from((string) $request->validated('verdict')),
            (int) $request->validated('appealId'),
            (int) $request->validated('paymentId'),
            $request->user()?->id,
        );

        Toast::success(
            $recurso->status === FineAppealStatus::Upheld ? 'El recurso prosperó: la multa quedó cancelada.' : 'El recurso no prosperó.',
            $recurso->status === FineAppealStatus::Upheld ? null : 'La multa vuelve a admitir pagos por el resto.',
        );

        return back();
    }
}
