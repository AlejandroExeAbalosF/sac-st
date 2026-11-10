<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\HaberWorkflowStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentTerms;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Support\InstallmentEditLock;
use App\Modules\Haberes\Support\InstallmentPlanRules;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Corrige una cuota activa preservando los cambios concurrentes. */
final class UpdateInstallment
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly InstallmentEditLock $traba,
    ) {}

    public function handle(Haber $haber, BeneficiaryInstallment $installment, SaveInstallmentData $data): BeneficiaryInstallment
    {
        return DB::transaction(function () use ($haber, $installment, $data): BeneficiaryInstallment {
            $lockedHaber = Haber::query()->lockForUpdate()->findOrFail($haber->id);
            $lockedInstallment = BeneficiaryInstallment::query()
                ->where('haber_id', $lockedHaber->id)
                ->lockForUpdate()
                ->findOrFail($installment->id);

            if ($lockedHaber->workflow_status !== HaberWorkflowStatus::Active) {
                throw ValidationException::withMessages([
                    'form' => 'No se puede editar una cuota de un haber que no está activo.',
                ]);
            }

            if ($lockedInstallment->workflow_status !== InstallmentWorkflowStatus::Active) {
                throw ValidationException::withMessages([
                    'form' => 'Solo se pueden editar cuotas pendientes y activas.',
                ]);
            }

            /*
             * El expediente salió del área con su Orden y su Pase: hay
             * alguien afuera leyendo lo que este dato dice. Se corrige
             * igual, pero registrando antes el caso y el porqué.
             */
            $trabada = $this->traba->lockingOrder($lockedInstallment);

            if ($trabada !== null && $lockedInstallment->edit_unlocked_at === null) {
                throw ValidationException::withMessages([
                    'form' => $this->traba->reason($trabada),
                ]);
            }

            if ($data->version === null || ! $lockedInstallment->updated_at->equalTo($data->version)) {
                throw ValidationException::withMessages([
                    'form' => 'Otra persona modificó esta cuota. Recargá la página para ver la versión actual.',
                ]);
            }

            /*
             * El importe que se edita es el que se debe. Si la cuota tiene un
             * excedente registrado, el total declarado se conserva —es lo que
             * entró, o lo que dice el comprobante— y el excedente se
             * recalcula contra la base nueva. Una base mayor que ese total ya
             * no es un excedente: se registra el importe mayor de nuevo.
             */
            $aAbonar = $data->amount;
            $total = $aAbonar;
            $excedente = '0.00';

            if ($lockedInstallment->tieneExcedente()) {
                $total = $lockedInstallment->importeEsperado();

                if (bccomp($aAbonar, $total, 2) === 1) {
                    throw ValidationException::withMessages([
                        'amount' => 'Lo que se debe no puede superar el total registrado de la cuota ($ '.Decimal::format($total).'). Si entró más, registrá el importe mayor de nuevo.',
                    ]);
                }

                $excedente = Decimal::sub($total, $aAbonar);
            }

            $sumWithoutCurrent = (string) $lockedHaber->installments()
                ->where('workflow_status', '!=', InstallmentWorkflowStatus::Cancelled->value)
                ->whereKeyNot($lockedInstallment->id)
                ->sum(DB::raw('expected_amount - excess_amount'));
            $newSum = bcadd($sumWithoutCurrent, $aAbonar, 2);
            $assigned = $lockedHaber->importeAsignado();
            $loaded = $lockedHaber->installments()->count();
            $expected = $lockedHaber->expected_installment_count ?? $loaded;

            /*
             * Con el plan completo, quedar por debajo de lo reconocido no es
             * un error: abre una cuota pendiente (más abajo).
             */
            $problema = InstallmentPlanRules::problema($newSum, $assigned, completaElPlan: false);

            if ($problema !== null && ($loaded < $expected || bccomp($newSum, $assigned, 2) === 1)) {
                throw ValidationException::withMessages(['amount' => $problema]);
            }

            if ($loaded >= $expected && $loaded >= 60 && bccomp($newSum, $assigned, 2) === -1) {
                throw ValidationException::withMessages([
                    'amount' => 'No se puede reducir el importe porque el haber ya alcanzó el máximo de 60 cuotas.',
                ]);
            }

            /*
             * El antes se toma dentro de la transacción y con la fila ya
             * bloqueada: leerlo afuera dejaría registrado un estado que
             * pudo cambiar entre la lectura y la escritura.
             */
            $antes = $this->valoresAuditables($lockedInstallment);

            /*
             * El motivo de la ventana se lee antes de cerrarla: es lo que
             * explica esta corrección en particular, y tiene que quedar en
             * el mismo evento que el antes y el después.
             */
            // La ventana se cierra al guardar: su motivo, con el código, queda en la auditoría.
            $motivoDeLaVentana = Reason::text($lockedInstallment->edit_unlock_reason_code, $lockedInstallment->edit_unlock_reason);
            $codigoDeLaVentana = $lockedInstallment->edit_unlock_reason_code?->value;

            $sinExcedente = bccomp($excedente, '0', 2) === 0;

            $lockedInstallment->fill([
                'expected_amount' => $total,
                'excess_amount' => $excedente,
                'excess_reason_code' => $sinExcedente ? null : $lockedInstallment->excess_reason_code,
                'excess_reason' => $sinExcedente ? null : $lockedInstallment->excess_reason,
                'management_label_id' => $data->managementLabelId,
                'due_date' => $data->dueDate,
                'description' => $data->concept,
                'expected_medium' => $data->expectedMedium,
                'notes' => $data->notes,
            ])->forceFill([
                /*
                 * Y se vuelve a bloquear. La ventana la abre un acto
                 * justificado y la cierra el guardado: dejarla abierta
                 * esperando que alguien se acuerde de cerrarla la
                 * convertiría en un permiso permanente, y no lo es.
                 */
                'edit_unlocked_at' => null,
                'edit_unlock_reason_code' => null,
                'edit_unlock_reason' => null,
                'edit_unlocked_by' => null,
            ])->save();

            [$viejos, $nuevos] = RecordAuditEvent::diff(
                $antes,
                $this->valoresAuditables($lockedInstallment),
            );

            if ($viejos !== []) {
                $this->auditar->handle(
                    'cuota.corregida',
                    $lockedInstallment,
                    $viejos,
                    $nuevos,
                    array_filter([
                        'haber_id' => $lockedHaber->id,
                        'expediente_id' => $lockedHaber->expediente_id,
                        'installment_number' => $lockedInstallment->installment_number,
                        'payment_order_number' => $trabada?->formatted_number,
                        'unlock_reason' => $motivoDeLaVentana,
                        'motivo_codigo' => $codigoDeLaVentana,
                    ], fn (mixed $valor): bool => $valor !== null),
                );
            }

            // Si el plan estaba completo y al reducir esta cuota queda un
            // saldo, ese saldo pasa a ser una cuota pendiente explícita. Así
            // no se falsea que todas las cuotas ya están cargadas.
            if ($loaded >= $expected && bccomp($newSum, $assigned, 2) === -1) {
                $lockedHaber->forceFill([
                    'expected_installment_count' => $loaded + 1,
                    'payment_terms' => PaymentTerms::Installments,
                ])->save();
            }

            return $lockedInstallment->refresh();
        });
    }

    /*
     * **La cuota se corrige entera mientras el expediente esté en el
     * área.** No hay nada que congelar al emitir el recibo, y conviene
     * decir por qué, porque la intuición dice lo contrario.
     *
     * El recibo **copia** al emitirse lo que va impreso —importe,
     * concepto, medio, número de cuota— en sus propias columnas
     * `*_snapshot`. El papel que el empleador se llevó y el registro del
     * comprobante quedan consistentes entre sí pase lo que pase con la
     * cuota después: corregirla no puede desmentir al papel, porque el
     * papel no la lee.
     *
     * Y corregir hacia arriba es un caso legítimo, no una anomalía: si el
     * expediente decía $700.000 y se cargó $602.250, el recibo por lo
     * cobrado sigue siendo cierto y la cuota vuelve a estar incompleta,
     * que es lo que pasó. Hacia abajo se permite quedar por
     * debajo de lo asignado: `InstallmentFunding::overAllocated()` expone
     * el excedente. Liberarlo requiere `UnallocateFunds`, con sus guardas;
     * corregir la cuota no revierte imputaciones ni recibos por sí solo.
     * Ver docs/haberes-reglas.md#correccion-y-sobreasignacion.
     *
     * El bloqueo real llega con el **pase de pago**, y ya está puesto:
     * arriba, contra `InstallmentEditLock`. Ahí el expediente sale del
     * área y el dato entra en circulación, así que corregir exige
     * registrar antes el caso y el motivo —`UnlockInstallmentEdit`—, y la
     * ventana se cierra sola al guardar.
     */

    /**
     * Los campos cuyo cambio hay que poder reconstruir. El estado y el
     * bloqueo no están porque se mueven por otras acciones, que dejan su
     * propio evento.
     *
     * @return array<string, mixed>
     */
    private function valoresAuditables(BeneficiaryInstallment $cuota): array
    {
        return [
            'expected_amount' => $cuota->expected_amount,
            'excess_amount' => $cuota->excess_amount,
            'management_label_id' => $cuota->management_label_id,
            'due_date' => $cuota->due_date?->toDateString(),
            'description' => $cuota->description,
            'expected_medium' => $cuota->expected_medium->value,
            'notes' => $cuota->notes,
        ];
    }
}
