import { Head, Link, useForm } from '@inertiajs/react';
import {
    Ban,
    History,
    Pencil,
    Plus,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import CollapsibleSection from '@/components/collapsible-section';
import DataField, { EmptyValue } from '@/components/data-field';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useDrawer } from '@/features/drawer/drawer-context';
import HaberList from '@/features/haberes/components/haber-list';
import type { EtiquetaOption } from '@/features/haberes/types';
import {
    compareAmounts,
    date,
    dateTime,
    isNonZero,
    sumAmounts,
} from '@/lib/format';
import {
    cancel,
    edit,
    history as historialDeExpediente,
    reactivate,
} from '@/routes/expedientes';
import { create as haberCreate } from '@/routes/haberes/haber';

type Props = {
    expediente: App.Modules.Haberes.Data.ExpedienteListItemData;
    /** Procedencia y control: lo que vive dentro del plegable. */
    extras: App.Modules.Haberes.Data.ExpedienteExtraData;
    etiquetas: EtiquetaOption[];
    canEdit: boolean;
    canCancel: boolean;
};

/**
 * Detalle del expediente.
 *
 * Es la pantalla donde el expediente se completa a lo largo del tiempo:
 * llega, se le carga el primer haber, y meses después vuelve con el ticket
 * de la cuota siguiente. Por eso el alta termina acá en vez de pedir todo
 * de una: el camino incremental hay que construirlo igual.
 */
export default function MostrarExpediente({
    expediente,
    extras,
    etiquetas,
    canEdit,
    canCancel,
}: Props) {
    const { openDrawer } = useDrawer();
    const [anulando, setAnulando] = useState(false);
    const [reactivando, setReactivando] = useState(false);
    const anulado = expediente.status === 'cancelled';

    const sinHaberes = expediente.haberes.length === 0;

    // El total declarado es opcional, así que solo se compara cuando lo
    // hay. Avisar contra un cero que nadie cargó es ruido.
    /*
     * La comparación contra el total declarado.
     *
     * El aviso mostraba solo el declarado, así que decía «no coinciden» sin
     * dar con qué comparar: había que ir a buscar el otro número y restarlo
     * a mano para saber si el que estaba mal era el acta o la carga.
     */
    const contraDeclarado = isNonZero(expediente.declaredTotalAmount)
        ? compareAmounts(
              expediente.recognizedTotalAmount,
              expediente.declaredTotalAmount,
          )
        : 0;

    const diferencia = sumAmounts([
        expediente.recognizedTotalAmount,
        `-${expediente.declaredTotalAmount.replace('-', '')}`,
    ]).replace('-', '');

    return (
        <>
            <Head title={`Expediente ${expediente.displayNumber}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                {/*
                 * El número es el título y la carátula la bajada, no al
                 * revés. Es el número lo que el operador trae anotado, lo
                 * que busca y lo que figura en el recibo; la carátula
                 * ayuda a reconocerlo y puede no estar.
                 */}
                <PageHeader
                    title={`Expediente ${expediente.displayNumber}`}
                    eyebrow={
                        <span className="font-mono">
                            {expediente.canonicalNumber}
                        </span>
                    }
                    description={
                        expediente.subject ?? (
                            <span className="italic">Sin carátula</span>
                        )
                    }
                    actions={
                        <>
                            {/*
                             * El historial va con las acciones y no
                             * escondido: «¿esto decía otra cosa?» aparece
                             * mirando el expediente, no en una pantalla de
                             * auditoría a la que hay que acordarse de ir.
                             */}
                            <Button
                                variant="ghost"
                                onClick={() =>
                                    openDrawer({
                                        kind: 'history',
                                        subject: 'expediente',
                                        url: historialDeExpediente(
                                            expediente.id,
                                        ).url,
                                        label: `del expediente ${expediente.displayNumber}`,
                                    })
                                }
                            >
                                <History
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Historial
                            </Button>
                            {canEdit && !anulado && (
                                <Button variant="outline" asChild>
                                    <Link href={edit(expediente.id)}>
                                        <Pencil
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Editar ficha
                                    </Link>
                                </Button>
                            )}
                            {canCancel &&
                                (anulado ? (
                                    <Button
                                        variant="outline"
                                        onClick={() => setReactivando(true)}
                                    >
                                        <RotateCcw
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Reactivar
                                    </Button>
                                ) : (
                                    <Button
                                        variant="outline"
                                        onClick={() => setAnulando(true)}
                                    >
                                        <Ban
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Anular
                                    </Button>
                                ))}
                        </>
                    }
                />

                {anulado && (
                    <p className="flex items-center gap-2 rounded-lg border border-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-strong">
                        <Ban className="size-4 shrink-0" aria-hidden="true" />
                        Este expediente está anulado. Se conserva para dejar
                        registro de que estuvo cargado; el motivo queda en la
                        auditoría.
                        {canCancel && ' Si fue un error, se puede reactivar.'}
                    </p>
                )}

                {/*
                 * Ficha del expediente.
                 *
                 * Arriba lo que se mira todos los días; abajo, plegado, lo
                 * que el expediente igual guarda. Es el mismo corte que hace
                 * el alta con «Más datos del expediente», y a propósito: lo
                 * que se cargó ahí se vuelve a encontrar acá con el mismo
                 * nombre.
                 */}
                <section className="rounded-lg border bg-card shadow-raised">
                    <dl className="grid gap-6 p-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                        <DataField label="Empleador">
                            {expediente.employerName}
                            {expediente.employerRepresentative && (
                                <span className="block text-xs text-muted-foreground">
                                    {expediente.employerRepresentative}
                                </span>
                            )}
                        </DataField>
                        <DataField label="Recibido">
                            {date(expediente.receivedDate)}
                        </DataField>
                        <DataField label="Reconocido">
                            <Money value={expediente.recognizedTotalAmount} />
                        </DataField>
                        <DataField label="Estado">
                            <StatusBadge
                                label={
                                    expediente.status === 'closed'
                                        ? 'Cerrado'
                                        : 'Activo'
                                }
                                tone={
                                    expediente.status === 'closed'
                                        ? 'done'
                                        : 'progress'
                                }
                            />
                        </DataField>
                        {/*
                         * Quién lo cargó y cuándo estaba solo en la
                         * auditoría, que el operador no abre. Va con lo
                         * demás y no plegado: cuando un dato del expediente
                         * no cierra, la primera pregunta es a quién
                         * preguntarle.
                         */}
                        <DataField label="Cargado">
                            {extras.createdAt ? (
                                <>
                                    {dateTime(extras.createdAt)}
                                    <span className="block text-xs text-muted-foreground">
                                        {extras.createdByName ?? (
                                            <EmptyValue>
                                                Sin autor registrado
                                            </EmptyValue>
                                        )}
                                    </span>
                                </>
                            ) : (
                                <EmptyValue />
                            )}
                        </DataField>
                        {/*
                         * Las observaciones se quedan afuera del plegable
                         * aunque sean opcionales: es lo que un operador le
                         * dejó escrito al que sigue, y detrás de un clic no
                         * se lee.
                         */}
                        {expediente.notes && (
                            <DataField
                                label="Observaciones"
                                className="sm:col-span-2 lg:col-span-3 xl:col-span-5"
                            >
                                <span className="block max-w-4xl whitespace-pre-wrap text-muted-foreground">
                                    {expediente.notes}
                                </span>
                            </DataField>
                        )}
                    </dl>

                    {/*
                     * Los campos vacíos se muestran igual, diciendo que
                     * están vacíos. En una ficha de papel el renglón en
                     * blanco también se ve, y es lo que le avisa al operador
                     * que ese dato falta cargarlo.
                     */}
                    <CollapsibleSection
                        label="Más datos del expediente"
                        className="border-t px-5 py-3"
                    >
                        <dl className="grid gap-6 pb-2 sm:grid-cols-2 lg:grid-cols-4">
                            <DataField label="Total declarado">
                                {extras.declaredTotalAmount ? (
                                    <Money value={extras.declaredTotalAmount} />
                                ) : (
                                    <EmptyValue>El acta no lo traía</EmptyValue>
                                )}
                            </DataField>
                            <DataField label="Código de SiCE">
                                {extras.externalId ? (
                                    <span className="font-mono text-xs">
                                        {extras.externalId}
                                    </span>
                                ) : (
                                    <EmptyValue />
                                )}
                            </DataField>
                            <DataField label="Referencia externa">
                                {extras.externalReference ?? <EmptyValue />}
                            </DataField>
                        </dl>
                    </CollapsibleSection>
                </section>

                {/*
                 * Aviso, no error: el DER define el total declarado como un
                 * dato «para control», no como una obligación.
                 */}
                {!sinHaberes && contraDeclarado !== 0 && (
                    <p className="flex flex-wrap items-center gap-x-1.5 gap-y-1 rounded-lg border border-warning bg-warning-soft px-3 py-2 text-xs text-warning-strong">
                        <TriangleAlert
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Lo reconocido
                        <Money value={expediente.recognizedTotalAmount} />
                        {contraDeclarado === 1 ? 'supera' : 'no alcanza'} al
                        total declarado
                        <Money value={expediente.declaredTotalAmount} />
                        por
                        <Money value={diferencia} />. Revisá el acta, o corregí
                        el total declarado si el equivocado es ese.
                    </p>
                )}

                {/* Haberes */}
                {sinHaberes ? (
                    <div className="rounded-lg border border-dashed bg-muted/30 px-5 py-12 text-center">
                        <p className="text-sm font-medium">
                            Este expediente todavía no reconoce ningún haber.
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Agregá el haber de cada beneficiario con las cuotas
                            que ya tengan importe conocido.
                        </p>
                        {!anulado && (
                            <Button className="mt-4" asChild>
                                <Link href={haberCreate(expediente.id)}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Agregar el primer haber
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : (
                    <div>
                        <HaberList
                            haberes={expediente.haberes}
                            acciones={
                                !anulado && (
                                    <Button size="sm" asChild>
                                        <Link href={haberCreate(expediente.id)}>
                                            <Plus
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                            Agregar haber
                                        </Link>
                                    </Button>
                                )
                            }
                            mostrarCuotas
                            etiquetas={etiquetas}
                            canEdit={canEdit}
                            canCancel={canCancel}
                        />
                    </div>
                )}
            </div>

            {anulando && (
                <DialogoDeBaja
                    expediente={expediente}
                    onClose={() => setAnulando(false)}
                />
            )}

            {reactivando && (
                <DialogoDeBaja
                    reactivar
                    expediente={expediente}
                    onClose={() => setReactivando(false)}
                />
            )}
        </>
    );
}

/**
 * Anulación y su reverso.
 *
 * Un solo diálogo para las dos direcciones: piden lo mismo —un motivo que
 * explique qué pasó— y difieren en el texto y en la ruta. El motivo es
 * obligatorio en ambas porque un expediente que figura anulado y después
 * activo necesita explicar las dos cosas, no solo la primera.
 */
function DialogoDeBaja({
    expediente,
    onClose,
    reactivar: esReactivacion = false,
}: {
    expediente: App.Modules.Haberes.Data.ExpedienteListItemData;
    onClose: () => void;
    reactivar?: boolean;
}) {
    const { data, setData, patch, processing, errors } = useForm({
        reason: '',
    });

    const cuantos = expediente.haberes.length;

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        patch(
            esReactivacion
                ? reactivate(expediente.id).url
                : cancel(expediente.id).url,
            { preserveScroll: true },
        );
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {esReactivacion ? 'Reactivar' : 'Anular'} el expediente{' '}
                        {expediente.displayNumber}
                    </DialogTitle>
                    <DialogDescription>
                        {esReactivacion
                            ? 'Cada haber y cada cuota vuelven al estado que tenían antes de anularse, no a activo.'
                            : cuantos === 0
                              ? 'No se borra: queda registrado como anulado.'
                              : `No se borra: queda registrado como anulado, y ${
                                    cuantos === 1
                                        ? 'su haber pasa'
                                        : `sus ${cuantos} haberes pasan`
                                } al mismo estado junto con sus cuotas.`}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <div>
                        <Label htmlFor="reason">Motivo</Label>
                        <Textarea
                            id="reason"
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            className="mt-1"
                            rows={3}
                            maxLength={500}
                            autoFocus
                            aria-invalid={Boolean(errors.reason)}
                            placeholder={
                                esReactivacion
                                    ? 'Por qué vuelve a estar vigente. Por ejemplo: se anuló el expediente equivocado.'
                                    : 'Qué pasó.'
                            }
                        />
                        {errors.reason && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.reason}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant={esReactivacion ? 'default' : 'destructive'}
                            disabled={processing}
                        >
                            {esReactivacion
                                ? processing
                                    ? 'Reactivando…'
                                    : 'Reactivar expediente'
                                : processing
                                  ? 'Anulando…'
                                  : 'Anular expediente'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
