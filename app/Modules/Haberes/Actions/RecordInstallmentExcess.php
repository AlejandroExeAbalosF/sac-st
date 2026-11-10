<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Support\IncomeEvidence;
use App\Modules\Haberes\Support\InstallmentEditLock;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registra que por una cuota entra más de lo que se debe.
 *
 * El operador escribe el total —lo que trae el empleador o dice el
 * comprobante— y el sistema guarda la diferencia con lo que se debía como
 * excedente de la cuota, con su motivo. La cuota pasa a esperar ese total:
 * el cobro, el recibo y el pago al beneficiario lo toman de ahí, sin cambios.
 *
 * **No mueve dinero.** Solo cambia lo que la cuota espera: la caja ve lo que
 * entra recién en el cobro. Por eso va antes de cualquier dinero, recibo o
 * comprobante cruzado; después ya no se puede saber qué parte entró de más.
 *
 * Con un total igual a lo que se debe, quita el excedente: es la corrección de
 * uno cargado por error, y su motivo queda en la auditoría.
 */
final class RecordInstallmentExcess
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly InstallmentEditLock $traba,
        private readonly InstallmentFunding $financiacion,
    ) {}

    /**
     * @param  numeric-string  $total
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $total,
        Reason $motivo,
        ?CarbonInterface $version,
        ?int $actorId = null,
    ): BeneficiaryInstallment {
        return DB::transaction(function () use ($installment, $total, $motivo, $version, $actorId): BeneficiaryInstallment {
            // De afuera hacia adentro, como la asignación: expediente -> haber -> cuota.
            $expedienteId = Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');
            Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            $haber = Haber::query()->lockForUpdate()->findOrFail($installment->haber_id);
            $cuota = BeneficiaryInstallment::query()
                ->where('haber_id', $haber->id)
                ->lockForUpdate()
                ->findOrFail($installment->id);

            $this->assertRegistrable($haber, $cuota, $version);

            $aAbonar = $cuota->importeAAbonar();
            $comparacion = bccomp($total, $aAbonar, 2);

            if ($comparacion === -1) {
                throw ValidationException::withMessages([
                    'amount' => 'El total no puede ser menor que lo que se debe ($ '.Decimal::format($aAbonar).'). Un pago menor es un pago parcial.',
                ]);
            }

            if ($comparacion === 0 && ! $cuota->tieneExcedente()) {
                throw ValidationException::withMessages([
                    'amount' => 'El total es lo que se debe: no hay excedente que registrar.',
                ]);
            }

            $this->assertMatchesTickets($cuota, $total);

            $antes = ['expected_amount' => $cuota->expected_amount, 'excess_amount' => $cuota->excess_amount];
            $excedente = Decimal::sub($total, $aAbonar);
            $quita = $comparacion === 0;

            $cuota->forceFill([
                'expected_amount' => $total,
                'excess_amount' => $excedente,
                'excess_reason_code' => $quita ? null : $motivo->code,
                'excess_reason' => $quita ? null : $motivo->detail,
            ])->save();

            $this->auditar->handle(
                $quita ? 'cuota.excedente-quitado' : 'cuota.excedente-registrado',
                $cuota,
                $antes,
                ['expected_amount' => $cuota->expected_amount, 'excess_amount' => $cuota->excess_amount, 'reason' => $motivo->describe()],
                [
                    'haber_id' => $haber->id,
                    'expediente_id' => $haber->expediente_id,
                    'installment_number' => $cuota->installment_number,
                    'motivo_codigo' => $motivo->code->value,
                ],
                actorId: $actorId,
            );

            return $cuota->refresh();
        });
    }

    /** @throws ValidationException */
    private function assertRegistrable(Haber $haber, BeneficiaryInstallment $cuota, ?CarbonInterface $version): void
    {
        $problema = match (true) {
            $haber->workflow_status !== HaberWorkflowStatus::Active => 'El haber no está activo.',
            $cuota->workflow_status !== InstallmentWorkflowStatus::Active => 'Solo se registra un importe mayor en una cuota activa.',
            $version === null || ! $cuota->updated_at->equalTo($version) => 'Otra persona modificó esta cuota. Recargá la página para ver la versión actual.',
            $this->traba->lockingOrder($cuota) !== null => 'La cuota ya está en circulación con su Orden de Pago: no se le puede registrar un importe mayor.',
            bccomp($this->financiacion->allocated($cuota), '0', 2) === 1 => 'La cuota ya tiene dinero imputado: el importe mayor se registra antes de cobrarla.',
            $this->tieneRecibo($cuota) => 'La cuota ya tiene recibo de ingreso.',
            IncomeEvidence::of($cuota) !== null => 'La cuota está respaldada por el sistema anterior: sus importes tienen que coincidir con ese registro.',
            $cuota->depositTickets()->where('status', DepositTicketStatus::Matched)->exists() => 'La cuota tiene un comprobante ya cruzado con el banco: desvinculalo antes de registrar el importe mayor.',
            default => null,
        };

        if ($problema !== null) {
            throw ValidationException::withMessages(['amount' => $problema]);
        }
    }

    /**
     * Con comprobantes a la espera, el total es lo que dicen: el papel no se
     * reescribe desde acá, y el cruce exige igualdad exacta.
     *
     * @param  numeric-string  $total
     *
     * @throws ValidationException
     */
    private function assertMatchesTickets(BeneficiaryInstallment $cuota, string $total): void
    {
        $esperando = $cuota->depositTickets()->where('status', DepositTicketStatus::Waiting)->get();

        if ($esperando->isEmpty()) {
            return;
        }

        $suma = '0.00';

        foreach ($esperando as $comprobante) {
            $suma = Decimal::add($suma, (string) $comprobante->amount);
        }

        if (! Decimal::equals($suma, $total)) {
            throw ValidationException::withMessages([
                'amount' => 'El comprobante cargado es por $ '.Decimal::format($suma).': el total tiene que coincidir con él.',
            ]);
        }
    }

    private function tieneRecibo(BeneficiaryInstallment $cuota): bool
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $cuota->id)
            ->exists();
    }
}
