<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Audit;

use App\Modules\Ledger\Audit\References\FinancialEventReference;
use App\Modules\Ledger\Audit\References\FundReceiptReference;
use App\Modules\Ledger\Audit\Subjects\CashCountSubject;
use App\Modules\Ledger\Audit\Subjects\PeriodClosingSubject;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditCatalogContributor;
use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Lo que Ledger registra: la caja, sus arqueos y cierres, y las
 * recepciones de fondos.
 *
 * Las recepciones son de Ledger aunque se registren desde el banco: la
 * tabla es suya, y el código se define acá una sola vez. Quien las
 * describe con nombre y enlace es Haberes, que tiene su pantalla.
 */
final class LedgerAuditCatalog implements AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Recepciones y cobros', 20);
        $catalog->registerCategory('Caja', 40);

        $catalog->registerActions(
            new AuditActionDefinition('recepcion.registrada', 'Se registró la recepción de fondos', 'Recepciones y cobros'),
            new AuditActionDefinition(
                'recepcion.revertida',
                'Se revirtió la recepción de fondos',
                'Recepciones y cobros',
                AuditSeverity::Critical,
                metadata: ['amount' => 'Importe'],
            ),

            new AuditActionDefinition('arqueo.registrado', 'Se registró el arqueo', 'Caja'),
            new AuditActionDefinition('arqueo.corregido', 'Se corrigió el arqueo', 'Caja'),
            new AuditActionDefinition('arqueo.revisado', 'Se revisó el arqueo', 'Caja'),
            // Mueve plata contra la cuenta de diferencias sin que haya
            // entrado ni salido nada del cajón.
            new AuditActionDefinition(
                'arqueo.diferencia-imputada',
                'Se imputó la diferencia de arqueo',
                'Caja',
                AuditSeverity::Critical,
                metadata: ['autorizacion' => 'Autorización'],
            ),
            new AuditActionDefinition('periodo.cerrado', 'Se cerró el período', 'Caja'),
            new AuditActionDefinition('periodo.reabierto', 'Se reabrió el período', 'Caja', AuditSeverity::Critical),
            new AuditActionDefinition('planilla.regenerada', 'Se rehízo la planilla del cierre', 'Caja', AuditSeverity::Critical),
            new AuditActionDefinition(
                'caja.haber-anterior-pagado',
                'Se pagó un haber anterior al sistema',
                'Caja',
                metadata: ['referencia' => 'Referencia'],
            ),
        );

        $catalog->registerSubject(new CashCountSubject);
        $catalog->registerSubject(new PeriodClosingSubject);

        $catalog->registerReference(new FinancialEventReference);
        $catalog->registerReference(new FundReceiptReference);
    }
}
