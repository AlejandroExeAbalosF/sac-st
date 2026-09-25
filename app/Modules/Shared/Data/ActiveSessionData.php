<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Support\DeviceLabel;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use stdClass;

/**
 * Una sesión abierta, leída de la tabla `sessions`.
 *
 * No hay modelo de Eloquent detrás y no hace falta: la tabla la administra
 * el driver de sesión, tiene un `payload` serializado que a nadie le
 * importa acá, y darle un modelo invitaría a escribirla desde el dominio.
 * Se lee con el query builder y se presenta.
 */
#[TypeScript]
final class ActiveSessionData extends Data
{
    public function __construct(
        public string $id,
        public ?string $userName,
        public ?string $deviceLabel,
        public ?string $ipAddress,
        /** ISO 8601 en UTC; el front lo presenta en hora de Salta. */
        public string $lastActivityAt,
        /** La sesión desde la que se está mirando la pantalla. */
        public bool $isCurrent,
        /**
         * Por qué quien mira no puede cerrarla, o `null` si puede. Sale de
         * `UserManagementGuard`, el mismo lugar que después lo rechaza.
         */
        public ?string $revokeLockedReason = null,
    ) {}

    public static function fromRow(
        stdClass $row,
        ?string $currentSessionId,
        ?string $userName = null,
        ?string $revokeLockedReason = null,
    ): self {
        return new self(
            id: (string) $row->id,
            userName: $userName,
            deviceLabel: DeviceLabel::fromUserAgent($row->user_agent),
            ipAddress: $row->ip_address,
            lastActivityAt: Carbon::createFromTimestamp((int) $row->last_activity)->toIso8601String(),
            isCurrent: $currentSessionId !== null && (string) $row->id === $currentSessionId,
            revokeLockedReason: $revokeLockedReason,
        );
    }
}
