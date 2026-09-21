import { useForm, usePage } from '@inertiajs/react';
import { FileText, Printer } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Switch } from '@/components/ui/switch';
import { businessToday, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { receipt as emitirRecibo } from '@/routes/haberes/installments';
import { preview as previaDelRecibo } from '@/routes/haberes/installments/receipt';
import DocumentFrame, { HOJA_ALTO_A5 } from './document-frame';
import TalonarioHint from './talonario-hint';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
export type Firmante = { id: number; name: string; position: string | null };

/**
 * Emitir el recibo, viendo antes lo que va a salir impreso.
 *
 * **La vista previa no es una maqueta parecida: es el comprobante.** Sale
 * de la misma plantilla y del mismo armado que la impresión, así que lo
 * que se mira es exactamente lo que se entrega. Una previsualización que
 * compone los datos por su cuenta termina mostrando otra cosa, y entonces
 * mirarla no sirve para nada.
 *
 * Se ve antes de confirmar porque emitir consume un número de la serie y
 * entrega un papel: no es un acto que convenga disparar de un clic.
 */
export default function IssueReceiptDialog({
    cuota,
    firmantes,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    firmantes: Firmante[];
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [talonario, setTalonario] = useState('');

    /*
     * El último número cargado en esta serie, para avisar si el que se
     * tipea no lo sigue. Se lee de la página en vez de enhebrarlo por
     * cuatro niveles de props: este diálogo solo se abre desde la ficha
     * del haber, así que la dependencia ya existe.
     */
    const { ultimoTalonario } = usePage().props as unknown as {
        ultimoTalonario: Record<string, string | null>;
    };

    const [encabezaTalonario, setEncabezaTalonario] = useState(false);
    const [firmante, setFirmante] = useState('');
    const [sobreTalonario, setSobreTalonario] = useState(false);

    /*
     * Si la cuota todavía no está financiada y entra por mostrador, este
     * acto además cobra: el dinero llegó con el expediente y el recibo es
     * lo que lo asienta. En el circuito bancario ya entró por el extracto
     * y acá solo se emite.
     *
     * El servidor decide lo mismo por su cuenta, leyendo la cuota. Esto
     * decide qué mostrar, no qué se asienta.
     */
    const hoy = businessToday();
    /*
     * El interruptor depende del número: si se borra, lo que queda no es
     * una elección apagada sino una que no existe. El servidor y la base
     * rechazan igual encabezar con un número que no está.
     */
    const hayTalonario = talonario.trim() !== '';
    const conTalonarioArriba = hayTalonario && encabezaTalonario;
    const esCheque = cuota.expectedMedium === 'cheque';
    const porMostrador = esCheque || cuota.expectedMedium === 'cash';
    const hayQueCobrar = !cuota.isFullyFunded && porMostrador;

    const form = useForm({
        talonarioNumber: '',
        signedById: '',
        idempotencyKey: '',
        receivedDate: cuota.createdAt,
        notes: '',
        chequeNumber: '',
        chequeBank: '',
        chequeIssueDate: '',
    });

    /*
     * La clave viaja con el formulario desde que se abre: es lo que hace
     * inocuo un segundo envio sin impedir un cobro distinto mas adelante.
     */
    const [clave] = useState(() => `recibo:${cuota.id}:${crypto.randomUUID()}`);

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };
    const erroresDelServidor = errors?.installmentId ?? errors?.personId;

    /*
     * La vista previa se recarga sola cuando cambia algo que sale
     * impreso. Va por `useMemo` y no por estado para que no haga falta
     * sincronizar nada: la URL es una función de lo que el operador
     * eligió.
     */
    const urlPrevia = useMemo(() => {
        const params = new URLSearchParams();

        if (talonario.trim() !== '') {
            params.set('talonarioNumber', talonario.trim());
        }

        if (firmante !== '') {
            params.set('signedById', firmante);
        }

        if (sobreTalonario) {
            params.set('talonario', '1');
        }

        if (conTalonarioArriba) {
            params.set('printsTalonarioNumber', '1');
        }

        const query = params.toString();

        return previaDelRecibo(cuota.id).url + (query ? `?${query}` : '');
    }, [cuota.id, talonario, firmante, sobreTalonario, conTalonarioArriba]);

    /*
     * La hoja mide lo que mide —A5 apaisado— y el dialogo mide otra cosa.
     * Se la achica entera en vez de recortarla: un recibo con barras de
     * scroll no deja ver de un vistazo lo que se esta por firmar.
     */

    const emitir = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((datos) => ({
            ...datos,
            talonarioNumber: talonario.trim(),
            printsTalonarioNumber: conTalonarioArriba,
            signedById: firmante,
            idempotencyKey: clave,
        }));

        form.post(emitirRecibo(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                setTalonario('');
                setEncabezaTalonario(false);
                setFirmante('');
                onCerrar();
            },
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="max-h-[92vh] gap-0 overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {hayQueCobrar
                            ? 'Cobrar y emitir el recibo'
                            : 'Emitir el recibo de ingreso'}
                    </DialogTitle>
                    <DialogDescription>
                        {hayQueCobrar
                            ? 'Así va a salir impreso. Al confirmar, el ingreso queda asentado: el recibo es su constancia.'
                            : 'Así va a salir impreso. El comprobante se emite una sola vez y toma su número al confirmar.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={emitir} className="mt-4 space-y-4">
                    <div className="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <div className="space-y-4">
                            <dl className="space-y-2 rounded-lg border p-3 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-xs text-field-label">
                                        Cuota
                                    </dt>
                                    <dd>N.º {cuota.number}</dd>
                                </div>
                                {!hayQueCobrar && (
                                    <div className="flex justify-between gap-3">
                                        <dt className="text-xs text-field-label">
                                            Importe
                                        </dt>
                                        <dd className="font-mono font-semibold tabular-nums">
                                            {money(cuota.fundedAmount)}
                                        </dd>
                                    </div>
                                )}
                                {cuota.concept && (
                                    <div>
                                        <dt className="text-xs text-field-label">
                                            Concepto
                                        </dt>
                                        <dd>{cuota.concept}</dd>
                                    </div>
                                )}
                            </dl>

                            {hayQueCobrar && (
                                <div className="space-y-3 rounded-lg border border-warning/40 bg-warning/5 p-3">
                                    <p className="text-xs font-medium">
                                        Al emitirlo se registra el cobro
                                    </p>

                                    {/*
                                     * El importe y el medio son datos, no
                                     * campos: los trae la cuota desde que
                                     * se cargó. Escribirlos otra vez acá
                                     * solo abría la puerta a un recibo que
                                     * dijera algo distinto del expediente.
                                     */}
                                    <dl className="space-y-1.5 text-sm">
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-xs text-field-label">
                                                Importe
                                            </dt>
                                            <dd className="font-mono font-semibold tabular-nums">
                                                {money(cuota.remainingAmount)}
                                            </dd>
                                        </div>
                                        <div className="flex justify-between gap-3">
                                            <dt className="text-xs text-field-label">
                                                Medio
                                            </dt>
                                            <dd>
                                                {esCheque
                                                    ? 'Cheque'
                                                    : 'Efectivo'}
                                            </dd>
                                        </div>
                                    </dl>

                                    <div className="space-y-1.5">
                                        <Label htmlFor={`fecha-${cuota.id}`}>
                                            Fecha del pago
                                        </Label>
                                        <Input
                                            id={`fecha-${cuota.id}`}
                                            type="date"
                                            /*
                                             * Entre el alta de la cuota y
                                             * hoy. El servidor valida lo
                                             * mismo; esto evita llegar al
                                             * error.
                                             */
                                            min={cuota.createdAt}
                                            max={hoy}
                                            value={form.data.receivedDate}
                                            onChange={(e) =>
                                                form.setData(
                                                    'receivedDate',
                                                    e.target.value,
                                                )
                                            }
                                        />

                                        <InputError
                                            message={form.errors.receivedDate}
                                        />
                                    </div>

                                    {/*
                                     * El cheque sigue el circuito del
                                     * efectivo y queda en custodia. Su
                                     * número está acá porque el reverso de
                                     * la planilla de caja los inventaria
                                     * uno por uno, y no lo sabe nadie hasta
                                     * tener el papel en la mano.
                                     */}
                                    {esCheque && (
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-1.5">
                                                <Label
                                                    htmlFor={`cheque-n-${cuota.id}`}
                                                >
                                                    N.º de cheque
                                                </Label>
                                                <Input
                                                    id={`cheque-n-${cuota.id}`}
                                                    value={
                                                        form.data.chequeNumber
                                                    }
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'chequeNumber',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="font-mono tabular-nums"
                                                />
                                                <InputError
                                                    message={
                                                        form.errors.chequeNumber
                                                    }
                                                />
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label
                                                    htmlFor={`cheque-b-${cuota.id}`}
                                                >
                                                    Banco librador
                                                </Label>
                                                <Input
                                                    id={`cheque-b-${cuota.id}`}
                                                    value={form.data.chequeBank}
                                                    onChange={(e) =>
                                                        form.setData(
                                                            'chequeBank',
                                                            e.target.value,
                                                        )
                                                    }
                                                />
                                            </div>
                                        </div>
                                    )}

                                    <p className="text-xs text-muted-foreground">
                                        Si lo que llegó no coincide, se corrige
                                        la cuota y después se emite.
                                    </p>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label htmlFor={`talonario-${cuota.id}`}>
                                    Número del talonario
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional
                                    </span>
                                </Label>
                                <Input
                                    id={`talonario-${cuota.id}`}
                                    value={talonario}
                                    onChange={(e) =>
                                        setTalonario(e.target.value)
                                    }
                                    placeholder="00071514"
                                    className="font-mono tabular-nums"
                                />
                                <InputError
                                    message={form.errors.talonarioNumber}
                                />
                                <TalonarioHint
                                    value={talonario}
                                    ultimo={ultimoTalonario.income}
                                />

                                {/*
                                 * Cuál de los dos números encabeza el
                                 * papel. Los dos quedan impresos y la
                                 * relación se guarda con el recibo: la
                                 * elección es del operador, no una
                                 * consecuencia de haber cargado el número.
                                 */}
                                <div className="flex items-start gap-2.5 pt-1">
                                    <Switch
                                        id={`encabeza-${cuota.id}`}
                                        checked={conTalonarioArriba}
                                        onCheckedChange={setEncabezaTalonario}
                                        disabled={!hayTalonario}
                                        className="mt-0.5"
                                    />
                                    <div className="space-y-0.5">
                                        <Label
                                            htmlFor={`encabeza-${cuota.id}`}
                                            className={cn(
                                                'text-xs font-normal',
                                                !hayTalonario &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            Encabezar con el número del
                                            talonario
                                        </Label>
                                        <p className="text-xs text-muted-foreground">
                                            {!hayTalonario
                                                ? 'Se habilita al cargar el número.'
                                                : conTalonarioArriba
                                                  ? 'El del sistema queda abajo, como registro.'
                                                  : 'El papel sale con el número del sistema.'}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-1.5">
                                <Label htmlFor={`firmante-${cuota.id}`}>
                                    Firma
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional
                                    </span>
                                </Label>
                                <Select
                                    value={firmante || 'ninguno'}
                                    onValueChange={(v) =>
                                        setFirmante(v === 'ninguno' ? '' : v)
                                    }
                                >
                                    <SelectTrigger id={`firmante-${cuota.id}`}>
                                        <SelectValue placeholder="Sin aclaración impresa" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="ninguno">
                                            Sin aclaración impresa
                                        </SelectItem>
                                        {firmantes.map((f) => (
                                            <SelectItem
                                                key={f.id}
                                                value={f.id.toString()}
                                            >
                                                {f.name}
                                                {f.position
                                                    ? ` · ${f.position}`
                                                    : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    El nombre sale debajo de la línea; el
                                    espacio de arriba queda para firmar.
                                </p>
                                <InputError message={form.errors.signedById} />
                            </div>

                            {/*
                             * Dos destinos del mismo comprobante. Sobre el
                             * talonario se apaga el fondo: el papel ya trae
                             * el formulario y solo hay que poner los datos.
                             */}
                            <div className="flex gap-1 rounded-md border p-1 text-xs">
                                <Opcion
                                    activa={!sobreTalonario}
                                    onClick={() => setSobreTalonario(false)}
                                    etiqueta="Papel en blanco"
                                />
                                <Opcion
                                    activa={sobreTalonario}
                                    onClick={() => setSobreTalonario(true)}
                                    etiqueta="Sobre el talonario"
                                />
                            </div>
                        </div>

                        {/*
                         * El comprobante mismo, en un marco. Va en iframe
                         * porque es un documento con sus propias medidas y
                         * su propia hoja de estilos: incrustarlo en el DOM
                         * de la aplicación lo desarmaría.
                         */}
                        <DocumentFrame
                            url={urlPrevia}
                            titulo="Vista previa del recibo"
                            hojaAlto={HOJA_ALTO_A5}
                            className="self-start"
                        />
                    </div>

                    {/*
                     * Los rechazos del Action —cuota incompleta, recibo
                     * ya emitido, expediente sin empleador— llegan con la
                     * clave del campo del backend, que no está en el tipo
                     * de este formulario.
                     */}
                    <InputError message={erroresDelServidor} />

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button variant="ghost" asChild>
                            <a
                                href={urlPrevia}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer className="size-4" />
                                Abrir en una pestaña
                            </a>
                        </Button>

                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onCerrar}
                            >
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                <FileText className="size-4" />
                                {hayQueCobrar
                                    ? 'Cobrar y emitir'
                                    : 'Emitir recibo'}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function Opcion({
    activa,
    onClick,
    etiqueta,
}: {
    activa: boolean;
    onClick: () => void;
    etiqueta: string;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'flex-1 rounded px-2 py-1.5 transition-colors',
                activa
                    ? 'bg-primary text-primary-foreground'
                    : 'hover:bg-muted',
            )}
        >
            {etiqueta}
        </button>
    );
}
