<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\FindCashDepositCandidates;
use App\Modules\Banking\Actions\ListCreditsForTransfer;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashDepositCandidate;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El efectivo que nadie retiró, camino al banco.
 *
 * Dos actos separados porque son dos hechos separados: el depósito lo hace
 * una persona y el banco lo confirma días después. Entre uno y otro el
 * dinero está en tránsito, que es un lugar real y no un estado de trámite.
 */
final class CashTransferController extends Controller
{
    /**
     * La pantalla para transcribir el ticket del cajero.
     *
     * Es una pantalla y no un diálogo por lo mismo que la carga del
     * comprobante del expediente: el operador vuelve del banco con un
     * papel y lo copia mirándolo, y la foto al lado del formulario no
     * entra en un modal sin apretarlo hasta volverlo incómodo.
     */
    public function create(BeneficiaryInstallment $installment): Response
    {
        $installment->loadMissing('haber.beneficiary', 'haber.expediente');

        $recibo = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        /*
         * Sin recibo no hay nada que trasladar, y el Action lo rechazaria
         * igual: se corta antes para no dibujar un formulario que no puede
         * enviarse.
         */
        abort_if($recibo === null, 404);

        $haber = $installment->haber;
        $expediente = $haber->expediente;

        return Inertia::render('haberes/traslados/create', [
            'cuota' => [
                'id' => $installment->id,
                'number' => $installment->installment_number,
                'amount' => app(InstallmentFunding::class)->allocated($installment),
                'concept' => $installment->description ?? $haber->concept,
                'receiptNumber' => $recibo->formatted_number,
            ],
            'haber' => [
                'id' => $haber->id,
                'expedienteId' => $expediente->id,
                'beneficiaryName' => $haber->beneficiary->name,
            ],
            'expediente' => [
                'id' => $expediente->id,
                'displayNumber' => $expediente->display_number,
            ],
            'accounts' => BankAccount::query()
                ->where('is_active', true)
                ->orderBy('label')
                ->get(['id', 'label', 'currency']),
        ]);
    }

    /**
     * Registra el depósito del efectivo de una cuota.
     *
     * La foto del ticket es obligatoria acá, a diferencia del comprobante
     * que trae el expediente: aquel documenta plata que entró y que el
     * extracto va a confirmar igual; esta es la única prueba de que el
     * efectivo salió de la caja.
     */
    public function store(
        Request $request,
        BeneficiaryInstallment $installment,
        DepositCashToBank $depositar,
    ): RedirectResponse {
        $validado = $request->validate([
            'bankAccountId' => ['required', 'integer', 'exists:bank_accounts,id'],
            'depositDate' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'depositTime' => ['nullable', 'date_format:H:i'],
            'operationNumber' => ['nullable', 'string', 'max:40'],
            'terminal' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:500'],
            'ticket' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf,webp', 'max:10240'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'ticket.required' => 'El ticket del cajero es obligatorio: es la prueba de que el efectivo salió de la caja.',
            'depositDate.before_or_equal' => 'La fecha del depósito no puede ser futura.',
        ]);

        $traslado = $depositar->handle(
            installment: $installment,
            ticket: [
                'bankAccountId' => (int) $validado['bankAccountId'],
                'depositDate' => CarbonImmutable::parse((string) $validado['depositDate']),
                'depositTime' => isset($validado['depositTime'])
                    ? $validado['depositTime'].':00'
                    : null,
                'operationNumber' => $validado['operationNumber'] ?? null,
                'terminal' => $validado['terminal'] ?? null,
                'notes' => $validado['notes'] ?? null,
            ],
            foto: $request->file('ticket'),
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        $haber = $installment->haber;

        return to_route('haberes.haber.show', [$haber->loadMissing('expediente')->expediente, $haber])->with(
            'status',
            'Depósito registrado por '.Decimal::format($traslado->amount)
            .'. Queda en tránsito hasta que el extracto lo confirme.',
        );
    }

    /**
     * Los créditos del extracto que podrían ser esta acreditación.
     *
     * Propone, no vincula. Confirmar es de una persona porque un crédito
     * mal atribuido cierra una ventana de tránsito que sigue abierta, y
     * deja un depósito perdido que nadie va a reclamar.
     */
    public function candidates(
        Request $request,
        CashToBankTransfer $transfer,
        FindCashDepositCandidates $buscar,
        ListCreditsForTransfer $listar,
    ): JsonResponse {
        /*
         * `?todos=1` cambia el filtro duro por el listado completo. No es
         * un modo de depuracion: «no hay candidatos» casi nunca significa
         * que el credito no exista, y sin esta salida la pantalla queda
         * sin nada que ofrecer justo cuando mas hace falta.
         */
        if ($request->boolean('todos')) {
            return response()->json([
                'candidates' => array_map(
                    fn (BankTransaction $m): array => $this->describirCredito($m, $transfer),
                    $listar->handle($transfer),
                ),
                'todos' => true,
                'periodImported' => $listar->periodIsImported($transfer),
            ]);
        }

        return response()->json([
            'candidates' => array_map(
                fn (CashDepositCandidate $c): array => [
                    'id' => $c->transaction->id,
                    'date' => $c->transaction->transaction_date?->format('Y-m-d'),
                    'amount' => $c->transaction->amount,
                    'description' => $c->transaction->description,
                    'operationId' => $c->transaction->operation_id,
                    'signals' => $c->signals,
                ],
                $buscar->handle($transfer),
            ),
            'todos' => false,
            'periodImported' => $listar->periodIsImported($transfer),
        ]);
    }

    /**
     * Un credito del listado ancho, con su distancia a la vista.
     *
     * En que difiere es lo unico que el operador necesita para decidir, y
     * decirlo es lo que distingue esta lista de un volcado de la tabla.
     *
     * @return array<string, mixed>
     */
    private function describirCredito(BankTransaction $movimiento, CashToBankTransfer $transfer): array
    {
        $diferencia = Decimal::sub(
            Decimal::abs($movimiento->amount),
            $transfer->amount,
        );

        $dias = $movimiento->transaction_date === null
            ? null
            : (int) $transfer->deposit_date->diffInDays($movimiento->transaction_date, false);

        $signals = [
            'importe' => Decimal::equals($diferencia, '0')
                ? 'exacto'
                : 'difiere en $ '.Decimal::format(Decimal::abs($diferencia)),
        ];

        if ($dias !== null) {
            $signals['fecha'] = match (true) {
                $dias === 0 => 'el mismo día del depósito',
                $dias === 1 => 'el día siguiente',
                $dias > 1 => "{$dias} días después",
                $dias === -1 => 'un día antes del depósito',
                default => abs($dias).' días antes del depósito',
            };
        }

        return [
            'id' => $movimiento->id,
            'date' => $movimiento->transaction_date?->format('Y-m-d'),
            'amount' => $movimiento->amount,
            'description' => $movimiento->description,
            'operationId' => $movimiento->operation_id,
            'signals' => $signals,
        ];
    }

    /**
     * Deshace un traslado que no ocurrió: el efectivo vuelve a la caja.
     *
     * Sólo lo que sigue en tránsito. Un traslado acreditado está
     * confirmado por el extracto, y decir que no ocurrió sería inventar.
     */
    public function cancel(
        Request $request,
        CashToBankTransfer $transfer,
        CancelCashToBankTransfer $cancelar,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se cancela el traslado.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $cancelar->handle(
            transfer: $transfer,
            reason: (string) $validado['reason'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('Traslado cancelado.', 'El efectivo vuelve a figurar en la caja.');

        return back();
    }

    /** Confirma la acreditación contra el crédito del extracto. */
    public function confirm(
        Request $request,
        CashToBankTransfer $transfer,
        ConfirmCashDepositCredit $confirmar,
    ): RedirectResponse {
        $validado = $request->validate([
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        /** @var BankTransaction $movimiento */
        $movimiento = BankTransaction::query()->findOrFail($validado['bankTransactionId']);

        $confirmar->handle(
            transfer: $transfer,
            transaction: $movimiento,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        Toast::success('El extracto confirmó el depósito.', 'El dinero ya está en el banco.');

        return back();
    }
}
