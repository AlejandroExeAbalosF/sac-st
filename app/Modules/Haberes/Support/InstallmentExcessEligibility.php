<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\LegacyDocument;

/**
 * Si a una cuota se le puede registrar un importe mayor, y si no, por qué.
 *
 * Una sola lista para los dos lados: `RecordInstallmentExcess` la aplica
 * bajo lock y rechaza con el motivo; la pantalla de «Cargar comprobante» la
 * consulta para no ofrecer una sección que va a rebotar.
 *
 * El importe mayor va antes de cualquier dinero: con plata imputada, recibo,
 * comprobante cruzado o respaldo del sistema anterior ya no se puede saber
 * qué parte entró de más.
 */
final class InstallmentExcessEligibility
{
    public function __construct(
        private readonly InstallmentEditLock $traba,
        private readonly InstallmentFunding $financiacion,
    ) {}

    /**
     * El motivo del rechazo, o `null` si se puede registrar.
     *
     * Con `$newTicket` se suman las condiciones de registrarlo junto con un
     * comprobante nuevo: sin excedente previo —cambiarlo o quitarlo es de la
     * tarjeta— y sin otro comprobante a la espera, porque el total tendría
     * que coincidir con la suma de todos los papeles.
     */
    public function rejection(BeneficiaryInstallment $cuota, bool $newTicket = false): ?string
    {
        return match (true) {
            $cuota->haber->workflow_status !== HaberWorkflowStatus::Active => 'El haber no está activo.',
            $cuota->workflow_status !== InstallmentWorkflowStatus::Active => 'Solo se registra un importe mayor en una cuota activa.',
            $newTicket && $cuota->tieneExcedente() => 'La cuota ya tiene un excedente registrado.',
            $newTicket && $cuota->depositTickets()->where('status', DepositTicketStatus::Waiting)->exists() => 'La cuota ya tiene un comprobante a la espera. Registrá el importe mayor desde la tarjeta.',
            $this->traba->lockingOrder($cuota) !== null => 'La cuota ya está en circulación con su Orden de Pago: no se le puede registrar un importe mayor.',
            bccomp($this->financiacion->allocated($cuota), '0', 2) === 1 => 'La cuota ya tiene dinero imputado: el importe mayor se registra antes de cobrarla.',
            ($evidence = IncomeEvidence::of($cuota))?->receipt !== null => 'La cuota ya tiene recibo de ingreso.',
            $evidence !== null || ($newTicket && LegacyDocument::query()->current()->where('beneficiary_installment_id', $cuota->id)->exists()) => 'La cuota está respaldada por el sistema anterior: sus importes tienen que coincidir con ese registro.',
            $cuota->depositTickets()->where('status', DepositTicketStatus::Matched)->exists() => 'La cuota tiene un comprobante ya cruzado con el banco: desvinculalo antes de registrar el importe mayor.',
            default => null,
        };
    }
}
