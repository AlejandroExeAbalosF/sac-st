<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Actions\RecordFineBankCreditPayment;
use App\Modules\Multas\Actions\RecordFineHistoricalMovement;
use App\Modules\Multas\Actions\ReviewFineHistoricalMovement;
use App\Modules\Multas\Actions\ReviseFineIdentifiers;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Http\Requests\ReviewHistoricalMovementRequest;
use App\Modules\Multas\Http\Requests\ReviseFineRecordRequest;
use App\Modules\Multas\Http\Requests\StoreFineBankCreditPaymentRequest;
use App\Modules\Multas\Http\Requests\StoreHistoricalMovementRequest;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Support\PartialDate;
use App\Support\Ui\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Los pagos y devoluciones anteriores al sistema.
 */
final class FineHistoricalMovementController extends Controller
{
    public function store(StoreHistoricalMovementRequest $request, Fine $fine, RecordFineHistoricalMovement $registrar): RedirectResponse
    {
        $registrar->handle(
            $fine,
            HistoricalMovementKind::from((string) $request->validated('kind')),
            (string) $request->validated('amount'),
            PartialDate::parse($request->validated('occurredOn')) ?? PartialDate::unknown(),
            (string) $request->validated('evidence'),
            $request->user()?->id,
        );

        Toast::success('Antecedente registrado.', 'Cuenta en el saldo cuando alguien con permiso de ajustar lo valide.');

        return to_route('multas.show', $fine);
    }

    /**
     * Un crédito viejo de la cuenta de Multas, identificado como pago de
     * esta multa. Importe y fecha salen del crédito.
     */
    public function storeFromBankCredit(
        StoreFineBankCreditPaymentRequest $request,
        Fine $fine,
        RecordFineBankCreditPayment $registrar,
    ): RedirectResponse {
        $registrar->handle(
            $fine,
            (int) $request->validated('bankTransactionId'),
            (string) $request->validated('evidence'),
            $request->user()?->id,
        );

        Toast::success('Pago anterior registrado con su crédito del banco.', 'Cuenta en el saldo cuando alguien con permiso de ajustar lo valide.');

        return to_route('multas.show', $fine);
    }

    public function review(
        ReviewHistoricalMovementRequest $request,
        FineHistoricalMovement $movement,
        ReviewFineHistoricalMovement $revisar,
    ): RedirectResponse {
        $resultado = HistoricalMovementStatus::from((string) $request->validated('status'));

        $revisar->handle($movement, $resultado, $request->validated('note'), $request->user()?->id);

        Toast::success($resultado === HistoricalMovementStatus::Validated ? 'Antecedente validado.' : 'Antecedente descartado.');

        return to_route('multas.show', $movement->fine_id);
    }

    /**
     * Completar o corregir la fecha de un antecedente, en cualquier estado:
     * no cambia lo que aporta al saldo.
     */
    public function reviseDate(
        ReviseFineRecordRequest $request,
        FineHistoricalMovement $movement,
        ReviseFineIdentifiers $revisar,
    ): RedirectResponse {
        // La base lo impide igual (`fine_historical_bank_credit`).
        if ($movement->bank_transaction_id !== null) {
            throw ValidationException::withMessages([
                'date' => 'La fecha de un pago respaldado por un crédito del banco es la del banco: no se revisa.',
            ]);
        }

        $fecha = PartialDate::parse($request->validated('date')) ?? PartialDate::unknown();
        $fechaVista = PartialDate::parse($request->validated('expected.date')) ?? PartialDate::unknown();

        $revision = $revisar->handle(
            $movement,
            ['occurred_on' => $fecha->date, 'occurred_precision' => $fecha->precision->value],
            ['occurred_on' => $fechaVista->date, 'occurred_precision' => $fechaVista->precision->value],
            $request->validated('reason'),
            $request->canCorrect(),
            $request->user()?->id,
        );

        Toast::success($revision === null ? 'No había nada que cambiar.' : 'Fecha del antecedente actualizada.');

        return to_route('multas.show', $movement->fine_id);
    }
}
