<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Audit;

use App\Modules\Haberes\Audit\References\ManagementLabelReference;
use App\Modules\Haberes\Audit\Subjects\CashToBankTransferSubject;
use App\Modules\Haberes\Audit\Subjects\DepositTicketSubject;
use App\Modules\Haberes\Audit\Subjects\ExpedienteSubject;
use App\Modules\Haberes\Audit\Subjects\FundReceiptSubject;
use App\Modules\Haberes\Audit\Subjects\HaberSubject;
use App\Modules\Haberes\Audit\Subjects\InstallmentChildSubject;
use App\Modules\Haberes\Audit\Subjects\InstallmentSubject;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditCatalogContributor;
use App\Modules\Shared\Enums\AuditSeverity;
use App\Modules\Shared\Models\Receipt;
use App\Support\EnumLabels;
use Illuminate\Database\Eloquent\Model;

/**
 * Lo que Haberes registra: el expediente, sus haberes y cuotas, y todo lo
 * que les pasa hasta que el dinero llega a su dueño.
 *
 * También describe sujetos cuyas tablas son de otros módulos —la
 * recepción, el recibo, el traslado—, porque sus pantallas son de acá.
 */
final class HaberesAuditCatalog implements AuditCatalogContributor
{
    public function __construct(private readonly InstallmentLinks $links) {}

    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Haberes', 10);
        $catalog->registerCategory('Pagos', 30);

        $this->actions($catalog);
        $this->subjects($catalog);

        $catalog->registerReference(new ManagementLabelReference);
    }

    private function actions(AuditCatalog $catalog): void
    {
        $catalog->registerActions(
            new AuditActionDefinition('expediente.registrado', 'Se registró el expediente', 'Haberes'),
            new AuditActionDefinition('expediente.corregido', 'Se corrigió la ficha del expediente', 'Haberes'),
            new AuditActionDefinition('expediente.fecha-ingreso-corregida', 'Se corrigió la fecha de ingreso', 'Haberes'),
            new AuditActionDefinition('expediente.anulado', 'Se anuló el expediente', 'Haberes', AuditSeverity::Critical),
            new AuditActionDefinition('expediente.reactivado', 'Se reactivó el expediente', 'Haberes'),
            new AuditActionDefinition('haber.reconocido', 'Se reconoció el haber', 'Haberes'),
            new AuditActionDefinition('haber.anulado', 'Se anuló el haber', 'Haberes', AuditSeverity::Critical),
            new AuditActionDefinition('haber.reactivado', 'Se reactivó el haber', 'Haberes'),
            new AuditActionDefinition('cuota.agregada', 'Se cargó la cuota', 'Haberes'),
            // Corregir el importe de una cuota cambia cuánto se le debe a
            // alguien.
            new AuditActionDefinition('cuota.corregida', 'Se corrigió la cuota', 'Haberes', AuditSeverity::Critical),
            new AuditActionDefinition(
                'cuota.medio-alineado',
                'Se alineó el medio con el comprobante bancario cargado',
                'Haberes',
            ),
            new AuditActionDefinition(
                'cuota.edicion-habilitada',
                'Se habilitó la edición de una cuota con orden emitida',
                'Haberes',
                AuditSeverity::Critical,
            ),

            new AuditActionDefinition('cuota.financiada', 'Se asignaron fondos a la cuota', 'Recepciones y cobros'),
            new AuditActionDefinition(
                'asignacion.desasignada',
                'Se desasignaron fondos de la cuota',
                'Recepciones y cobros',
                AuditSeverity::Critical,
                metadata: ['devuelto' => 'Importe devuelto'],
                money: ['devuelto'],
            ),
            new AuditActionDefinition('recibo.emitido', 'Se emitió el recibo de ingreso', 'Recepciones y cobros'),
            new AuditActionDefinition(
                'cobro.anulado',
                'Se anuló el cobro',
                'Recepciones y cobros',
                AuditSeverity::Critical,
                metadata: ['amount' => 'Importe'],
            ),
            new AuditActionDefinition('ticket.registrado', 'Se registró el ticket de depósito', 'Recepciones y cobros'),
            new AuditActionDefinition(
                'ticket.corregido',
                'Se corrigió el ticket de depósito',
                'Recepciones y cobros',
                metadata: ['foto' => 'Foto'],
            ),
            new AuditActionDefinition('ticket.vinculado', 'Se vinculó el ticket con el extracto', 'Recepciones y cobros'),
            new AuditActionDefinition('ticket.desvinculado', 'Se desvinculó el ticket del extracto', 'Recepciones y cobros'),
            new AuditActionDefinition(
                'ticket.descartado',
                'Se descartó el ticket de depósito',
                'Recepciones y cobros',
                AuditSeverity::Critical,
            ),
            new AuditActionDefinition('ticket.reabierto', 'Se reabrió el ticket de depósito', 'Recepciones y cobros'),

            new AuditActionDefinition('orden-de-pago.emitida', 'Se emitió la orden de pago', 'Pagos'),
            new AuditActionDefinition('orden-de-pago.detalles-corregidos', 'Se corrigieron datos de la orden de pago', 'Pagos'),
            new AuditActionDefinition('orden-de-pago.anulada', 'Se anuló la orden de pago', 'Pagos', AuditSeverity::Critical),
            new AuditActionDefinition('egreso.entregado', 'Se entregó el pago al beneficiario', 'Pagos'),
            new AuditActionDefinition('egreso.informado', 'Se informó la transferencia', 'Pagos'),
            new AuditActionDefinition('egreso.debito-reconocido', 'Se reconoció el débito en el extracto', 'Pagos'),
            new AuditActionDefinition('egreso.debito-desvinculado', 'Se desvinculó el débito del extracto', 'Pagos'),
            new AuditActionDefinition('egreso.validado', 'Se validó el egreso', 'Pagos'),
            new AuditActionDefinition('recibo-egreso.emitido', 'Se emitió el recibo de egreso', 'Pagos'),
        );
    }

    private function subjects(AuditCatalog $catalog): void
    {
        $catalog->registerSubject(new ExpedienteSubject($this->links));
        $catalog->registerSubject(new HaberSubject($this->links));
        $catalog->registerSubject(new InstallmentSubject($this->links));
        $catalog->registerSubject(new CashToBankTransferSubject($this->links));
        $catalog->registerSubject(new DepositTicketSubject($this->links));
        $catalog->registerSubject(new FundReceiptSubject);

        $catalog->registerSubject(new InstallmentChildSubject(
            $this->links,
            'PaymentOrder',
            'Orden de pago',
            PaymentOrder::class,
            ['formatted_number'],
            fn (Model $orden): string => 'Orden de pago '.$orden->getAttribute('formatted_number'),
            [
                'formatted_number' => 'Número',
                'amount' => 'Importe',
                'beneficiary_cbu_snapshot' => 'CBU del beneficiario',
                'pase_id' => 'Pase (id interno)',
                'pase_destination' => 'Destino del pase',
                'destination' => 'Destino del pase',
                'notes' => 'Observaciones',
                'cbu_folio_snapshot' => 'Foja del CBU',
                'reason' => 'Motivo',
            ],
        ));

        $catalog->registerSubject(new InstallmentChildSubject(
            $this->links,
            'Disbursement',
            'Egreso',
            Disbursement::class,
            [],
            fn (Model $egreso): string => 'Egreso n.º '.$egreso->getKey(),
            [
                'method' => 'Medio',
                'amount' => 'Importe',
                'payment_date' => 'Fecha de pago',
                'status' => 'Estado',
                'report_received_at' => 'Informe recibido',
                'transfer_reference' => 'Referencia de la transferencia',
                'transaction_date' => 'Fecha del débito',
                'bank_transaction_id' => 'Movimiento del extracto',
                'financial_event_id' => 'Asiento',
                'payment_order_id' => 'Orden de pago (id interno)',
                'reason' => 'Motivo',
            ],
            [
                'method' => EnumLabels::of(DisbursementMethod::class),
                'status' => EnumLabels::of(DisbursementStatus::class),
            ],
        ));

        $catalog->registerSubject(new InstallmentChildSubject(
            $this->links,
            'FundingAllocation',
            'Asignación de fondos',
            FundingAllocation::class,
            [],
            fn (Model $asignacion): string => 'Asignación n.º '.$asignacion->getKey(),
            [
                'fund_receipt_id' => 'Recepción',
                'amount' => 'Importe',
                'financiada_completa' => 'Cuota financiada completa',
            ],
        ));

        // El recibo es de Shared, pero se emite y se mira desde la cuota.
        $catalog->registerSubject(new InstallmentChildSubject(
            $this->links,
            'Receipt',
            'Recibo',
            Receipt::class,
            ['formatted_number'],
            fn (Model $recibo): string => 'Recibo '.$recibo->getAttribute('formatted_number'),
            [
                'formatted_number' => 'Número',
                'talonario_number' => 'Número de talonario',
                'amount' => 'Importe',
                'beneficiary' => 'Beneficiario',
                'disbursement_id' => 'Egreso (id interno)',
            ],
        ));
    }
}
