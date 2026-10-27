<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Anulación de un registro del sistema anterior: el pago fuera del circuito
 * o el recibo de papel de una cuota.
 *
 * Cada uno con su lista de motivos; el detalle es opcional, salvo con «Otro».
 * El registro no se borra: queda anulado con su motivo.
 */
final class VoidLegacySettlementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules($this->act());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reasonCode' => 'motivo', 'reason' => 'detalle'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Reason::messages();
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), $this->act())
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }

    public function act(): HaberesReasonAct
    {
        return $this->routeIs('*legacy-funds.void-paper')
            ? HaberesReasonAct::LegacyDocumentVoid
            : HaberesReasonAct::LegacySettlementVoid;
    }
}
