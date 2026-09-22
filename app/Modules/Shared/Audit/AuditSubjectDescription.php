<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

/**
 * Cómo se nombra el sujeto de un evento, y adónde lleva.
 *
 * `url` es nula cuando el registro ya no existe, cuando no tiene pantalla
 * propia o cuando quien mira no puede abrirla: un enlace que termina en un
 * 403 o en un 404 es peor que ninguno.
 */
final readonly class AuditSubjectDescription
{
    public function __construct(
        public string $label,
        public ?string $url = null,
    ) {}
}
