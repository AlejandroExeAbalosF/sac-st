<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

/**
 * De dónde salió el papel — §9.8 del DER.
 *
 * Existe porque el área está en transición: hoy emite del talonario
 * preimpreso y lo carga en el sistema después, y va a llegar el día en
 * que el sistema imprima. Distinguirlos permite convivir sin que el
 * número del sistema deje de ser el identificador en ninguno de los dos
 * casos.
 *
 * Es también lo que da sentido a `recorded_at` y `recorded_by`: en el
 * modo talonario, quien emitió y firmó el papel puede no ser quien lo
 * cargó, y entre una cosa y la otra pueden pasar días.
 */
enum ReceiptIssueMode: string
{
    /** Lo generó el sistema. */
    case Online = 'online';

    /** Se escribió en el talonario y se cargó después. */
    case OfflineTalonario = 'offline_talonario';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Emitido por el sistema',
            self::OfflineTalonario => 'Talonario, cargado después',
        };
    }
}
