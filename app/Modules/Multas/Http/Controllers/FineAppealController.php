<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ResolveFineAppeal;
use App\Modules\Multas\Enums\AppealKind;
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
 * El pago del 20 % para recurrir, y la resolución de su diferencia (M07).
 *
 * En efectivo lo registra quien cobra; por depósito directo, también quien
 * concilia. La diferencia la resuelve solo el administrador
 * (`multas.resolver-recurso`, en la ruta).
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
            externalReference: (string) $datos['externalReference'],
            externalAmount: (string) $datos['externalAmount'],
            idempotencyKey: (string) $datos['idempotencyKey'],
            actorId: $usuario->id,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
            declaredPaidOn: isset($datos['declaredPaidOn']) ? CarbonImmutable::parse((string) $datos['declaredPaidOn']) : null,
            presentedOn: isset($datos['presentedOn']) ? CarbonImmutable::parse((string) $datos['presentedOn']) : null,
            presentationEvidence: $datos['presentationEvidence'] ?? null,
        );

        match ($pago->status) {
            FinePaymentStatus::UnderReview => Toast::warning(
                'Recurso en revisión.',
                ($pago->appeal_repeated
                    ? 'La multa ya tiene otro recurso'
                    : 'El recibo externo no coincide con el 20 % calculado')
                    .': no se cobró ni se emitió recibo. Lo resuelve el administrador.',
            ),
            FinePaymentStatus::Reported => Toast::success('Recurso registrado.', 'El depósito queda por verificar contra el extracto.'),
            default => Toast::success('Recurso cobrado.'),
        };

        return back();
    }

    public function resolve(ResolveFineAppealRequest $request, FinePayment $payment, ResolveFineAppeal $resolver): RedirectResponse
    {
        $datos = $request->validated();

        $pago = $resolver->handle(
            pago: $payment,
            aplicar: $datos['decision'] === 'apply',
            reason: (string) $datos['reason'],
            actorId: $request->user()?->id,
            talonarioNumber: $datos['talonarioNumber'] ?? null,
            signedById: isset($datos['signedById']) ? (int) $datos['signedById'] : null,
        );

        Toast::success($pago->status === FinePaymentStatus::Discarded ? 'Recurso descartado.' : 'Recurso aplicado por el 20 % exigido.');

        return back();
    }
}
