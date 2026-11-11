<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use App\Modules\Shared\Support\ReasonCode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El motivo de un acto de Shared sobre un adjunto, elegido de una lista para
 * poder contarlo. La lista no sale del relevamiento: es un punto de partida
 * para ajustar con el área. La base repite los códigos en el CHECK de
 * `attachments.void_reason_code`.
 */
#[TypeScript]
enum SharedReasonCode: string implements ReasonCode
{
    case WrongFile = 'wrong_file';
    case WrongSubject = 'wrong_subject';
    case Illegible = 'illegible';
    case Duplicate = 'duplicate';
    case SensitiveData = 'sensitive_data';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::WrongFile => 'Se subió el archivo equivocado',
            self::WrongSubject => 'Corresponde a otro trámite',
            self::Illegible => 'No se lee',
            self::Duplicate => 'Está repetido',
            self::SensitiveData => 'Tiene datos sensibles de otra persona',
            self::Other => 'Otro',
        };
    }
}
