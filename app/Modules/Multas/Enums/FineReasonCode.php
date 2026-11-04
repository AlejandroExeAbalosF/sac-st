<?php

declare(strict_types=1);

namespace App\Modules\Multas\Enums;

use App\Modules\Shared\Support\ReasonCode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El motivo de un acto que deshace o corrige algo ya cargado, elegido de una
 * lista para poder contarlo. Cada acto ofrece los suyos (`FineReasonAct`);
 * con «Otro» el detalle es obligatorio.
 *
 * Las listas no salen del relevamiento: son un punto de partida para
 * ajustar con el área.
 */
#[TypeScript]
enum FineReasonCode: string implements ReasonCode
{
    case DataEntryError = 'data_entry_error';
    case Duplicate = 'duplicate';
    case WrongFine = 'wrong_fine';
    case WrongDocument = 'wrong_document';
    case MisreadPaper = 'misread_paper';
    case AppealAdmitted = 'appeal_admitted';
    case CourtRuling = 'court_ruling';
    case ResolutionCorrection = 'resolution_correction';
    case NotCredited = 'not_credited';
    case NoEvidence = 'no_evidence';
    case MediumChange = 'medium_change';
    case WrongBankCredit = 'wrong_bank_credit';
    case CreditOfAnotherBox = 'credit_of_another_box';
    /** Lo pone el sistema: el pago informado se reemplazó por su corrección. */
    case ReplacedByCorrection = 'replaced_by_correction';
    /** Lo pone el sistema: la reversión de la reducción de un veredicto. */
    case AppealVerdictReverted = 'appeal_verdict_reverted';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DataEntryError => 'Error de carga',
            self::Duplicate => 'Cargado dos veces',
            self::WrongFine => 'Era de otra multa',
            self::WrongDocument => 'Documento equivocado',
            self::MisreadPaper => 'Se leyó mal el papel',
            self::AppealAdmitted => 'Recurso admitido',
            self::CourtRuling => 'Fallo judicial',
            self::ResolutionCorrection => 'Corrige una resolución anterior',
            self::NotCredited => 'No se acreditó en el banco',
            self::NoEvidence => 'Sin respaldo que lo pruebe',
            self::MediumChange => 'Se paga por otro medio',
            self::WrongBankCredit => 'Se eligió otro crédito',
            self::CreditOfAnotherBox => 'El crédito no era de Multas',
            self::ReplacedByCorrection => 'Reemplazado por su corrección',
            self::AppealVerdictReverted => 'Se revirtió el veredicto',
            self::Other => 'Otro',
        };
    }
}
