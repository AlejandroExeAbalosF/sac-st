import { date } from '@/lib/format';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Orden = App.Modules.Haberes.Data.InstallmentOrderStateData;
type Egreso = App.Modules.Haberes.Data.InstallmentDisbursementData;

type Hito = {
    /** Qué pasó. */
    titulo: string;
    /** Cuándo, en ISO. */
    fecha: string;
    /** El comprobante, quién lo hizo, lo que ubique el hecho. */
    detalle?: string | null;
};

/**
 * El recorrido de la cuota: qué pasó, cuándo y con qué papel.
 *
 * Los datos estaban todos, repartidos entre el recibo, el traslado, la
 * Orden y el egreso, y había que abrir cuatro bloques distintos para
 * reconstruir una historia que se cuenta en una sola línea.
 *
 * **Solo lo que ocurrió.** Un hito que todavía no pasó no se dibuja en
 * gris: la lista es lo que hay, no un formulario a completar. Lo que falta
 * se lee en la etapa actual, que está arriba y dice exactamente qué se
 * está esperando.
 *
 * No consulta nada: las fechas ya viajan a esta pantalla.
 */
export default function InstallmentTimeline({
    cuota,
    orden,
    egreso,
}: {
    cuota: Cuota;
    orden?: Orden;
    egreso?: Egreso;
}) {
    const hitos = recorridoDe(cuota, orden, egreso);

    return (
        <ol className="space-y-2">
            {hitos.map((hito) => (
                <li
                    key={`${hito.titulo}-${hito.fecha}`}
                    className="flex gap-3 text-xs"
                >
                    <span className="w-[4.5rem] shrink-0 text-muted-foreground tabular-nums">
                        {date(hito.fecha)}
                    </span>
                    <span className="flex-1">
                        {hito.titulo}
                        {hito.detalle && (
                            <span className="text-muted-foreground">
                                {' · '}
                                {hito.detalle}
                            </span>
                        )}
                    </span>
                </li>
            ))}
        </ol>
    );
}

/**
 * Los hitos que ocurrieron, del más viejo al más nuevo.
 *
 * Se ordena por fecha y no por el orden del circuito: el §12.3 y el §12.4
 * describen el informe del organismo y el débito del extracto llegando en
 * cualquier orden, así que dibujarlos siempre en la misma secuencia
 * contaría una historia que no fue.
 */
function recorridoDe(cuota: Cuota, orden?: Orden, egreso?: Egreso): Hito[] {
    const hitos: Hito[] = [{ titulo: 'Cuota cargada', fecha: cuota.createdAt }];

    if (cuota.incomeReceipt) {
        hitos.push({
            titulo: 'Recibo de ingreso emitido',
            fecha: cuota.incomeReceipt.issueDate,
            detalle: cuota.incomeReceipt.formattedNumber,
        });
    }

    if (cuota.cashTransfer) {
        hitos.push({
            titulo: 'Efectivo depositado en la cuenta',
            fecha: cuota.cashTransfer.depositDate,
            detalle: cuota.cashTransfer.operationNumber
                ? `operación ${cuota.cashTransfer.operationNumber}`
                : null,
        });

        if (cuota.cashTransfer.creditedDate) {
            hitos.push({
                titulo: 'Depósito identificado en el extracto',
                fecha: cuota.cashTransfer.creditedDate,
            });
        }
    }

    if (orden?.order) {
        hitos.push({
            titulo: 'Orden de Pago emitida',
            fecha: orden.order.orderDate,
            detalle: orden.order.formattedNumber,
        });
    }

    const pago = egreso?.disbursement;

    if (pago?.reportedAt) {
        hitos.push({
            titulo: 'El organismo informó la transferencia',
            fecha: pago.reportedAt,
            detalle: pago.transferReference,
        });
    }

    if (pago?.debitDate) {
        hitos.push({
            titulo: 'Débito reconocido en el extracto',
            fecha: pago.debitDate,
            detalle: pago.debitOperationId,
        });
    }

    if (pago?.validatedAt) {
        hitos.push({
            titulo: 'Egreso validado',
            fecha: pago.validatedAt,
            detalle: pago.validatedByName,
        });
    }

    if (pago?.paymentDate) {
        hitos.push({
            titulo: 'Entregado al beneficiario',
            fecha: pago.paymentDate,
            detalle: pago.deliveredByName,
        });
    }

    if (egreso?.receipt) {
        hitos.push({
            titulo: 'Recibo de egreso emitido',
            fecha: egreso.receipt.issueDate,
            detalle: egreso.receipt.formattedNumber,
        });
    }

    return hitos.sort((a, b) => a.fecha.localeCompare(b.fecha));
}

export { recorridoDe };
export type { Hito };
