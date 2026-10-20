<?php

declare(strict_types=1);

namespace App\Modules\Multas\Audit;

use App\Modules\Multas\Audit\Subjects\FineCaseSubject;
use App\Modules\Multas\Audit\Subjects\FineDocumentSubject;
use App\Modules\Multas\Audit\Subjects\FineSubject;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditCatalogContributor;
use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Lo que Multas registra: expedientes, documentos, multas, su obligación y
 * sus antecedentes.
 *
 * Son críticos los hechos que cambian cuánto se debe o lo que ya estaba
 * cargado: un ajuste, una reversión, una corrección, un antecedente
 * validado.
 */
final class MultasAuditCatalog implements AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Multas', 35);

        $catalog->registerActions(
            new AuditActionDefinition('expediente-multa.registrado', 'Se registró el expediente de multa', 'Multas'),
            new AuditActionDefinition('expediente-multa.datos-completados', 'Se completaron datos del expediente de multa', 'Multas'),
            new AuditActionDefinition(
                'expediente-multa.datos-corregidos',
                'Se corrigieron datos del expediente de multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'documento-multa.registrado',
                'Se registró un documento del expediente de multa',
                'Multas',
                metadata: ['expediente' => 'Expediente'],
            ),
            new AuditActionDefinition('documento-multa.datos-completados', 'Se completaron datos del documento', 'Multas'),
            new AuditActionDefinition(
                'documento-multa.datos-corregidos',
                'Se corrigieron datos del documento',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.registrada',
                'Se registró la multa',
                'Multas',
                metadata: ['expediente' => 'Expediente', 'resolucion' => 'Resolución'],
            ),
            new AuditActionDefinition('multa.datos-completados', 'Se completaron datos de la multa', 'Multas'),
            new AuditActionDefinition(
                'multa.datos-corregidos',
                'Se corrigieron datos de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.fecha-de-movimiento-completada',
                'Se completó la fecha de un movimiento de la obligación',
                'Multas',
                metadata: ['movimiento' => 'Movimiento'],
            ),
            new AuditActionDefinition(
                'multa.fecha-de-movimiento-corregida',
                'Se corrigió la fecha de un movimiento de la obligación',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['movimiento' => 'Movimiento', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.fecha-de-antecedente-completada',
                'Se completó la fecha de un pago anterior al sistema',
                'Multas',
                metadata: ['antecedente' => 'Antecedente'],
            ),
            new AuditActionDefinition(
                'multa.fecha-de-antecedente-corregida',
                'Se corrigió la fecha de un pago anterior al sistema',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['antecedente' => 'Antecedente', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.documento-vinculado',
                'Se vinculó un documento a la multa',
                'Multas',
                metadata: ['documento' => 'Documento', 'papel' => 'Papel'],
            ),
            new AuditActionDefinition(
                'multa.ajuste-registrado',
                'Se ajustó la obligación de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['tipo' => 'Tipo', 'amount' => 'Importe', 'resolucion' => 'Resolución', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.ajuste-revertido',
                'Se revirtió un ajuste de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['tipo' => 'Ajuste revertido', 'amount' => 'Importe', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.antecedente-registrado',
                'Se registró un pago anterior al sistema',
                'Multas',
                metadata: ['tipo' => 'Tipo', 'amount' => 'Importe', 'respaldo' => 'Respaldo'],
            ),
            new AuditActionDefinition(
                'multa.antecedente-validado',
                'Se validó un pago anterior al sistema',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['amount' => 'Importe', 'respaldo' => 'Respaldo'],
            ),
            new AuditActionDefinition(
                'expediente-multa.adjunto-agregado',
                'Se adjuntó un archivo al expediente de multa',
                'Multas',
                metadata: ['titulo' => 'Descripción', 'archivo' => 'Archivo'],
            ),
            new AuditActionDefinition(
                'multa.adjunto-agregado',
                'Se adjuntó un archivo a la multa',
                'Multas',
                metadata: ['titulo' => 'Descripción', 'archivo' => 'Archivo'],
            ),
            new AuditActionDefinition(
                'multa.respaldo-de-antecedente-agregado',
                'Se adjuntó el comprobante de un pago anterior al sistema',
                'Multas',
                metadata: ['titulo' => 'Descripción', 'archivo' => 'Archivo'],
            ),
            new AuditActionDefinition(
                'multa.antecedente-descartado',
                'Se descartó un pago anterior al sistema',
                'Multas',
                metadata: ['amount' => 'Importe', 'respaldo' => 'Respaldo', 'reason' => 'Motivo'],
            ),
        );

        $catalog->registerSubject(new FineCaseSubject);
        $catalog->registerSubject(new FineDocumentSubject);
        $catalog->registerSubject(new FineSubject);
    }
}
