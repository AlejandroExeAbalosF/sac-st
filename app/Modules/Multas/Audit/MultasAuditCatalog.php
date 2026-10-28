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
                'multa.resolucion-de-origen-reemplazada',
                'Se reemplazó la resolución de origen de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['anterior' => 'Resolución anterior', 'nueva' => 'Resolución nueva', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.documento-desvinculado',
                'Se desvinculó un documento de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['documento' => 'Documento', 'papel' => 'Papel', 'reason' => 'Motivo'],
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
                'documento-multa.adjunto-agregado',
                'Se adjuntó el archivo de un documento de multa',
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
            new AuditActionDefinition(
                'multa.pago-informado',
                'Se informó un pago bancario de la multa',
                'Multas',
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'judicial' => 'Orden de pago judicial', 'corrige' => 'Corrección'],
            ),
            new AuditActionDefinition(
                'multa.pago-verificado',
                'Se verificó un pago bancario de la multa contra el extracto',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'recibo' => 'Recibo', 'credito' => 'Crédito del banco'],
            ),
            new AuditActionDefinition(
                'multa.pago-descartado',
                'Se descartó un pago de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'reason' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.recurso-registrado',
                'Se registró un pago por recurso de la multa',
                'Multas',
                metadata: ['tipo' => 'Recurso', 'medio' => 'Medio', 'base' => 'Base', 'exigido' => '20 % calculado', 'externo' => 'Recibo externo', 'importe' => 'Importe del recibo externo', 'recibo' => 'Recibo', 'corrige' => 'Corrección'],
            ),
            new AuditActionDefinition(
                'multa.comprobante-de-pago-agregado',
                'Se adjuntó el comprobante de un pago de la multa',
                'Multas',
                metadata: ['titulo' => 'Descripción', 'archivo' => 'Archivo'],
            ),
            new AuditActionDefinition(
                'multa.pago-cobrado',
                'Se cobró un pago de la multa en caja',
                'Multas',
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'recibo' => 'Recibo', 'judicial' => 'Orden de pago judicial'],
            ),
            new AuditActionDefinition(
                'multa.cobro-depositado',
                'Se depositó en el banco un cobro de la multa',
                'Multas',
                metadata: ['medio' => 'Medio', 'amount' => 'Importe', 'recibo' => 'Recibo', 'fecha' => 'Fecha del depósito', 'traslado' => 'Traslado'],
            ),
            new AuditActionDefinition(
                'multa.deposito-acreditado',
                'Se acreditó en el extracto el depósito de un cobro de la multa',
                'Multas',
                metadata: ['amount' => 'Importe', 'traslado' => 'Traslado', 'credito' => 'Crédito del banco'],
            ),
            new AuditActionDefinition(
                'multa.deposito-cancelado',
                'Se canceló el depósito de un cobro de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['amount' => 'Importe', 'traslado' => 'Traslado', 'motivo' => 'Motivo'],
            ),
            new AuditActionDefinition(
                'multa.cheque-rechazado',
                'Se rechazó el cheque de un pago de la multa',
                'Multas',
                AuditSeverity::Critical,
                metadata: ['cheque' => 'Cheque', 'amount' => 'Importe', 'fecha' => 'Fecha del rechazo', 'motivo' => 'Motivo', 'banco' => 'Banco'],
            ),
            new AuditActionDefinition(
                'multa.cheque-reemplazado',
                'Se recibió el cheque que reemplaza al rechazado',
                'Multas',
                metadata: ['rechazado' => 'Cheque rechazado', 'cheque' => 'Cheque de reemplazo', 'amount' => 'Importe'],
            ),
            new AuditActionDefinition(
                'multa.rechazo-banco-registrado',
                'Se registró en el extracto un movimiento del cheque rechazado',
                'Multas',
                metadata: ['credito' => 'Crédito del extracto', 'debito' => 'Débito del extracto', 'banco' => 'Banco'],
            ),
            new AuditActionDefinition(
                'multa.rechazo-nunca-acreditado',
                'Se confirmó que el banco nunca acreditó el cheque rechazado',
                'Multas',
                metadata: ['nota' => 'Cómo se comprobó'],
            ),
            new AuditActionDefinition(
                'multa.rechazo-completado',
                'Se completó el aviso o la disposición del cheque rechazado',
                'Multas',
                metadata: ['aviso' => 'Aviso a la empresa', 'disposicion' => 'Qué se hizo con el cheque'],
            ),
            new AuditActionDefinition(
                'multa.rendicion-emitida',
                'Se emitió la rendición mensual de Multas',
                'Multas',
                metadata: [
                    'mes' => 'Mes rendido',
                    'version' => 'Versión del cierre',
                    'en_termino' => 'Total en término',
                    'fuera_de_termino' => 'Total fuera de término',
                ],
            ),
        );

        $catalog->registerSubject(new FineCaseSubject);
        $catalog->registerSubject(new FineDocumentSubject);
        $catalog->registerSubject(new FineSubject);
    }
}
