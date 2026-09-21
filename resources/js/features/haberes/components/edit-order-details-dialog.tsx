import { useForm, usePage } from '@inertiajs/react';
import { Lock, Save } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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
import { Textarea } from '@/components/ui/textarea';
import {
    print as imprimirOrden,
    update as corregirOrden,
    view as verOrden,
} from '@/routes/ordenes';
import { print as imprimirPase, view as verPase } from '@/routes/pases';
import DocumentPreview from './document-preview';
import type { DocumentoParaVer } from './document-preview';
import DocumentoTag from './documento-tag';
import type { EnQuePapel } from './documento-tag';
import { FojaEnLosPapeles } from './order-form-fields';

type Orden = App.Modules.Haberes.Data.PaymentOrderSummaryData;

/**
 * Corregir lo accesorio de la Orden y de su Pase.
 *
 * **Es el camino de la foja aclaratoria**, uno de los dos que el área usa
 * cuando algo está mal: se agrega una foja explicando el problema y los
 * mismos papeles siguen su camino. El otro es anular y rehacer, y lo elige
 * quien opera —es el único que tiene el papel a la vista—.
 *
 * Lo que se arregla acá es la foja donde figura el CBU y el destinatario
 * de la nota.
 *
 * Lo que **no** está acá —importe, beneficiario, cuenta, número— no es un
 * olvido: es lo que compromete al organismo a mover dinero, y un trigger
 * de la base lo impide. Si eso está mal no hay corrección que valga, hay
 * que anular.
 */
export default function EditOrderDetailsDialog({
    orden,
    abierto,
    onCerrar,
}: {
    orden: Orden;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const form = useForm({
        cbuFolio: orden.cbuFolio ?? '',
        paseDestination: orden.pase?.destination ?? '',
        paseNotes: orden.pase?.notes ?? '',
    });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    /**
     * Las dos hojas con los retoques que todavía no se guardaron.
     *
     * La vista los acepta por querystring; **la impresión no**, y apunta
     * derecho a lo guardado. Es a propósito: el papel que se manda a
     * imprimir no puede decir algo que el registro no dice.
     */
    const documentos = useMemo((): DocumentoParaVer[] => {
        const params = new URLSearchParams({
            cbuFolio: form.data.cbuFolio,
            paseDestination: form.data.paseDestination,
            paseNotes: form.data.paseNotes,
        }).toString();

        const hojas: DocumentoParaVer[] = [
            {
                etiqueta: 'Orden de Pago',
                urlVista: `${verOrden(orden.id).url}?${params}`,
                urlImpresion: imprimirOrden(orden.id).url,
            },
        ];

        if (orden.pase !== null) {
            hojas.push({
                etiqueta: 'Nota de Pase',
                urlVista: `${verPase(orden.pase.id).url}?${params}`,
                urlImpresion: imprimirPase(orden.pase.id).url,
            });
        }

        return hojas;
    }, [orden, form.data]);

    /* Medio segundo de quietud, para no pedir las hojas por cada tecla. */
    const [enVivo, setEnVivo] = useState(documentos);

    useEffect(() => {
        const reloj = setTimeout(() => setEnVivo(documentos), 500);

        return () => clearTimeout(reloj);
    }, [documentos]);

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.patch(corregirOrden(orden.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        Corregir la Orden de Pago {orden.number}
                    </DialogTitle>
                    <DialogDescription>
                        Los renglones administrativos del documento. Lo que
                        compromete el pago no se edita.
                    </DialogDescription>
                </DialogHeader>

                {/*
                 * El formulario a la izquierda y las hojas al costado, con
                 * lo escrito ya aplicado: corregir a ciegas una observación
                 * que va impresa es cómo se manda una segunda versión con
                 * el mismo problema.
                 *
                 * Las alturas van explícitas en `vh` y no por `flex-1`:
                 * `DialogContent` es `position: fixed` y no tiene altura
                 * propia contra la que el flex pueda resolver, así que la
                 * columna crecía hasta su contenido y se desbordaba por
                 * abajo del diálogo.
                 */}
                <div className="mt-4 grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="max-h-[62vh] overflow-y-auto pr-1">
                        <form onSubmit={enviar} className="space-y-4">
                            {/*
                             * Agrupado por documento, no por tipo de campo. Los dos
                             * papeles se corrigen en la misma pantalla y no se
                             * parecen en nada, así que cada bloque dice dónde cae
                             * lo que carga. La foja va rotulada «los dos papeles»
                             * porque es el único dato que se escribe una vez y se
                             * imprime dos: el OBS de la Orden y la cita de la nota.
                             */}
                            <Bloque
                                titulo="Foja donde figura el CBU"
                                papel="ambos"
                            >
                                <div className="max-w-xs space-y-1.5">
                                    <Label htmlFor={`folio-${orden.id}`}>
                                        Foja
                                    </Label>
                                    <Input
                                        id={`folio-${orden.id}`}
                                        value={form.data.cbuFolio}
                                        onChange={(e) =>
                                            form.setData(
                                                'cbuFolio',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="19"
                                    />
                                    <InputError
                                        message={form.errors.cbuFolio}
                                    />
                                </div>

                                <FojaEnLosPapeles foja={form.data.cbuFolio} />
                            </Bloque>

                            <Bloque titulo="En la nota de Pase" papel="pase">
                                <div className="max-w-sm space-y-1.5">
                                    <Label htmlFor={`destino-${orden.id}`}>
                                        Destinatario
                                    </Label>
                                    <Input
                                        id={`destino-${orden.id}`}
                                        value={form.data.paseDestination}
                                        onChange={(e) =>
                                            form.setData(
                                                'paseDestination',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Encabeza «Pase de Contable — A{' '}
                                        {form.data.paseDestination}».
                                    </p>
                                    <InputError
                                        message={form.errors.paseDestination}
                                    />
                                </div>

                                <div className="mt-4 space-y-1.5">
                                    <Label htmlFor={`pase-notas-${orden.id}`}>
                                        Párrafo extra
                                    </Label>
                                    <Textarea
                                        id={`pase-notas-${orden.id}`}
                                        rows={2}
                                        value={form.data.paseNotes}
                                        onChange={(e) =>
                                            form.setData(
                                                'paseNotes',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Se acompaña foja de observación con el CBU corregido."
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Sale antes de «Sin más, sirva la
                                        presente de atenta nota».
                                    </p>
                                    <InputError
                                        message={form.errors.paseNotes}
                                    />
                                </div>
                            </Bloque>

                            {/*
                             * Decirlo acá y no dejar que lo descubra probando: quien
                             * abre esto suele venir buscando corregir el importe.
                             */}
                            <p className="flex items-start gap-2 rounded-lg border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                                <Lock
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                El importe, el beneficiario, la cuenta y el
                                número no se editan: son lo que le pide al
                                organismo que mueva el dinero. Si alguno está
                                mal, hay que anular la Orden y emitir otra.
                            </p>

                            <InputError message={errors?.orderId} />

                            <DialogFooter className="gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onCerrar}
                                >
                                    Cancelar
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    <Save className="size-4" />
                                    Guardar
                                </Button>
                            </DialogFooter>
                        </form>
                    </div>

                    <aside className="hidden lg:block">
                        <p className="mb-2 text-xs text-muted-foreground">
                            Con lo escrito ya aplicado. Se guarda recién al
                            confirmar; lo que se imprime desde acá es lo
                            guardado.
                        </p>
                        <DocumentPreview
                            documentos={enVivo}
                            alto="56vh"
                            compacto
                        />
                    </aside>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** Un bloque del diálogo, rotulado con el papel en el que cae. */
function Bloque({
    titulo,
    papel,
    children,
}: {
    titulo: string;
    papel: EnQuePapel;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-lg border p-4">
            <h3 className="mb-3 flex flex-wrap items-center gap-2 text-xs font-semibold tracking-wide text-field-label uppercase">
                {titulo}
                <DocumentoTag papel={papel} />
            </h3>
            {children}
        </section>
    );
}
