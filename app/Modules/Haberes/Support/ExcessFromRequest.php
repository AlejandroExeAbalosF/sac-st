<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * El importe mayor que acompaña a otro acto: el cobro por mostrador o la
 * carga del comprobante de depósito.
 *
 * Los dos pedidos lo reciben con los mismos campos —`excessTotal`, `version`
 * y el motivo— y lo entregan con la forma que esperan sus Actions, que lo
 * registran con `RecordInstallmentExcess` en su propia transacción. Está en
 * un solo lugar para que el permiso y el motivo obligatorio no diverjan
 * entre un circuito y otro.
 */
final class ExcessFromRequest
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'excessTotal' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999999.99'],
            'version' => ['nullable', 'date', 'required_with:excessTotal'],
            ...Reason::rules(HaberesReasonAct::InstallmentExcess, required: false, max: 300),
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'excessTotal.gt' => 'El total tiene que ser mayor que cero.',
            'version.required_with' => 'Recargá la página antes de registrar el importe mayor.',
        ];
    }

    /**
     * El importe mayor que acompaña al cobro o al comprobante, si lo hay.
     *
     * Cambia lo que la cuota espera, como corregirla, y pide el mismo
     * permiso que el botón de la tarjeta.
     *
     * @param  array<string, mixed>  $validado
     * @return array{total: numeric-string, reason: Reason, version: CarbonInterface}|null
     */
    public static function fromValidated(Request $request, array $validado): ?array
    {
        if (($validado['excessTotal'] ?? null) === null) {
            return null;
        }

        abort_unless($request->user()?->can('expedientes.editar') ?? false, 403);

        $motivo = Reason::fromValidated($validado, HaberesReasonAct::InstallmentExcess);

        if ($motivo === null) {
            throw ValidationException::withMessages(['reasonCode' => 'Elegí por qué entra más.']);
        }

        return [
            'total' => Decimal::scale((string) $validado['excessTotal']),
            'reason' => $motivo,
            'version' => CarbonImmutable::parse((string) $validado['version']),
        ];
    }
}
