<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Audit;

use App\Modules\Aranceles\Audit\Subjects\FeeChargeSubject;
use App\Modules\Aranceles\Audit\Subjects\FeeTypeSubject;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditCatalogContributor;
use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Lo que Aranceles registra. Es crítico lo que cambia cuánto se propone cobrar,
 * o lo que ya estaba cargado.
 */
final class ArancelesAuditCatalog implements AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Aranceles', 36);

        $catalog->registerActions(
            new AuditActionDefinition('arancel.tipo-registrado', 'Se registró un tipo de arancel', 'Aranceles'),
            new AuditActionDefinition('arancel.tipo-corregido', 'Se corrigió un tipo de arancel', 'Aranceles', AuditSeverity::Critical),
            new AuditActionDefinition(
                'arancel.precio-agregado',
                'Se agregó un precio de arancel',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['desde' => 'Vigente desde', 'valor' => 'Valor', 'referencia' => 'Referencia legal'],
            ),
            new AuditActionDefinition(
                'arancel.precio-anulado',
                'Se anuló un precio de arancel',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['desde' => 'Vigente desde', 'valor' => 'Valor', 'reason' => 'Motivo'],
            ),
        );

        $catalog->registerActions(
            new AuditActionDefinition(
                'arancel.cobrado',
                'Se cobró un arancel',
                'Aranceles',
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'recibo' => 'Recibo'],
            ),
            new AuditActionDefinition(
                'arancel.pendiente-creado',
                'Se registró un arancel pendiente de pago',
                'Aranceles',
                metadata: ['amount' => 'Importe'],
            ),
            new AuditActionDefinition(
                'arancel.credito-identificado',
                'Se identificó un crédito como pago de un arancel',
                'Aranceles',
                metadata: ['amount' => 'Importe', 'falta' => 'Falta'],
            ),
            new AuditActionDefinition(
                'arancel.ajustado',
                'Se ajustó el importe de un arancel pendiente',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['anterior' => 'Importe anterior', 'amount' => 'Importe', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'arancel.cobro-anulado',
                'Se anuló un cobro de arancel',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['recibo' => 'Recibo', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'arancel.recibo-reemplazado',
                'Se reemplazó un recibo de arancel',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['recibo' => 'Recibo', 'nuevo' => 'Recibo nuevo', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'arancel.cobro-corregido',
                'Se corrigió un cobro de arancel',
                'Aranceles',
                AuditSeverity::Critical,
                metadata: ['anterior' => 'Importe anterior', 'amount' => 'Importe', 'recibo' => 'Recibo', 'reason' => 'Motivo'],
            ),
        );

        $catalog->registerSubject(new FeeTypeSubject);
        $catalog->registerSubject(new FeeChargeSubject);
    }
}
