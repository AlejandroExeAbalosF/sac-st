<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Actions\RecordFeeChargeProof;
use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Http\Requests\RecordFeeDepositTicketRequest;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/** El comprobante del depósito de un cobro: como en las otras cajas, se transcribe mirando la foto. */
final class FeeChargeProofController extends Controller
{
    public function create(FeeCharge $feeCharge): Response
    {
        abort_unless($feeCharge->medium === FeeChargeMedium::Bank && $feeCharge->status !== FeeChargeStatus::Voided, 404);

        $saldo = FeeChargeBalance::query()->findOrFail($feeCharge->id);

        return Inertia::render('aranceles/comprobante-de-cobro', [
            'cobro' => [
                'id' => $feeCharge->id,
                'payerName' => $feeCharge->payer_name,
                // Contra el total se compara lo que dice el papel: si difiere, la pantalla avisa.
                'total' => Decimal::scale((string) $saldo->total),
            ],
            'today' => BusinessDate::today()->toDateString(),
        ]);
    }

    public function store(RecordFeeDepositTicketRequest $request, FeeCharge $feeCharge, RecordFeeChargeProof $cargar): RedirectResponse
    {
        /** @var UploadedFile $foto */
        $foto = $request->file('ticket');

        $cargar->handle(
            $feeCharge,
            (string) $request->validated('amount'),
            $request->ticket(),
            $foto,
            (string) $request->validated('idempotencyKey'),
            $request->user()?->id,
        );

        Toast::success('Comprobante cargado.', 'El depósito se sigue verificando contra el extracto.');

        return to_route('aranceles.cobros.index', ['cobro' => $feeCharge->id]);
    }
}
