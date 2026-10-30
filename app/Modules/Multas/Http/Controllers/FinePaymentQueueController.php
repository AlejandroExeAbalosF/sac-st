<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Multas\Data\FinePendingPaymentData;
use App\Modules\Multas\Enums\FineCollectionPlace;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Multas\Support\FineDeposits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lo que espera a alguien en Multas, en una sola pantalla y por pestañas.
 *
 * - **Pagos informados:** lo que el deudor informó y espera su crédito del
 *   extracto. Es de quien concilia; cada fila lleva a la ficha, donde se
 *   verifica, se corrige o se descarta.
 * - **Por depositar** y **depositado, sin acreditar:** los cobros de la caja
 *   camino al banco, cada uno entero y en su propio depósito (M06).
 * - **Rechazos:** los cheques rechazados con algo pendiente (M03).
 *
 * Inicio cuenta cada pestaña y lleva a ella.
 */
final class FinePaymentQueueController extends Controller
{
    /** Las pestañas, en el orden en que se muestran. */
    public const TABS = ['informados', 'depositar', 'transito', 'rechazos'];

    public function __construct(
        private readonly FineDeposits $depositos,
        private readonly FineBankCredits $creditos,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        $concilia = $usuario?->can('multas.verificar-pagos') ?? false;
        $pedida = $request->string('pestana')->toString();
        $pestana = in_array($pedida, self::TABS, true) && ($pedida !== 'informados' || $concilia)
            ? $pedida
            : ($concilia ? 'informados' : 'depositar');

        return Inertia::render('multas/pendientes', [
            'tab' => $pestana,
            'informados' => $concilia ? $this->informados() : [],
            'paraDepositar' => $this->filas($this->depositos->counterPayments(FineCollectionPlace::InCustody)),
            'enTransito' => $this->filas($this->depositos->counterPayments(FineCollectionPlace::InTransit)),
            'rechazados' => $this->filas($this->depositos->paymentsWithPendingRejections()),
            'can' => [
                'verify' => $concilia,
                // Depositar exige la caja abierta con alguna cuenta habilitada.
                'deposit' => ($usuario?->can('multas.depositar') ?? false) && $this->creditos->book() !== null,
                'confirmDeposit' => $concilia,
                'viewAttachments' => $usuario?->can('adjuntos.ver') ?? false,
                'manageRejections' => $usuario?->can('multas.gestionar-rechazos') ?? false,
                'replaceCheque' => $usuario?->can('multas.cobrar') ?? false,
            ],
        ]);
    }

    /**
     * Lo informado que espera su crédito: lo más viejo primero, que es lo
     * que lleva más tiempo esperando.
     *
     * @return list<FinePendingPaymentData>
     */
    private function informados(): array
    {
        return array_values(FinePayment::query()
            ->where('status', FinePaymentStatus::Reported)
            ->with(['fine.fineCase', 'creator:id,name'])
            ->orderBy('reported_at')
            ->orderBy('id')
            ->get()
            ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago))
            ->all());
    }

    /**
     * @param  Builder<FinePayment>  $consulta
     * @return list<FinePendingPaymentData>
     */
    private function filas(Builder $consulta): array
    {
        $pagos = $consulta
            ->with(['fine.fineCase', 'application.fundReceipt', 'paymentReceipt.receipt', 'creator:id,name'])
            ->orderBy('id')
            ->get();

        $depositos = $this->depositos->forPayments($pagos);

        return array_values($pagos
            ->map(fn (FinePayment $pago): FinePendingPaymentData => FinePendingPaymentData::fromModel($pago, $depositos[$pago->id] ?? null))
            ->all());
    }
}
