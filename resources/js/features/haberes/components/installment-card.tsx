import {
    ArrowDownRight,
    ArrowUpRight,
    CircleDashed,
    FolderOpen,
    History,
    Lock,
    Pencil,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { useDrawer } from '@/features/drawer/drawer-context';
import { salidaDe } from '@/features/haberes/installment-channel';
import { ETAPA, TONO_ETAPA } from '@/features/haberes/installment-stage';
import type { EgresoProps, OrdenDePagoProps } from '@/features/haberes/types';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import { history as historialDeCuota } from '@/routes/haberes/installments';
import InstallmentExpense from './installment-expense';
import InstallmentIncome from './installment-income';
import InstallmentTimeline from './installment-timeline';
import type { Firmante } from './issue-receipt-dialog';
import PaymentOrderPanel from './payment-order-panel';
import UnlockEditDialog from './unlock-edit-dialog';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type CuotaStatus = App.Modules.Haberes.Enums.InstallmentWorkflowStatus;

const TONO: Record<CuotaStatus, StatusTone> = {
    active: 'progress',
    suspended: 'action',
    blocked: 'blocked',
    cancelled: 'neutral',
    paid: 'done',
};

const ETIQUETA: Record<CuotaStatus, string> = {
    active: 'Pendiente',
    suspended: 'Suspendida',
    blocked: 'Bloqueada',
    cancelled: 'Anulada',
    paid: 'Pagada',
};

const MEDIO: Record<
    NonNullable<App.Modules.Haberes.Enums.ExpectedMedium>,
    string
> = {
    cash: 'Efectivo',
    bank: 'Depósito en cuenta',
    cheque: 'Cheque',
};

/**
 * Una cuota, desplegada como tarjeta.
 *
 * Es la forma que propuso el diseño del área y es la correcta acá: en la
 * pantalla del haber hay lugar, y con lugar la cuota deja de ser una fila
 * y pasa a ser lo que en realidad es —un tramo del circuito, con sus
 * hitos y lo que le falta—.
 *
 * La versión compacta sigue existiendo para el acordeón del expediente,
 * donde la cuota es un dato de contexto y no lo que se vino a mirar.
 *
 * Los cuatro tramos del circuito —ingreso, documentación, egreso y lo que
 * cada uno espera— se dibujan siempre, incluso los que a esta cuota no le
 * corresponden: la que se paga por mostrador dice que no lleva Orden, y la
 * que sale por transferencia dice que su recibo de egreso espera la
 * validación del contador. Mostrar el recorrido completo con cada tramo
 * explicado es más honesto que esconder lo que no aplica: el operador ve
 * dónde está parada la cuota, no solo lo que ya se puede hacer.
 *
 * Arriba de todo eso va «Recorrido», que es la otra mitad de la respuesta:
 * los tramos dicen **qué falta**, y el recorrido **qué ya pasó y cuándo**.
 */
export default function InstallmentCard({
    cuota,
    total,
    editable,
    atenuada,
    cuentas,
    puedeEditarTicket,
    puedeEmitirRecibo,
    puedeRegistrarPago,
    puedeTrasladar,
    puedeAcreditar,
    puedeDesasignar,
    puedeAnular,
    firmantes,
    orden,
    egreso,
    onEditar,
}: {
    cuota: Cuota;
    total: number;
    editable: boolean;
    atenuada: boolean;
    cuentas: { id: number; label: string }[];
    puedeEditarTicket: boolean;
    puedeEmitirRecibo: boolean;
    puedeRegistrarPago: boolean;
    puedeTrasladar: boolean;
    puedeAcreditar: boolean;
    puedeDesasignar: boolean;
    puedeAnular: boolean;
    firmantes: Firmante[];
    orden: OrdenDePagoProps;
    egreso: EgresoProps;
    onEditar: () => void;
}) {
    const { openDrawer } = useDrawer();
    const [destrabar, setDestrabar] = useState(false);
    const anulada = cuota.status === 'cancelled';
    const estadoOrden = orden.estados[cuota.id];
    const estadoEgreso = egreso.estados[cuota.id];

    /*
     * Con la Orden y el Pase emitidos el expediente salió del área. La
     * cuota se sigue pudiendo corregir —el área fue explícita— pero antes
     * hay que registrar el caso, así que el botón cambia de destino en vez
     * de desaparecer: esconderlo dejaría al operador sin saber qué hacer.
     */
    const trabada = estadoOrden?.editLocked ?? false;

    /*
     * El medio real, solo cuando difiere del previsto.
     *
     * `effectiveMedium` cae en el previsto mientras no haya plata, así que
     * una diferencia significa que ya entró dinero y entró por otro lado
     * —el caso del §2.4.6, que separa cómo entró de cómo sale—.
     */
    const medioReal =
        cuota.effectiveMedium !== null &&
        cuota.effectiveMedium !== cuota.expectedMedium
            ? (MEDIO[cuota.effectiveMedium as keyof typeof MEDIO] ?? null)
            : null;

    return (
        <li
            className={cn(
                'overflow-hidden rounded-lg border bg-card shadow-xs transition-opacity duration-200',
                atenuada && 'opacity-40',
                anulada && 'border-dashed bg-muted/20',
            )}
        >
            {/* Encabezado: quién es, cómo está, cuándo se cargó y en qué
                punto del circuito quedó. El importe ya no va acá: lo dice la
                fila del concepto, dos renglones más abajo. */}
            <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-b bg-muted/30 px-4 py-3 sm:px-5">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-semibold">
                        Cuota {cuota.number} de {total}
                    </span>
                    <StatusBadge
                        label={ETIQUETA[cuota.status]}
                        tone={TONO[cuota.status]}
                    />
                </div>

                <div className="flex flex-wrap items-end gap-x-6 gap-y-2">
                    {/*
                     * El medio previsto va rotulado y arriba, no suelto
                     * entre las etiquetas: es lo que anticipa por dónde va
                     * a moverse el dinero de esta cuota, y quien la mira lo
                     * busca antes que cualquier otra cosa.
                     *
                     * Ya no hay caso «sin definir»: desde 09/2026 la columna
                     * es NOT NULL y el alta lo exige.
                     */}
                    <div className="text-right">
                        <p className="text-[0.65rem] tracking-wide text-field-label uppercase">
                            Medio previsto
                        </p>
                        <p className="text-sm">
                            {MEDIO[cuota.expectedMedium]}
                            {/*
                             * Y por dónde sale hoy, si ya no sale por donde
                             * entró. Pegado al medio y no en un renglón
                             * aparte: es la misma pregunta —«qué va a pasar
                             * con esta plata»— leída de un vistazo.
                             */}
                            {salidaDe(cuota) !== null && (
                                <span className="ml-1 text-warning-strong">
                                    {salidaDe(cuota)}
                                </span>
                            )}
                        </p>
                        {/*
                         * Y cuando el dinero entró por otro lado, se dice
                         * también: el previsto se edita libremente y puede
                         * quedar contradiciendo a un hecho ya asentado.
                         * Mostrar solo la expectativa haría que la tarjeta
                         * afirme algo que el libro desmiente.
                         */}
                        {medioReal !== null && (
                            <p className="text-[0.7rem] text-warning-strong">
                                Entró por {medioReal}
                            </p>
                        )}
                    </div>
                    <div className="text-right">
                        <p className="text-[0.65rem] tracking-wide text-field-label uppercase">
                            Fecha de carga
                        </p>
                        <p className="text-sm">{date(cuota.createdAt)}</p>
                    </div>
                    {/*
                     * Acá decía «Monto total», que repetía el importe que
                     * ya está a la derecha del concepto, dos renglones más
                     * abajo. La etapa, en cambio, no estaba en ninguna
                     * parte: había que leer el bloque del traslado y el del
                     * egreso para reconstruirla.
                     */}
                    {cuota.stage && (
                        <div className="text-right">
                            <p className="text-[0.65rem] tracking-wide text-field-label uppercase">
                                Etapa
                            </p>
                            <StatusBadge
                                className="mt-0.5"
                                label={ETAPA[cuota.stage]}
                                tone={TONO_ETAPA[cuota.stage]}
                            />
                        </div>
                    )}
                </div>
            </div>

            <Seccion icono={History} titulo="Recorrido">
                <InstallmentTimeline
                    cuota={cuota}
                    orden={orden.estados[cuota.id]}
                    egreso={egreso.estados[cuota.id]}
                />
            </Seccion>

            <Seccion icono={Receipt} titulo="Conceptos">
                {/*
                 * Un concepto por cuota, y no por ahora: el §3 del DER v4.3
                 * define la cuota con «importe, concepto, etiqueta y medio
                 * propios» —uno de cada— y como la unidad financiable,
                 * ordenable y **pagable**. Varios importes en una cuota
                 * serían un pago parcial, que en Haberes no ocurre.
                 *
                 * Acá decía lo contrario, y sobre eso se justificaba un
                 * «Monto total» arriba que repetía este mismo importe. El
                 * área lo desmintió y el campo se fue.
                 */}
                <div className="flex items-baseline justify-between gap-4">
                    <span>
                        {cuota.concept ?? (
                            <span className="text-muted-foreground italic">
                                Sin concepto
                            </span>
                        )}
                    </span>
                    <Money value={cuota.expectedAmount} />
                </div>

                {cuota.managementLabel && (
                    <div className="mt-2 flex flex-wrap items-center gap-2">
                        <span
                            className={cn(
                                'rounded px-1.5 py-0.5 font-mono text-[0.65rem] tracking-tight',
                                cuota.blocksPayment
                                    ? 'bg-warning-soft text-warning-strong'
                                    : 'bg-muted text-muted-foreground',
                            )}
                            title={
                                cuota.blocksPayment
                                    ? 'Esta etiqueta retiene la entrega al beneficiario'
                                    : undefined
                            }
                        >
                            {cuota.blocksPayment && (
                                <Lock
                                    className="mr-1 inline size-2.5"
                                    aria-hidden="true"
                                />
                            )}
                            {cuota.managementLabel}
                        </span>
                    </div>
                )}

                {cuota.notes && (
                    <p className="mt-2 text-xs text-muted-foreground italic">
                        {cuota.notes}
                    </p>
                )}
            </Seccion>

            <Seccion icono={ArrowDownRight} titulo="Ingreso">
                <InstallmentIncome
                    cuota={cuota}
                    cuentas={cuentas}
                    puedeEditarTicket={puedeEditarTicket}
                    puedeEmitirRecibo={puedeEmitirRecibo}
                    puedeRegistrarPago={puedeRegistrarPago}
                    puedeTrasladar={puedeTrasladar}
                    puedeAcreditar={puedeAcreditar}
                    puedeDesasignar={puedeDesasignar}
                    puedeAnular={puedeAnular}
                    firmantes={firmantes}
                />
            </Seccion>

            <Seccion icono={FolderOpen} titulo="Documentación de pago">
                {estadoOrden === undefined ? (
                    <Pendiente>
                        Sin información de la Orden para esta cuota.
                    </Pendiente>
                ) : (
                    <PaymentOrderPanel
                        cuotaId={cuota.id}
                        estado={estadoOrden}
                        permisos={{
                            ...orden.permisos,
                            // Una cuota anulada no empieza nada nuevo. Lo
                            // ya emitido se sigue viendo: un documento que
                            // el organismo tiene no deja de existir porque
                            // acá alguien anule la cuota.
                            emitir: orden.permisos.emitir && !anulada,
                        }}
                    />
                )}
            </Seccion>

            <Seccion icono={ArrowUpRight} titulo="Egreso" ultima>
                {estadoEgreso === undefined ? (
                    <Pendiente>
                        Sin información del egreso para esta cuota.
                    </Pendiente>
                ) : (
                    <InstallmentExpense
                        cuota={cuota}
                        estado={estadoEgreso}
                        // Una cuota anulada no empieza nada nuevo. Lo ya
                        // pagado se sigue viendo: un recibo que el
                        // beneficiario firmó no deja de existir porque acá
                        // alguien anule la cuota.
                        puedePagar={egreso.permisos.registrar && !anulada}
                        puedeValidar={egreso.permisos.validar && !anulada}
                    />
                )}
            </Seccion>

            <div className="flex items-center justify-end gap-1 border-t bg-muted/20 px-4 py-2.5 sm:px-5">
                {/*
                 * El historial va al lado de editar y no escondido: la
                 * pregunta «¿esta cuota decía otra cosa?» aparece justo
                 * cuando alguien está mirando la cuota, no en una pantalla
                 * de auditoría a la que hay que acordarse de ir.
                 *
                 * Y abre al costado y no en un diálogo, que tapaba la
                 * cuota justo cuando había que compararla con su pasado.
                 */}
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-8 text-xs"
                    onClick={() =>
                        openDrawer({
                            kind: 'history',
                            subject: 'installment',
                            url: historialDeCuota(cuota.id).url,
                            label: `de la cuota ${cuota.number}`,
                        })
                    }
                    aria-label={`Ver el historial de la cuota ${cuota.number}`}
                >
                    <History className="size-3.5" aria-hidden="true" />
                    Historial
                </Button>

                {editable && !anulada && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() =>
                            trabada ? setDestrabar(true) : onEditar()
                        }
                        aria-label={`Editar la cuota ${cuota.number}`}
                        title={
                            trabada
                                ? 'La cuota está en circulación: corregirla exige registrar el caso'
                                : undefined
                        }
                    >
                        {trabada ? (
                            <Lock className="size-3.5" aria-hidden="true" />
                        ) : (
                            <Pencil className="size-3.5" aria-hidden="true" />
                        )}
                        {trabada ? 'Habilitar edición' : 'Editar cuota'}
                    </Button>
                )}
            </div>

            {destrabar && estadoOrden?.order != null && (
                <UnlockEditDialog
                    cuotaId={cuota.id}
                    numero={cuota.number}
                    ordenNumero={estadoOrden.order.number}
                    abierto
                    onCerrar={() => setDestrabar(false)}
                />
            )}
        </li>
    );
}

/** Un tramo de la tarjeta, con su rótulo y su separador. */
function Seccion({
    icono: Icono,
    titulo,
    ultima = false,
    children,
}: {
    icono: typeof Receipt;
    titulo: string;
    ultima?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('px-4 py-3.5 sm:px-5', !ultima && 'border-b')}>
            <h4 className="mb-2 flex items-center gap-1.5 text-[0.65rem] font-semibold tracking-wide text-field-label uppercase">
                <Icono className="size-3.5" aria-hidden="true" />
                {titulo}
            </h4>
            <div className="text-sm">{children}</div>
        </div>
    );
}

/**
 * Una etapa que todavía no tiene pantalla.
 *
 * Se dibuja igual que las demás, apagada, con el mismo criterio que la
 * barra lateral usa para los módulos que faltan: el circuito se ve
 * completo y queda claro qué parte ya se puede recorrer.
 */
function Pendiente({ children }: { children: React.ReactNode }) {
    return (
        <p className="flex items-start gap-2 text-xs text-muted-foreground">
            <CircleDashed
                className="mt-0.5 size-3.5 shrink-0"
                aria-hidden="true"
            />
            {children}
        </p>
    );
}
