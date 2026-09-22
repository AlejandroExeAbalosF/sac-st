<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Cuánto pesa una acción de auditoría para quien revisa.
 *
 * Crítica es lo que deshace, fuerza o reabre algo: anular un cobro,
 * revertir una recepción, reabrir un período, verificar un CBU salteando
 * el cotejo. No quiere decir que esté mal hacerlo —el circuito lo prevé—,
 * sino que es lo primero que un control mira.
 */
#[TypeScript]
enum AuditSeverity: string
{
    case Normal = 'normal';
    case Critical = 'critical';
}
