<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit;

use App\Modules\Banking\Audit\References\BankAccountReference;
use App\Modules\Banking\Audit\References\BankTransactionReference;
use App\Modules\Banking\Audit\Subjects\BankAccountSubject;
use App\Modules\Banking\Audit\Subjects\BankStatementImportSubject;
use App\Modules\Banking\Audit\Subjects\BankTransactionSubject;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditCatalogContributor;
use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Lo que Banking registra: las cuentas del organismo, los extractos, sus
 * movimientos y el traslado del efectivo al banco.
 *
 * El traslado se describe desde Haberes, que es quien sabe de qué cuota
 * era el efectivo y tiene la pantalla adonde llevar.
 */
final class BankingAuditCatalog implements AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Banco', 50);

        $catalog->registerActions(
            new AuditActionDefinition('banco.cuenta.registrada', 'Se registró una cuenta del organismo', 'Banco'),
            new AuditActionDefinition('banco.cuenta.corregida', 'Se corrigió la cuenta del organismo', 'Banco'),
            new AuditActionDefinition('banco.extracto.importado', 'Se importó el extracto', 'Banco'),
            new AuditActionDefinition('banco.extracto.rechazado', 'Se rechazó la importación del extracto', 'Banco'),
            new AuditActionDefinition(
                'banco.extracto.revertido',
                'Se revirtió la importación del extracto',
                'Banco',
                AuditSeverity::Critical,
                metadata: ['movimientos_borrados' => 'Movimientos borrados'],
            ),
            // Saca un movimiento de la cola de identificación sin que nadie
            // diga de quién es la plata.
            new AuditActionDefinition(
                'banco.movimiento.fuera-del-circuito',
                'Se dejó un movimiento fuera del circuito',
                'Banco',
                AuditSeverity::Critical,
            ),

            new AuditActionDefinition('traslado.depositado', 'Se depositó el efectivo en el banco', 'Caja'),
            new AuditActionDefinition('traslado.acreditado', 'Se acreditó el depósito en el extracto', 'Caja'),
            new AuditActionDefinition(
                'traslado.cancelado',
                'Se canceló el depósito y el efectivo volvió a la caja',
                'Caja',
                AuditSeverity::Critical,
                metadata: ['amount' => 'Importe'],
            ),
        );

        $catalog->registerSubject(new BankAccountSubject);
        $catalog->registerSubject(new BankStatementImportSubject);
        $catalog->registerSubject(new BankTransactionSubject);

        $catalog->registerReference(new BankAccountReference);
        $catalog->registerReference(new BankTransactionReference);
    }
}
