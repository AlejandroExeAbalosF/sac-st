<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Enums;

use App\Modules\Shared\Support\ReasonCode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El motivo de un acto de Haberes que anula, reactiva o habilita algo ya
 * cargado, elegido de una lista para poder contarlo. Cada acto ofrece los
 * suyos (`HaberesReasonAct`); con «Otro» el detalle es obligatorio.
 *
 * Las listas no salen del relevamiento: son un punto de partida para
 * ajustar con el área.
 */
#[TypeScript]
enum HaberesReasonCode: string implements ReasonCode
{
    case DataEntryError = 'data_entry_error';
    case Duplicate = 'duplicate';
    case CaseArchived = 'case_archived';
    case CancelledByMistake = 'cancelled_by_mistake';
    case CaseReopened = 'case_reopened';
    case WrongBeneficiaryData = 'wrong_beneficiary_data';
    case WrongAmount = 'wrong_amount';
    case WrongInstallment = 'wrong_installment';
    case SafObservation = 'saf_observation';
    case ResolutionChanged = 'resolution_changed';
    case MistypedNumber = 'mistyped_number';
    case DepositNotMade = 'deposit_not_made';
    case WrongReceipt = 'wrong_receipt';
    case WrongExpediente = 'wrong_expediente';
    /**
     * Lo pone el sistema: el papel cae porque se anuló el pago fuera del
     * circuito que respaldaba. El motivo de fondo es el del pago.
     */
    case SettlementVoided = 'settlement_voided';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DataEntryError => 'Error de carga',
            self::Duplicate => 'Cargado dos veces',
            self::CaseArchived => 'El trámite se archivó',
            self::CancelledByMistake => 'Se anuló por error',
            self::CaseReopened => 'Se reabrió el trámite',
            self::WrongBeneficiaryData => 'Datos del beneficiario equivocados',
            self::WrongAmount => 'Importe equivocado',
            self::WrongInstallment => 'Era de otra cuota',
            self::SafObservation => 'Observación del SAF',
            self::ResolutionChanged => 'Cambió la resolución',
            self::MistypedNumber => 'Número mal tipeado',
            self::DepositNotMade => 'El depósito no se hizo',
            self::WrongReceipt => 'Comprobante equivocado o ilegible',
            self::WrongExpediente => 'Era de otro expediente',
            self::SettlementVoided => 'Se anuló el pago que respaldaba',
            self::Other => 'Otro',
        };
    }
}
