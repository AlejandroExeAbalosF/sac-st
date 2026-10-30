<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Enums;

/**
 * Lo que se puede hacer en las pantallas de caja de Ledger.
 *
 * Las pantallas son las mismas para todas las cajas, pero el permiso es de
 * cada una: cerrar Haberes no habilita a cerrar Multas. Ledger pregunta por
 * el acto y el módulo de la caja dice qué permiso lo autoriza
 * (`CashBoxModule::permission`).
 */
enum CashBoxAbility: string
{
    case View = 'ver';
    case ViewClosings = 'ver-cierres';
    case Count = 'arquear';
    case ReviewCount = 'revisar-arqueo';
    case AdjustDifference = 'ajustar-diferencia';
    case Close = 'cerrar';
    case Reopen = 'reabrir';
    case Export = 'exportar';
    case RegenerateSheet = 'regenerar-planilla';
    case Open = 'abrir';
    case ManageAccounts = 'cuentas';
}
