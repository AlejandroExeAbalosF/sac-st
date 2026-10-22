<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Models\PeriodClosing;

/**
 * Lo que la caja necesita saber del módulo que la opera.
 *
 * Arqueo, cierre y planilla son de Ledger y valen para cualquier caja. Pero
 * hay preguntas que Ledger no puede contestar sin conocer el dominio: qué
 * permiso autoriza cada acto, a quién pertenece cada cheque del cajón y qué
 * significa la columna de banco. Cada módulo responde por su caja y se
 * registra en `AppServiceProvider`, igual que `OpeningDateRule`.
 *
 * @phpstan-type ChequeColumn array{key: string, header: string}
 */
interface CashBoxModule
{
    /** El permiso que autoriza ese acto en esta caja. */
    public function permission(CashBoxAbility $ability): string;

    /**
     * El inventario del reverso: los cheques que estaban en el cajón al
     * terminar el período, con las columnas que declara `chequeColumns()` y
     * además `number`, `bank`, `date` y `amount`.
     *
     * Es la cartera **a esa fecha**: la posición sale de
     * `ChequeCustodyAt`, y el módulo agrega sus propias salidas y quién es
     * cada cheque. Solo lo llama el cierre, con la caja bloqueada.
     *
     * @return list<array<string, string>>
     */
    public function chequesInCustody(PeriodClosing $closing): array;

    /**
     * Las columnas que identifican cada cheque en el reverso, antes de las
     * del cheque. Se congelan con la evidencia: una planilla rehecha no le
     * pregunta al módulo de hoy cómo era el formato.
     *
     * @return list<ChequeColumn>
     */
    public function chequeColumns(): array;

    /**
     * Qué representa la columna de banco, si hay que aclararlo. Nulo cuando
     * la cuenta es solo de esta caja.
     */
    public function bankColumnNote(): ?string;
}
