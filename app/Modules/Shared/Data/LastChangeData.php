<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * La última vez que alguien tocó algo, y quién.
 *
 * Sale de `audit_events` y no de `updated_at` a propósito. El timestamp se
 * mueve con cualquier escritura, incluidas las que nadie pidió: anular un
 * expediente hace un `update` masivo sobre sus haberes y sus cuotas, así
 * que les deja fecha de modificación de hoy a filas que nadie editó. La
 * auditoría, en cambio, registra hechos: cada fila tiene un actor y un
 * nombre de acción que alguien eligió.
 *
 * Ausente quiere decir que no pasó nada después del alta, y eso se muestra
 * como vacío. Repetir la fecha de carga en el renglón de abajo sería
 * ruido con forma de dato.
 */
#[TypeScript]
final class LastChangeData extends Data
{
    public function __construct(
        /** ISO-8601; la pantalla decide si muestra la fecha o la hora. */
        public string $at,
        /**
         * Quién lo hizo. Puede faltar: `audit_events.user_id` es nullable
         * —hay cambios que no vienen de una sesión— y la FK es
         * `nullOnDelete`, así que un usuario borrado deja el hecho sin
         * autor pero no borra el hecho.
         */
        public ?string $by,
    ) {}
}
