<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un evento de la auditoría de operaciones, listo para leer.
 *
 * `id` se muestra a propósito: es el `audit_id` que el registro de acceso
 * HTTP deja en `context.changes`, y es la llave para cruzar este evento
 * con la solicitud que lo provocó.
 *
 * `userName` nulo quiere decir que no lo provocó una persona —una
 * importación, un comando—.
 */
#[TypeScript]
final class OperationAuditEventData extends Data
{
    public function __construct(
        public int $id,
        /** ISO 8601 en UTC; el front lo presenta en hora de Salta. */
        public string $occurredAt,
        public ?string $userName,
        public string $action,
        public string $label,
        public bool $critical,
        public string $category,
        public string $subjectType,
        public int $subjectId,
        /** Cómo se llama el tipo de sujeto: «Cuota», «Orden de pago». */
        public string $subjectLabel,
        /** Cuál es: «Cuota 2 · Haber 1 · Expte. 125958/2026». */
        public string $subjectDescription,
        /** Solo si el registro existe y quien mira puede abrir su pantalla. */
        public ?string $subjectUrl,
        /** @var list<AuditChangeData> */
        public array $changes,
        public ?string $ipAddress,
    ) {}
}
