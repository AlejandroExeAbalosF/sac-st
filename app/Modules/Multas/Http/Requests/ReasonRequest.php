<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Multas\Support\FineReason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Un acto que solo pide el motivo: revertir un ajuste, descartar un pago,
 * desvincular un documento.
 *
 * El motivo se elige de la lista del acto y el detalle es opcional, salvo con
 * «Otro». Es lo único que va a quedar para entender, dentro de un tiempo, por
 * qué algo figura revertido, descartado o desvinculado.
 */
final class ReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return FineReason::rules($this->act());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return FineReason::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reasonCode' => 'motivo', 'reason' => 'detalle'];
    }

    public function reason(): FineReason
    {
        return FineReason::fromValidated($this->validated())
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }

    /** Qué acto es, por la ruta: cada uno tiene su lista. */
    public function act(): FineReasonAct
    {
        return match (true) {
            $this->routeIs('multas.ajustes.reverse') => FineReasonAct::Reversal,
            $this->routeIs('multas.pagos.discard') => FineReasonAct::PaymentDiscard,
            $this->routeIs('multas.documentos.anulacion.store') => FineReasonAct::LinkRevocation,
            default => throw new LogicException('Esta ruta no declara qué motivo pide.'),
        };
    }
}
