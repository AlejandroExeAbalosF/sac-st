<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use App\Models\User;

/**
 * Lo que la auditoría necesita saber de un tipo de sujeto.
 *
 * Lo registra el módulo que tiene la pantalla del sujeto, que no siempre
 * es el dueño de la tabla: una recepción vive en Ledger, pero se mira
 * desde Haberes, y es Haberes quien sabe a qué haber lleva.
 */
interface AuditSubjectResolver
{
    /** El `class_basename` del modelo, tal como lo guarda `RecordAuditEvent`. */
    public function subjectType(): string;

    /** Cómo se llama el sujeto en singular: «Cuota», «Orden de pago». */
    public function label(): string;

    /**
     * Los rótulos de los campos de `old_values` y `new_values`.
     *
     * @return array<string, string>
     */
    public function fields(): array;

    /**
     * Las traducciones de los valores propios de este sujeto —estados,
     * medios—, por campo. Las referencias a otras filas no van acá sino en
     * un `AuditReferenceResolver`, que es de todos los sujetos.
     *
     * @return array<string, array<array-key, string>>
     */
    public function valueLabels(): array;

    /**
     * Los campos que son importes aunque su nombre no lo diga.
     *
     * `amount` y `*_amount` ya se reconocen solos; esto es para
     * `closing_cash` y compañía, que sin declararse saldrían como número
     * pelado.
     *
     * @return list<string>
     */
    public function moneyFields(): array;

    /**
     * Nombre y enlace de cada sujeto, en lote.
     *
     * Devuelve una entrada por cada id que todavía exista. Los que falten
     * los completa el catálogo con una descripción estable.
     *
     * @param  list<int>  $ids
     * @return array<int, AuditSubjectDescription>
     */
    public function describe(array $ids, ?User $viewer): array;
}
