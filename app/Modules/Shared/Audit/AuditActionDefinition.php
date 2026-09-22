<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Qué significa un código de `audit_events.action`.
 *
 * El código es lo que se guarda y no cambia; el rótulo y la categoría son
 * presentación y pueden corregirse sin tocar el historial.
 */
final readonly class AuditActionDefinition
{
    /**
     * @param  string  $category  Una categoría registrada en el catálogo: un concepto del área, no un módulo.
     * @param  array<string, string>  $metadata  Las claves de `metadata` que se muestran, con su rótulo. Las
     *                                           demás quedan en la tabla y no salen: el JSON entero no se vuelca.
     * @param  list<string>  $money  Las claves de `metadata` que son importes aunque su nombre no lo diga.
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $category,
        public AuditSeverity $severity = AuditSeverity::Normal,
        public array $metadata = [],
        public array $money = [],
    ) {}

    public function isCritical(): bool
    {
        return $this->severity === AuditSeverity::Critical;
    }
}
