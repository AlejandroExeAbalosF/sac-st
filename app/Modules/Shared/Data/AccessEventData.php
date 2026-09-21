<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un evento del historial de accesos, con todo lo que hace falta para
 * leerlo desde afuera de la propia cuenta.
 *
 * Se distingue de `RecentAccessData` —el resumen del tablero— en tres
 * campos que solo importan en la pantalla de auditoría: de quién fue,
 * qué nombre se tipeó cuando el intento no correspondía a nadie, y por
 * qué falló.
 *
 * `userName` viene en `null` en el caso más interesante de todos: alguien
 * probando un usuario que no existe. Esa fila no tiene a quién apuntar y
 * es justamente la que hay que conservar.
 */
#[TypeScript]
final class AccessEventData extends Data
{
    public function __construct(
        public int $id,
        public LoginEventType $type,
        public string $label,
        public ?int $userId,
        public ?string $userName,
        public ?string $usernameAttempted,
        public ?string $failureReason,
        public ?string $deviceLabel,
        public ?string $ipAddress,
        public bool $suspicious,
        /** ISO 8601 en UTC; el front lo presenta en hora de Salta. */
        public string $occurredAt,
    ) {}

    public static function fromEvent(UserLoginEvent $event): self
    {
        return new self(
            id: $event->id,
            type: $event->event_type,
            label: $event->event_type->label(),
            userId: $event->user_id,
            userName: $event->relationLoaded('user') ? $event->user?->name : null,
            usernameAttempted: $event->username_attempted,
            failureReason: $event->failure_reason?->label(),
            deviceLabel: $event->device_label,
            ipAddress: $event->ip_address,
            suspicious: $event->event_type->isSuspicious(),
            occurredAt: $event->created_at->toIso8601String(),
        );
    }
}
