<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use App\Modules\Shared\Support\Reason;
use App\Modules\Shared\Support\ReasonAct;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los actos de Shared que piden motivo de una lista.
 */
#[TypeScript]
enum SharedReasonAct: string implements ReasonAct
{
    /** Sacar de la vista un adjunto opcional, sin borrarlo. */
    case VoidAttachment = 'void_attachment';

    /**
     * Eliminar del todo un adjunto ya anulado. El motivo no tiene columna:
     * la fila desaparece, y queda en la auditoría.
     */
    case PurgeAttachment = 'purge_attachment';

    /**
     * @return list<SharedReasonCode>
     */
    public function codes(): array
    {
        return match ($this) {
            self::VoidAttachment => SharedReasonCode::cases(),
            // Lo que justifica que ni siquiera quede guardado.
            self::PurgeAttachment => [SharedReasonCode::SensitiveData, SharedReasonCode::WrongFile, SharedReasonCode::Other],
        };
    }

    /**
     * @return array<string, list<array{value: string|int, label: string}>>
     */
    public static function options(): array
    {
        return Reason::options(self::class);
    }
}
