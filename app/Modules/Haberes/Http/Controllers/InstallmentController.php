<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Haberes\Actions\AddInstallment;
use App\Modules\Haberes\Actions\ReceiveAndAllocateTicket;
use App\Modules\Haberes\Actions\RecordInstallmentExcess;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\UnlockInstallmentEdit;
use App\Modules\Haberes\Actions\UpdateInstallment;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Data\SaveInstallmentData;
use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Haberes\Http\Requests\SaveInstallmentRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

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
        // 250 y no 300: el recibo guarda «motivo: detalle» en una columna de 300.
        $validado = $request->validate(
            Reason::rules(HaberesReasonAct::CollectionVoid, max: 250)
            + ['idempotencyKey' => ['required', 'string', 'max:120']],
            Reason::messages(),
        );

        $anular->handle(
            installment: $installment,
            motivo: Reason::fromValidated($validado, HaberesReasonAct::CollectionVoid) ?? throw new LogicException('El motivo ya se validó.'),
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
        $validado = $request->validate(
            Reason::rules(HaberesReasonAct::FundsRelease, max: 250) + [
                'allocationId' => ['required', 'integer', 'exists:funding_allocations,id'],
                'amount' => ['required', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/'],
                'idempotencyKey' => ['required', 'string', 'max:120'],
            ],
            Reason::messages(),
        );
        $motivo = Reason::fromValidated($validado, HaberesReasonAct::FundsRelease) ?? throw new LogicException('El motivo ya se validó.');

        /** @var FundingAllocation $asignacion */
        $asignacion = FundingAllocation::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->findOrFail($validado['allocationId']);

        $desasignar->handle(
            allocation: $asignacion,
            amount: $validado['amount'],
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
            motivo: $motivo,
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
        $validado = $request->validate(
            Reason::rules(HaberesReasonAct::InstallmentUnlock, max: 300),
            ['reasonCode.required' => 'Hay que registrar el caso antes de corregir una cuota en circulación.'] + Reason::messages(),
        );

        $habilitar->handle(
            $installment,
            Reason::fromValidated($validado, HaberesReasonAct::InstallmentUnlock) ?? throw new LogicException('El motivo ya se validó.'),
            $request->user()?->id,
        );

        return back()->with(
            'status',
            'Edición habilitada. Al guardar los cambios, la cuota vuelve a quedar bloqueada.',
        );
    }

    /**
     * Registra el total que entra por la cuota cuando supera lo que se debe.
     *
     * El operador escribe el total; la diferencia queda como excedente de la
     * cuota, con su motivo. Con el total igual a lo que se debe, lo quita.
     */
    public function recordExcess(
        Request $request,
        BeneficiaryInstallment $installment,
        RecordInstallmentExcess $registrar,
    ): RedirectResponse {
        $quita = $request->boolean('remove');
        $acto = $quita ? HaberesReasonAct::InstallmentExcessRemoval : HaberesReasonAct::InstallmentExcess;

        $validado = $request->validate(
            [
                'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999.99'],
                'version' => ['required', 'date'],
                ...Reason::rules($acto, max: 300),
            ],
            [
                'amount.required' => 'Escribí el total.',
                'amount.gt' => 'El total tiene que ser mayor que cero.',
                'reasonCode.required' => $quita ? 'Elegí por qué se quita.' : 'Elegí por qué entra más.',
            ] + Reason::messages(),
            ['amount' => 'total'],
        );

        $cuota = $registrar->handle(
            $installment,
            Decimal::scale((string) $validado['amount']),
            Reason::fromValidated($validado, $acto) ?? throw new LogicException('El motivo ya se validó.'),
            CarbonImmutable::parse((string) $validado['version']),
            $request->user()?->id,
        );

        return back()->with('status', $cuota->tieneExcedente()
            ? "Cuota {$cuota->installment_number}: se registró un excedente de $ ".Decimal::format($cuota->excedente()).'.'
            : "Cuota {$cuota->installment_number}: se quitó el excedente.");
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
