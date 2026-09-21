<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Urgencia de una cola de trabajo del tablero.
 *
 * No es decoración: define el color con el que se lee el número. `Action`
 * significa "esto espera que alguien haga algo"; `Blocked`, "esto no puede
 * avanzar hasta que se resuelva algo de afuera".
 */
#[TypeScript]
enum QueueTone: string
{
    case Neutral = 'neutral';
    case Action = 'action';
    case Blocked = 'blocked';
    case Done = 'done';
}
