<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un ingreso propio, para mostrarle al usuario su actividad reciente.
 *
 * Que cada uno vea sus últimos accesos es la forma más barata de detectar
 * un uso indebido de credenciales: nadie conoce mejor que el titular si
 * ese ingreso del sábado a las 23:10 fue suyo.
 */
#[TypeScript]
final class RecentAccessData extends Data
{
    public function __construct(
        public int $id,
        public LoginEventType $type,
        public string $label,
        public ?string $deviceLabel,
        public ?string $ipAddress,
        /** ISO 8601 en UTC; el front lo presenta en hora de Salta. */
        public string $occurredAt,
    ) {}

    public static function fromEvent(UserLoginEvent $event): self
    {
        return new self(
            id: $event->id,
            type: $event->event_type,
            label: $event->event_type->label(),
            deviceLabel: $event->device_label,
            ipAddress: $event->ip_address,
            occurredAt: $event->created_at->toIso8601String(),
        );
    }
}
