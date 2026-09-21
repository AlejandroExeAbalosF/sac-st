import { Head, Link } from '@inertiajs/react';
import { Ban, History, Lock, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import CollapsibleSection from '@/components/collapsible-section';
import DataField, { EmptyValue } from '@/components/data-field';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useDrawer } from '@/features/drawer/drawer-context';
import HaberCancelDialog from '@/features/haberes/components/haber-cancel-dialog';
import InstallmentCards from '@/features/haberes/components/installment-cards';
import type { Firmante } from '@/features/haberes/components/issue-receipt-dialog';
import type {
    EgresoProps,
    EtiquetaOption,
    OrdenDePagoProps,
} from '@/features/haberes/types';
import {
    compareAmounts,
    date,
    dateTime,
    isNonZero,
    sumAmounts,
} from '@/lib/format';
import { show } from '@/routes/expedientes';
import { history as historialDeHaber } from '@/routes/haberes/haber';

type Haber = App.Modules.Haberes.Data.HaberListItemData;
type HaberStatus = App.Modules.Haberes.Enums.HaberWorkflowStatus;

type Props = {
    haber: Haber;
    /** Lo del acta que la ficha guarda plegado. */
    extras: App.Modules.Haberes.Data.HaberExtraData;
    expediente: {
        id: number;
        displayNumber: string;
        canonicalNumber: string | null;
        subject: string | null;
        employerName: string | null;
        declaredTotalAmount: string | null;
        status: App.Modules.Haberes.Enums.ExpedienteStatus;
    };
    beneficiario: { name: string; document: string | null };
    etiquetas: EtiquetaOption[];
    /** Cuentas activas, para el modal del comprobante. */
    cuentas: { id: number; label: string }[];
    canEdit: boolean;
    canCancel: boolean;
    canManageTickets: boolean;
    canIssueReceipt: boolean;
    canRegisterPayment: boolean;
    canTransferCash: boolean;
    canConfirmTransfer: boolean;
    canUnallocate: boolean;
    canVoidReceipt: boolean;
    firmantes: Firmante[];
    /** El estado de la Orden de Pago de cada cuota, indexado por su id. */
    ordenes: Record<number, App.Modules.Haberes.Data.InstallmentOrderStateData>;
    canViewOrder: boolean;
    canIssueOrder: boolean;
    canVoidOrder: boolean;
    canVerifyCbu: boolean;
    /** El estado del egreso de cada cuota, indexado por su id. */
    egresos: Record<
        number,
        App.Modules.Haberes.Data.InstallmentDisbursementData
    >;
    canPayBeneficiary: boolean;
    canValidateDisbursement: boolean;
};

const TONO: Record<HaberStatus, StatusTone> = {
    active: 'progress',
    suspended: 'action',
    blocked: 'blocked',
    cancelled: 'neutral',
    closed: 'done',
};

const ETIQUETA: Record<HaberStatus, string> = {
    active: 'Activo',
    suspended: 'Suspendido',
    blocked: 'Bloqueado',
    cancelled: 'Anulado',
    closed: 'Cerrado',
};

/**
 * Detalle de un haber, con su plan de cuotas.
 *
 * El haber vivía dentro del expediente como una fila desplegable, y ahí
 * entraba mientras la cuota fuera un importe. Ahora la cuota tiene
 * concepto, etiqueta, medio previsto, observaciones y estado propio, y el
 * plan puede llegar a sesenta: eso no cabe en un acordeón dentro de una
 * lista de cinco beneficiarios.
 *
 * El expediente queda arriba porque el haber no se entiende suelto: el
 * número, el empleador y el total declarado son el marco contra el que se
 * lee lo que este beneficiario tiene reconocido.
 */
export default function MostrarHaber({
    haber,
    extras,
    expediente,
    beneficiario,
    etiquetas,
    cuentas,
    canEdit,
    canCancel,
    canManageTickets,
    canIssueReceipt,
    canRegisterPayment,
    canTransferCash,
    canConfirmTransfer,
    canUnallocate,
    canVoidReceipt,
    firmantes,
    ordenes,
    canViewOrder,
    canIssueOrder,
    canVoidOrder,
    canVerifyCbu,
    egresos,
    canPayBeneficiary,
    canValidateDisbursement,
}: Props) {
    const { openDrawer } = useDrawer();
    const [baja, setBaja] = useState(false);

    /*
     * Todo lo de la documentación de pago va en un bulto: atraviesa la
     * lista y la tarjeta sin que ninguna de las dos lo mire, y seis props
     * sueltas de pasamanos se desordenan en la primera que se agrega.
     */
    const orden: OrdenDePagoProps = {
        estados: ordenes,
        permisos: {
            ver: canViewOrder,
            emitir: canIssueOrder,
            anular: canVoidOrder,
            verificarCbu: canVerifyCbu,
        },
    };

    /* Lo del egreso viaja igual que lo de la Orden, y por lo mismo. */
    const egreso: EgresoProps = {
        estados: egresos,
        permisos: {
            registrar: canPayBeneficiary,
            validar: canValidateDisbursement,
        },
    };

    const anulado = haber.status === 'cancelled';
    const cargadas = haber.installments.length;
    const suma = sumAmounts(
        haber.installments.map((cuota) => cuota.expectedAmount),
    );
    const falta = sumAmounts([haber.assignedAmount, `-${suma}`]);
    const cierra = compareAmounts(suma, haber.assignedAmount) === 0;

    return (
        <>
            <Head
                title={`${beneficiario.name} · Expediente ${expediente.displayNumber}`}
            />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {/*
                 * El título es el beneficiario porque es lo que distingue a
                 * este haber de los otros del mismo expediente. Pero la
                 * pantalla es el haber, no la persona: la misma persona
                 * puede tener haberes en varios expedientes, y este es uno.
                 */}
                {/*
                 * Anular el haber se hacía solo desde la tarjeta del
                 * expediente, que muestra cuatro datos. Acá está el recibo,
                 * la Orden y el egreso: es la pantalla donde se ve qué se
                 * está dando de baja.
                 */}
                <PageHeader
                    title={beneficiario.name}
                    description={
                        beneficiario.document
                            ? `DNI ${beneficiario.document}`
                            : undefined
                    }
                    actions={
                        <>
                            {/*
                             * Suele estar vacío, y eso es la respuesta: un
                             * haber se reconoce, se anula y se reactiva,
                             * pero no se corrige. Que no haya nada dice que
                             * nadie tocó el derecho reconocido.
                             */}
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    openDrawer({
                                        kind: 'history',
                                        subject: 'haber',
                                        url: historialDeHaber([
                                            expediente.id,
                                            haber.haberNumber,
                                        ]).url,
                                        label: `del haber de ${beneficiario.name}`,
                                    })
                                }
                            >
                                <History
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Historial
                            </Button>
                            {canCancel && (
                                <Button
                                    variant="outline"
                                    onClick={() => setBaja(true)}
                                >
                                    {anulado ? (
                                        <RotateCcw
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <Ban
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {anulado ? 'Reactivar' : 'Anular'}
                                </Button>
                            )}
                        </>
                    }
                />

                {anulado && (
                    <p className="flex items-center gap-2 rounded-lg border border-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-strong">
                        <Lock className="size-4 shrink-0" aria-hidden="true" />
                        Este haber está anulado: no cuenta en lo reconocido del
                        expediente y sus cuotas no se pueden editar.
                    </p>
                )}

                {haber.blockReason && (
                    <p className="flex items-start gap-2 rounded-lg border border-warning bg-warning-soft px-3 py-2 text-sm text-warning-strong">
                        <Lock
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {haber.blockReason}
                    </p>
                )}

                {/*
                 * Arriba lo que se mira todos los días; abajo, plegado, lo
                 * que el acta dejó y casi nunca se consulta. Es el mismo
                 * corte que la ficha del expediente.
                 */}
                <section className="rounded-lg border bg-card shadow-raised">
                    <dl className="grid gap-6 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <DataField label="Reconocido">
                            <Money value={haber.assignedAmount} />
                        </DataField>
                        <DataField label="Concepto">
                            {haber.concept ?? (
                                <EmptyValue>Sin concepto informado</EmptyValue>
                            )}
                        </DataField>
                        <DataField label="Cuotas">
                            {cargadas} de {haber.installmentCount} cargadas
                            {haber.paidInstallmentCount > 0 &&
                                ` · ${haber.paidInstallmentCount} pagadas`}
                        </DataField>
                        <DataField label="Estado">
                            <StatusBadge
                                label={ETIQUETA[haber.status]}
                                tone={TONO[haber.status]}
                            />
                        </DataField>
                        {/*
                         * El haber se carga meses después que el expediente, y
                         * muchas veces lo carga otra persona: el autor del
                         * expediente no contesta por este renglón.
                         */}
                        <DataField label="Cargado">
                            {haber.createdAt ? (
                                <>
                                    {dateTime(haber.createdAt)}
                                    <span className="block text-xs text-muted-foreground">
                                        {haber.createdByName ?? (
                                            <EmptyValue>
                                                Sin autor registrado
                                            </EmptyValue>
                                        )}
                                    </span>
                                </>
                            ) : (
                                <EmptyValue />
                            )}
                            {/*
                             * Sin cambios no se dibuja nada: repetir acá la
                             * fecha de carga diría que alguien tocó el haber
                             * el día que se creó.
                             */}
                            {haber.lastChange && (
                                <span className="mt-1 block text-xs text-muted-foreground">
                                    Modificado {dateTime(haber.lastChange.at)}
                                    {haber.lastChange.by &&
                                        ` por ${haber.lastChange.by}`}
                                </span>
                            )}
                        </DataField>
                    </dl>

                    <CollapsibleSection
                        label="Más datos del haber"
                        className="border-t px-5 py-3"
                    >
                        <dl className="grid gap-6 pb-2 sm:grid-cols-2 lg:grid-cols-3">
                            <DataField label="Fecha del acta">
                                {extras.legalDate ? (
                                    date(extras.legalDate)
                                ) : (
                                    <EmptyValue />
                                )}
                            </DataField>
                            <DataField label="Resolución">
                                {extras.resolutionReference ?? <EmptyValue />}
                            </DataField>
                            <DataField label="Forma de pago">
                                {extras.paymentTerms === 'single'
                                    ? 'De una vez'
                                    : 'En cuotas'}
                            </DataField>
                            {/*
                             * Las observaciones del haber no se veían en
                             * ninguna pantalla: se cargaban en el alta y
                             * desaparecían.
                             */}
                            <DataField
                                label="Observaciones"
                                className="sm:col-span-2 lg:col-span-3"
                            >
                                {haber.notes ? (
                                    <span className="block max-w-4xl whitespace-pre-wrap text-muted-foreground">
                                        {haber.notes}
                                    </span>
                                ) : (
                                    <EmptyValue />
                                )}
                            </DataField>
                        </dl>
                    </CollapsibleSection>
                </section>

                {/*
                 * El marco: de qué expediente cuelga este haber. No es
                 * decoración —el número es lo que el operador tiene anotado
                 * y el empleador es quien deposita—, pero va apagado porque
                 * lo que se vino a mirar son las cuotas.
                 */}
                <div className="rounded-lg border bg-muted/30 px-5 py-4 text-sm">
                    <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-3">
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Expediente
                            </dt>
                            <dd className="mt-0.5 font-mono">
                                <Link
                                    href={show(expediente.id)}
                                    className="underline underline-offset-2 hover:text-primary"
                                >
                                    {expediente.canonicalNumber ??
                                        expediente.displayNumber}
                                </Link>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Empleador
                            </dt>
                            <dd className="mt-0.5">
                                {expediente.employerName ?? (
                                    <span className="text-muted-foreground italic">
                                        Sin identificar
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Carátula
                            </dt>
                            <dd className="mt-0.5 text-muted-foreground">
                                {expediente.subject ?? 'Sin carátula'}
                            </dd>
                        </div>
                    </dl>
                </div>

                <section aria-labelledby="cuotas">
                    <div className="mb-2 flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <h2
                            id="cuotas"
                            className="text-xs font-semibold tracking-wide text-primary uppercase"
                        >
                            Plan de cuotas
                        </h2>

                        {/*
                         * El control que importa: la suma de las cuotas
                         * cargadas contra el derecho reconocido. Mientras
                         * falten cuotas puede ser menor; cuando estén todas,
                         * tiene que dar exacto.
                         */}
                        <span className="text-xs text-muted-foreground">
                            {cierra ? (
                                <span className="text-success-strong">
                                    Las cuotas suman el total reconocido
                                </span>
                            ) : (
                                <>
                                    Cargadas <Money value={suma} /> de{' '}
                                    <Money value={haber.assignedAmount} />
                                    {isNonZero(falta) && (
                                        <>
                                            {' · faltan '}
                                            <Money
                                                value={falta.replace('-', '')}
                                            />
                                        </>
                                    )}
                                </>
                            )}
                        </span>
                    </div>

                    <InstallmentCards
                        expedienteId={expediente.id}
                        haberNumber={haber.haberNumber}
                        cuentas={cuentas}
                        puedeEditarTicket={canManageTickets}
                        puedeEmitirRecibo={canIssueReceipt}
                        puedeRegistrarPago={canRegisterPayment}
                        puedeTrasladar={canTransferCash}
                        puedeAcreditar={canConfirmTransfer}
                        puedeDesasignar={canUnallocate}
                        puedeAnular={canVoidReceipt}
                        firmantes={firmantes}
                        orden={orden}
                        egreso={egreso}
                        cuotas={haber.installments}
                        previstas={haber.installmentCount}
                        totalReconocido={haber.assignedAmount}
                        etiquetas={etiquetas}
                        editable={canEdit && !anulado}
                    />
                </section>
            </div>

            {baja && (
                <HaberCancelDialog
                    haber={haber}
                    reactivar={anulado}
                    onClose={() => setBaja(false)}
                />
            )}
        </>
    );
}
