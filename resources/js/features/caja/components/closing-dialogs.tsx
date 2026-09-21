import { useForm, usePage } from '@inertiajs/react';
import { FileSpreadsheet, RefreshCw, TriangleAlert } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { businessToday, dateTime as formatDateTime } from '@/lib/format';
import { download } from '@/routes/adjuntos';
import { reopen, store } from '@/routes/caja/cierres';
import { regenerate } from '@/routes/caja/cierres/sheet';

type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;

/*
 * Los diálogos que operan sobre un cierre ya hecho.
 *
 * **Viven acá y no en la pantalla de Cierres porque los usan dos.** La
 * caja del día los necesita igual: mandar al operador a otra pantalla a
 * buscar la tarjeta del día que ya tiene abierto para reabrirlo o rehacer
 * su planilla es un rodeo que no aporta nada.
 */

/**
 * Las planillas que este cierre emitió, de la última a la primera.
 *
 * **Guardar las versiones anteriores sin poder abrirlas no serviría de
 * nada.** Una planilla vieja puede estar impresa y firmada en una carpeta,
 * y el día que alguien pregunte por qué el papel no coincide con lo que
 * baja hoy, la respuesta tiene que estar a un clic.
 *
 * Todas se pueden descargar: ninguna deja de ser un documento por haber
 * sido reemplazada.
 */
export function DialogoHistorialDePlanillas({
    cierre,
    cerrar,
}: {
    cierre: Cierre | null;
    cerrar: () => void;
}) {
    return (
        <Dialog
            open={cierre !== null}
            onOpenChange={(open) => open || cerrar()}
        >
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Planillas emitidas</DialogTitle>
                    <DialogDescription>
                        Rehacer una planilla no pisa la anterior. Todas quedan,
                        y todas se pueden descargar.
                    </DialogDescription>
                </DialogHeader>

                <ul className="grid gap-2">
                    {cierre?.sheetHistory.map((planilla) => (
                        <li
                            key={planilla.id}
                            className={
                                planilla.current
                                    ? 'flex flex-wrap items-center gap-3 rounded-lg border bg-card px-3 py-2.5'
                                    : 'flex flex-wrap items-center gap-3 rounded-lg border border-dashed px-3 py-2.5'
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium">
                                    {formatDateTime(planilla.issuedAt)}
                                    {planilla.current && (
                                        <span className="ml-2 text-xs font-normal text-success-strong">
                                            vigente
                                        </span>
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {planilla.issuedBy ?? 'Sin autor'}
                                    {planilla.templateVersion !== null
                                        ? ` · formulario v${planilla.templateVersion}`
                                        : ' · formulario sin marcar'}
                                </p>
                            </div>

                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={
                                        download({ attachment: planilla.id })
                                            .url
                                    }
                                >
                                    <FileSpreadsheet className="size-4" />
                                    Descargar
                                </a>
                            </Button>
                        </li>
                    ))}
                </ul>

                <DialogFooter>
                    <Button variant="outline" type="button" onClick={cerrar}>
                        Cerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DialogoRehacerPlanilla({
    cierre,
    cerrar,
}: {
    cierre: Cierre | null;
    cerrar: () => void;
}) {
    /* `RegenerateCashSheet` rechaza con `status` un cierre sin planilla. */
    const { errors: deLaPagina } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const form = useForm({ reason: '' });

    return (
        <Dialog
            open={cierre !== null}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    cerrar();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Rehacer la planilla</DialogTitle>
                    <DialogDescription>
                        Vuelve a dibujar el Excel con el formulario actual. Los
                        importes no cambian: son los que el cierre congeló. La
                        planilla anterior queda archivada, y el motivo se
                        registra con tu nombre.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-rehacer-planilla"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (cierre === null) {
                            return;
                        }

                        form.post(regenerate({ closing: cierre.id }).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                cerrar();
                            },
                        });
                    }}
                    className="grid gap-2"
                >
                    <Label htmlFor="reason-planilla">Motivo</Label>
                    <Textarea
                        id="reason-planilla"
                        rows={3}
                        placeholder="Qué cambió en el formulario y por qué hay que rehacerla."
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                    <InputError message={deLaPagina.status} />
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-rehacer-planilla"
                        disabled={form.processing}
                    >
                        <RefreshCw className="size-4" />
                        Rehacer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DialogoReapertura({
    cierre,
    cerrar,
}: {
    cierre: Cierre | null;
    cerrar: () => void;
}) {
    /* `ReopenPeriod` rechaza con `status` cuando hay un mensual por encima. */
    const { errors: deLaPagina } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const form = useForm({ reason: '' });

    return (
        <Dialog
            open={cierre !== null}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    cerrar();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Reabrir el período</DialogTitle>
                    <DialogDescription>
                        El motivo queda registrado con tu nombre y la fecha. Los
                        totales no se reescriben: volver a cerrar es lo que los
                        recalcula.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-reabrir"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (cierre === null) {
                            return;
                        }

                        form.post(reopen({ closing: cierre.id }).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                cerrar();
                            },
                        });
                    }}
                    className="grid gap-2"
                >
                    <Label htmlFor="reason">Motivo</Label>
                    <Textarea
                        id="reason"
                        rows={3}
                        placeholder="Qué falta o qué hay que corregir."
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                    <InputError message={deLaPagina.status} />
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-reabrir"
                        disabled={form.processing}
                    >
                        Reabrir
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function DialogoCierre({
    inicial,
    cerrar,
    selected,
    pendingDaysByMonth,
}: {
    /** El tipo y la fecha con los que abre: un día nuevo, o una fila reabierta. */
    inicial: { date: string; periodType: string };
    cerrar: () => void;
    selected: { cashBoxId: number; currency: string };
    /** Días con movimientos sin cerrar, por mes `YYYY-MM`. */
    pendingDaysByMonth: Record<string, number>;
}) {
    /*
     * `period` y `status` no son campos del formulario, así que no entran en
     * `form.errors`, que está tipado por la forma de los datos. Se leen de
     * las props de la página.
     *
     * Es la única razón válida para hacerlo: todo lo demás que llega en
     * `page.props.errors` ya está en `form.errors` —`useForm` los copia—, y
     * pintar los dos mostraba el mismo mensaje dos veces.
     */
    const { errors: deLaPagina } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const hoy = businessToday();

    const form = useForm({
        cashBoxId: selected.cashBoxId,
        date: inicial.date,
        periodType: inicial.periodType,
        currency: selected.currency,
        notes: '',
    });

    /*
     * Solo tiene sentido para el cierre mensual: en el diario, el día que
     * se está cerrando es exactamente el que falta.
     */
    const diasPendientes =
        form.data.periodType === 'monthly'
            ? (pendingDaysByMonth[form.data.date.slice(0, 7)] ?? 0)
            : 0;

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    cerrar();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cerrar período</DialogTitle>
                    <DialogDescription>
                        Los totales se calculan desde el libro y quedan
                        congelados. A partir de ahí el período no admite
                        movimientos con fecha adentro.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-cierre"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(store().url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                cerrar();
                            },
                        });
                    }}
                    className="grid gap-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="periodType">Período</Label>
                        <Select
                            value={form.data.periodType}
                            onValueChange={(valor) =>
                                form.setData('periodType', valor)
                            }
                        >
                            <SelectTrigger id="periodType">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="daily">Diario</SelectItem>
                                <SelectItem value="monthly">Mensual</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.periodType} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">
                            {form.data.periodType === 'monthly'
                                ? 'Un día del mes a cerrar'
                                : 'Día a cerrar'}
                        </Label>
                        <Input
                            id="date"
                            type="date"
                            max={hoy}
                            value={form.data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                        />
                        <InputError message={form.errors.date} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Observaciones</Label>
                        <Textarea
                            id="notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    {/*
                     * Ya no es un aviso: es lo que va a impedir el cierre.
                     *
                     * Los totales del mes se calculan sobre el libro, así
                     * que cerraban igual aunque ninguna jornada hubiera
                     * pasado por su arqueo — y el control diario, que es
                     * donde se detecta un faltante, quedaba salteado. El
                     * área definió que el mes exige sus días cerrados.
                     */}
                    {diasPendientes > 0 && (
                        <p className="flex items-start gap-2 rounded-md border border-warning-soft bg-warning-soft px-3 py-2 text-xs text-warning-strong">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {diasPendientes === 1
                                    ? 'Queda 1 día con movimientos sin cerrar en este mes.'
                                    : `Quedan ${diasPendientes} días con movimientos sin cerrar en este mes.`}{' '}
                                El mes no se puede cerrar hasta que estén
                                cerrados: sin su arqueo, esos días nunca pasaron
                                por el control del cajón.
                            </span>
                        </p>
                    )}

                    <InputError message={deLaPagina.period} />
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-cierre"
                        disabled={form.processing}
                    >
                        Cerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
