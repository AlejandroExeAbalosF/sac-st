import { Link } from '@inertiajs/react';
import {
    Ban,
    CircleDashed,
    Eye,
    FileSignature,
    HandCoins,
    Pencil,
    Send,
} from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import type { OrdenDePagoProps } from '@/features/haberes/types';
import { date } from '@/lib/format';
import { create as nuevaOrden } from '@/routes/haberes/installments/order';
import { print as imprimirOrden, view as verOrden } from '@/routes/ordenes';
import { print as imprimirPase, view as verPase } from '@/routes/pases';
import DocumentViewerDialog from './document-viewer-dialog';
import type { DocumentoParaVer } from './document-viewer-dialog';
import EditOrderDetailsDialog from './edit-order-details-dialog';
import VoidOrderDialog from './void-order-dialog';

type Estado = App.Modules.Haberes.Data.InstallmentOrderStateData;
type Orden = App.Modules.Haberes.Data.PaymentOrderSummaryData;
type OrdenStatus = App.Modules.Haberes.Enums.PaymentOrderStatus;

const TONO: Record<OrdenStatus, StatusTone> = {
    draft: 'progress',
    reviewed: 'progress',
    approved: 'action',
    sent: 'action',
    transfer_reported: 'action',
    bank_debit_observed: 'action',
    ready_for_validation: 'action',
    completed: 'done',
    rejected: 'blocked',
    voided: 'neutral',
};

const ETIQUETA: Record<OrdenStatus, string> = {
    draft: 'Emitida',
    reviewed: 'Revisada',
    approved: 'Aprobada',
    sent: 'Remitida',
    transfer_reported: 'Transferencia informada',
    bank_debit_observed: 'Débito observado',
    ready_for_validation: 'Lista para validar',
    completed: 'Completada',
    rejected: 'Rechazada',
    voided: 'Anulada',
};

/**
 * La documentación de pago de una cuota: su Orden y su Pase.
 *
 * **Lo primero que decide es si corresponde.** Una cuota que se cobró en
 * efectivo y se va a entregar en mano no lleva Orden, y decirlo con todas
 * las letras es mejor que dejar la sección en blanco: el operador
 * necesita saber que ahí no falta nada, no quedarse con la duda de si el
 * sistema se olvidó de algo.
 *
 * Si el mismo efectivo termina depositado en la cuenta del ministerio, la
 * cuota pasa a llevar Orden sin que nadie cambie nada. Es el §2.4.7: el
 * dinero cambió de lugar y con eso cambió de régimen.
 */
export default function PaymentOrderPanel({
    cuotaId,
    estado,
    permisos,
}: {
    cuotaId: number;
    estado: Estado;
    permisos: OrdenDePagoProps['permisos'];
}) {
    const [anulando, setAnulando] = useState(false);
    const [corrigiendo, setCorrigiendo] = useState(false);
    const [viendo, setViendo] = useState(false);

    if (!estado.applies) {
        return (
            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                <HandCoins
                    className="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                Se paga por mostrador: el dinero está en la caja y se entrega en
                mano contra el recibo de egreso. Si en cambio termina depositado
                en la cuenta del ministerio, va a corresponder Orden de Pago.
            </p>
        );
    }

    const orden = estado.order;

    if (orden !== null) {
        return (
            <>
                <OrdenEmitida
                    orden={orden}
                    puedeVer={permisos.ver}
                    puedeEditar={permisos.emitir}
                    puedeAnular={permisos.anular}
                    onVer={() => setViendo(true)}
                    onEditar={() => setCorrigiendo(true)}
                    onAnular={() => setAnulando(true)}
                />

                {viendo && (
                    <DocumentViewerDialog
                        titulo={`Orden de Pago ${orden.number}`}
                        descripcion="Los dos documentos que se remitieron juntos. El importe y las partes quedaron fijados al emitirlos; las observaciones se corrigen."
                        documentos={documentosDe(orden)}
                        abierto
                        onCerrar={() => setViendo(false)}
                    />
                )}

                {corrigiendo && (
                    <EditOrderDetailsDialog
                        orden={orden}
                        abierto
                        onCerrar={() => setCorrigiendo(false)}
                    />
                )}

                {anulando && (
                    <VoidOrderDialog
                        orden={orden}
                        abierto
                        onCerrar={() => setAnulando(false)}
                    />
                )}
            </>
        );
    }

    return (
        <>
            <div className="space-y-2">
                {estado.blockedReason !== null ? (
                    <p className="flex items-start gap-2 text-xs text-muted-foreground">
                        <CircleDashed
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {estado.blockedReason}
                    </p>
                ) : (
                    <p className="flex items-start gap-2 text-xs text-muted-foreground">
                        <Send
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {estado.canIssue
                            ? 'La cuota está lista: se puede generar la Orden y su Pase para remitirlas al organismo.'
                            : 'Faltan datos que el formulario imprime. Se completan al generar la Orden.'}
                    </p>
                )}

                {permisos.emitir && estado.blockedReason === null && (
                    <Button
                        variant={estado.canIssue ? 'default' : 'outline'}
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        asChild
                    >
                        <Link href={nuevaOrden(cuotaId)}>
                            <FileSignature className="size-3.5" />
                            {estado.canIssue
                                ? 'Generar Orden y Pase'
                                : 'Completar datos y generar'}
                        </Link>
                    </Button>
                )}
            </div>
        </>
    );
}

/**
 * Las hojas emitidas, en el orden en que viajaron.
 *
 * Un solo botón las abre a las dos y el visor pone la solapa para pasar de
 * una a la otra: así es como se revisan, juntas, porque juntas se
 * remitieron. Elegir cuál mirar antes de abrir era una decisión que el
 * operador no tenía por qué tomar.
 *
 * El Pase puede faltar en órdenes viejas; entonces va sola la Orden.
 */
function documentosDe(orden: Orden): DocumentoParaVer[] {
    const documentos: DocumentoParaVer[] = [
        {
            etiqueta: 'Orden de Pago',
            urlVista: verOrden(orden.id).url,
            urlImpresion: imprimirOrden(orden.id).url,
        },
    ];

    if (orden.pase !== null) {
        documentos.push({
            etiqueta: 'Nota de Pase',
            urlVista: verPase(orden.pase.id).url,
            urlImpresion: imprimirPase(orden.pase.id).url,
        });
    }

    return documentos;
}

function OrdenEmitida({
    orden,
    puedeVer,
    puedeEditar,
    puedeAnular,
    onVer,
    onEditar,
    onAnular,
}: {
    orden: Orden;
    puedeVer: boolean;
    puedeEditar: boolean;
    puedeAnular: boolean;
    onVer: () => void;
    onEditar: () => void;
    onAnular: () => void;
}) {
    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="text-sm font-semibold">
                    Orden de Pago {orden.number}
                </span>
                <StatusBadge
                    label={ETIQUETA[orden.status]}
                    tone={TONO[orden.status]}
                />
                <Money value={orden.amount} className="text-sm" />
                <span className="text-xs text-muted-foreground">
                    {date(orden.orderDate)}
                </span>
            </div>

            <dl className="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                {orden.organismAccountLabel && (
                    <Dato etiqueta="Desde">{orden.organismAccountLabel}</Dato>
                )}
                {orden.pase !== null && (
                    <Dato etiqueta="Pase">
                        {orden.pase.destination}
                        {' · '}
                        {date(orden.pase.issueDate)}
                    </Dato>
                )}
                <Dato etiqueta="Recibo de ingreso">
                    <span className="font-mono">
                        {orden.incomeReceiptNumber}
                    </span>
                </Dato>
                {orden.cbuFolio && (
                    <Dato etiqueta="CBU informado en">
                        fs. {orden.cbuFolio}
                    </Dato>
                )}
            </dl>

            {orden.notes && (
                <p className="text-xs text-muted-foreground italic">
                    OBS: {orden.notes}
                </p>
            )}

            <div className="flex flex-wrap gap-1">
                {puedeVer && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        onClick={onVer}
                    >
                        <Eye className="size-3.5" />
                        {orden.pase === null
                            ? 'Ver la Orden'
                            : 'Ver la Orden y el Pase'}
                    </Button>
                )}

                {/*
                 * Corregir va antes que anular. Los dos son caminos
                 * válidos y el área definió que elige quien opera, así que
                 * el criterio del orden no es cuál se usa más: es que
                 * entre dos opciones legítimas, la que no destruye nada se
                 * ofrece primero.
                 */}
                {puedeEditar && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="min-h-11 text-xs sm:min-h-8"
                        onClick={onEditar}
                    >
                        <Pencil className="size-3.5" />
                        Corregir observaciones
                    </Button>
                )}

                {puedeAnular && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 text-xs text-destructive hover:text-destructive"
                        onClick={onAnular}
                    >
                        <Ban className="size-3.5" />
                        Anular
                    </Button>
                )}
            </div>
        </div>
    );
}

function Dato({
    etiqueta,
    children,
}: {
    etiqueta: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex gap-2">
            <dt className="text-field-label">{etiqueta}:</dt>
            <dd>{children}</dd>
        </div>
    );
}
