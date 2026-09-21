<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\AddInstallment;
use App\Modules\Haberes\Actions\ReceiveAndAllocateTicket;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\UnlockInstallmentEdit;
use App\Modules\Haberes\Actions\UpdateInstallment;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Http\Requests\SaveInstallmentRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Support\Money\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InstallmentController extends Controller
{
    public function store(SaveInstallmentRequest $request, Expediente $expediente, Haber $haber, AddInstallment $add): RedirectResponse
    {
        $installment = $add->handle($haber, SaveInstallmentData::fromRequest($request));

        return $this->volverA($request, $haber)
            ->with('status', "Cuota {$installment->installment_number} agregada.");
    }

    /**
     * Anula el cobro por mostrador de esta cuota.
     *
     * Se usa cuando el importe se tipeo mal y el sistema asento en la caja
     * un dinero que nadie trajo: deshace la imputacion, deshace la
     * recepcion y anula el recibo, todo con su motivo.
     *
     * Despues la cuota vuelve a estar sin cobrar y el circuito normal la
     * cobra bien. El recibo nuevo toma el siguiente numero libre de la
     * serie y apunta al anulado.
     */
    public function voidCollection(
        Request $request,
        BeneficiaryInstallment $installment,
        VoidCashCollection $anular,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se anula el cobro.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $anular->handle(
            installment: $installment,
            reason: (string) $validado['reason'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        return back()->with(
            'status',
            'Cobro anulado. La cuota vuelve a estar sin cobrar y el recibo quedó anulado con su número.',
        );
    }

    /**
     * Devuelve al pozo de no identificados plata imputada a esta cuota.
     *
     * Dos caminos llegan acá: se corrigió el importe de la cuota y quedó
     * plata de más, o la recepción se imputó a la cuota equivocada. En el
     * segundo caso esto es la primera mitad —después se imputa donde
     * corresponde con la asignación de siempre—.
     *
     * El motivo es obligatorio. Liberar plata imputada no es un ajuste de
     * pantalla: mueve dinero entre cuentas del libro, y alguien tiene que
     * poder leer el año que viene por qué ese dinero dejó de estar
     * asignado a este beneficiario.
     */
    public function unallocate(
        Request $request,
        BeneficiaryInstallment $installment,
        UnallocateFunds $desasignar,
    ): RedirectResponse {
        $validado = $request->validate([
            'allocationId' => ['required', 'integer', 'exists:funding_allocations,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'reason.required' => 'Hay que decir por qué se libera ese dinero.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        /** @var FundingAllocation $asignacion */
        $asignacion = FundingAllocation::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->findOrFail($validado['allocationId']);

        $desasignar->handle(
            allocation: $asignacion,
            amount: $validado['amount'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
            notes: (string) $validado['reason'],
        );

        return back()->with(
            'status',
            'Se liberaron $ '.Decimal::format($validado['amount'])
            .': ese dinero vuelve a la cola de fondos sin identificar.',
        );
    }

    /**
     * Registra el ingreso de la cuota y se lo asigna, en un solo acto.
     *
     * **Atajo del camino normal, no un camino nuevo.** Los dos asientos se
     * escriben igual y con los mismos Actions; lo que se ahorra es recorrer
     * dos pantallas tipeando importes que el sistema ya conoce, porque el
     * ticket está cruzado con su crédito y la cuota dice cuánto espera.
     *
     * Cuando el caso no es ése —un crédito que hay que repartir entre
     * expedientes, un excedente que pide una decisión— el Action se planta
     * y queda el camino largo, que es el que sabe preguntar.
     */
    public function receiveAndAllocate(
        Request $request,
        BeneficiaryInstallment $installment,
        ReceiveAndAllocateTicket $registrarYAsignar,
    ): RedirectResponse {
        $validado = $request->validate([
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ]);

        $recepcion = $registrarYAsignar->handle(
            installment: $installment,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
        );

        return back()->with(
            'status',
            "Ingreso registrado y asignado a la cuota {$installment->installment_number}. "
            ."Recepción {$recepcion->id}.",
        );
    }

    /**
     * Abre la ventana para corregir una cuota que ya está en circulación.
     *
     * Con la Orden y el Pase emitidos, el expediente salió del área y hay
     * alguien afuera leyendo lo que este dato dice. La corrección sigue
     * siendo posible —el área fue explícita— pero exige registrar antes el
     * caso y el porqué. Al guardar, la ventana se cierra sola.
     */
    public function unlockEdit(
        Request $request,
        BeneficiaryInstallment $installment,
        UnlockInstallmentEdit $habilitar,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ], [
            'reason.required' => 'Hay que registrar el caso antes de corregir una cuota en circulación.',
            'reason.min' => 'El motivo tiene que explicar el caso: un par de palabras no alcanzan.',
        ]);

        $habilitar->handle($installment, (string) $validado['reason'], $request->user()?->id);

        return back()->with(
            'status',
            'Edición habilitada. Al guardar los cambios, la cuota vuelve a quedar bloqueada.',
        );
    }

    public function update(
        SaveInstallmentRequest $request,
        Expediente $expediente,
        Haber $haber,
        BeneficiaryInstallment $installment,
        UpdateInstallment $update,
    ): RedirectResponse {
        abort_unless($installment->haber_id === $haber->id, 404);

        $update->handle($haber, $installment, SaveInstallmentData::fromRequest($request));

        return $this->volverA($request, $haber)
            ->with('status', "Cuota {$installment->installment_number} actualizada.");
    }

    private function volverA(SaveInstallmentRequest $request, Haber $haber): RedirectResponse
    {
        $expediente = $haber->loadMissing('expediente')->expediente;

        return $request->validated('returnTo') === 'haber'
            ? to_route('haberes.haber.show', [$expediente, $haber])
            : to_route('expedientes.show', $expediente);
    }
}
