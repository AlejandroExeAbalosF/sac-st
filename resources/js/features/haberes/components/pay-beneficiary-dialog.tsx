import { useForm, usePage } from '@inertiajs/react';
import { HandCoins, Printer } from 'lucide-react';
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
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { businessToday, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { disbursement as registrarEgreso } from '@/routes/haberes/installments';
import {
    preview as previaDelEgreso,
    receipt as emitirRecibo,
} from '@/routes/haberes/installments/disbursement';
import DocumentFrame, { HOJA_ALTO_A5 } from './document-frame';
import TalonarioHint from './talonario-hint';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

/**
 * Entregar el dinero y hacer firmar el recibo, viendo antes el papel.
 *
 * **La vista previa no es una maqueta parecida: es el comprobante.** Sale
 * de la misma plantilla y del mismo armado que la impresión, así que lo
 * que se mira es exactamente lo que el beneficiario va a firmar.
 *
 * **Lo que no hay acá es un campo de importe.** Lo que se entrega es lo
 * que la cuota tiene financiado —incluido el excedente de redondeo del
 * §2.4, que en efectivo también se entrega— y el navegador no tiene por
 * qué poder proponer otra cosa: sería una forma de asentar una salida de
 * caja que no ocurrió.
 */
export default function PayBeneficiaryDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    estado: Estado;
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
    const [sobreTalonario, setSobreTalonario] = useState(false);

    const hoy = businessToday();
    /*
     * El interruptor depende del número: si se borra, lo que queda no es
     * una elección apagada sino una que no existe. El servidor y la base
     * rechazan igual encabezar con un número que no está.
     */
    const hayTalonario = talonario.trim() !== '';
    const conTalonarioArriba = hayTalonario && encabezaTalonario;

    /*
     * El pago puede ya estar hecho. Es el caso normal en la transferencia
     * —el contador validó y lo que falta es el papel— y el excepcional en
     * el mostrador, cuando el recibo se anuló y hay que rehacerlo. En los
     * dos, esto solo emite: el dinero ya salió.
     */
    const soloEmite = estado.needsReceipt;

    const form = useForm({
        talonarioNumber: '',
        printsTalonarioNumber: false as boolean,
        paymentDate: hoy,
        notes: '',
        idempotencyKey: '',
    });

    /*
     * La clave viaja con el formulario desde que se abre: es lo que hace
     * inocuo un segundo envío sin impedir una entrega distinta más
     * adelante.
     */
    const [clave] = useState(() => `egreso:${cuota.id}:${crypto.randomUUID()}`);

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };
    const erroresDelServidor = errors?.installmentId;

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

        if (sobreTalonario) {
            params.set('talonario', '1');
        }

        if (conTalonarioArriba) {
            params.set('printsTalonarioNumber', '1');
        }

        const query = params.toString();

        return previaDelEgreso(cuota.id).url + (query ? `?${query}` : '');
    }, [cuota.id, talonario, sobreTalonario, conTalonarioArriba]);

    /*
     * La hoja mide lo que mide —A5 apaisado— y el diálogo mide otra cosa.
     * Se la achica entera en vez de recortarla: un recibo con barras de
     * scroll no deja ver de un vistazo lo que se está por firmar.
     */

    const entregar = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((datos) => ({
            ...datos,
            talonarioNumber: talonario.trim(),
            printsTalonarioNumber: conTalonarioArriba,
            idempotencyKey: clave,
        }));

        /*
         * Dos rutas porque son dos actos distintos: una entrega el dinero
         * y emite; la otra solo emite el papel de un pago que ya está
         * asentado. Mandar todo por la primera habría hecho que el
         * servidor tenga que adivinar cuál de los dos se pidió.
         */
        form.post(
            soloEmite
                ? emitirRecibo(cuota.id).url
                : registrarEgreso(cuota.id).url,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTalonario('');
                    setEncabezaTalonario(false);
                    onCerrar();
                },
            },
        );
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="max-h-[92vh] gap-0 overflow-y-auto sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>
                        {soloEmite
                            ? 'Emitir el recibo de egreso'
                            : 'Entregar y emitir el recibo de egreso'}
                    </DialogTitle>
                    <DialogDescription>
                        {soloEmite
                            ? 'El pago ya está asentado: esto emite el comprobante, que toma su número al confirmar.'
                            : 'Así va a salir impreso. Al confirmar, el egreso queda asentado y la cuota pasa a pagada.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={entregar} className="mt-4 space-y-4">
                    <div className="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <div className="space-y-4">
                            <dl className="space-y-2 rounded-lg border p-3 text-sm">
                                <div className="flex justify-between gap-3">
                                    <dt className="text-xs text-field-label">
                                        Cuota
                                    </dt>
                                    <dd>N.º {cuota.number}</dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-xs text-field-label">
                                        Se entrega
                                    </dt>
                                    <dd className="font-mono font-semibold tabular-nums">
                                        {money(estado.amount)}
                                    </dd>
                                </div>
                                <div className="flex justify-between gap-3">
                                    <dt className="text-xs text-field-label">
                                        Medio
                                    </dt>
                                    <dd>
                                        {estado.method === 'cheque'
                                            ? 'El cheque en custodia'
                                            : estado.method === 'bank_transfer'
                                              ? 'Transferencia del organismo'
                                              : 'Efectivo de la caja'}
                                    </dd>
                                </div>
                                {cuota.concept && (
                                    <div>
                                        <dt className="text-xs text-field-label">
                                            Concepto
                                        </dt>
                                        <dd>{cuota.concept}</dd>
                                    </div>
                                )}
                            </dl>

                            {!soloEmite && (
                                <div className="space-y-3 rounded-lg border border-warning/40 bg-warning/5 p-3">
                                    <p className="text-xs font-medium">
                                        Al confirmarlo el dinero sale de la caja
                                    </p>

                                    <div className="space-y-1.5">
                                        <Label htmlFor={`entrega-${cuota.id}`}>
                                            Fecha de la entrega
                                        </Label>
                                        <Input
                                            id={`entrega-${cuota.id}`}
                                            type="date"
                                            /*
                                             * Entre el alta de la cuota y
                                             * hoy. El servidor valida lo
                                             * mismo; esto evita llegar al
                                             * error.
                                             */
                                            min={cuota.createdAt}
                                            max={hoy}
                                            value={form.data.paymentDate}
                                            onChange={(e) =>
                                                form.setData(
                                                    'paymentDate',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.paymentDate}
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <Label htmlFor={`notas-${cuota.id}`}>
                                            Observaciones
                                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                opcional
                                            </span>
                                        </Label>
                                        <Textarea
                                            id={`notas-${cuota.id}`}
                                            rows={2}
                                            value={form.data.notes}
                                            onChange={(e) =>
                                                form.setData(
                                                    'notes',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            Quedan en el asiento, no en el
                                            papel.
                                        </p>
                                        <InputError
                                            message={form.errors.notes}
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="space-y-3 rounded-lg border p-3">
                                <div className="space-y-1.5">
                                    <Label htmlFor={`talonario-${cuota.id}`}>
                                        N.º del talonario
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
                                        placeholder="00076397"
                                        className="font-mono tabular-nums"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Si el papel se escribió a mano y se
                                        carga después.
                                    </p>
                                    <InputError
                                        message={form.errors.talonarioNumber}
                                    />
                                    <TalonarioHint
                                        value={talonario}
                                        ultimo={ultimoTalonario.expense}
                                    />
                                </div>

                                {/*
                                 * Cuál de los dos números encabeza el
                                 * papel. Es una elección del operador y no
                                 * una consecuencia de haber cargado el
                                 * número: los dos quedan en el registro.
                                 */}
                                <div className="flex items-start gap-2.5 pt-1">
                                    <Switch
                                        id={`encabeza-egreso-${cuota.id}`}
                                        checked={conTalonarioArriba}
                                        onCheckedChange={setEncabezaTalonario}
                                        disabled={!hayTalonario}
                                        className="mt-0.5"
                                    />
                                    <div className="space-y-0.5">
                                        <Label
                                            htmlFor={`encabeza-egreso-${cuota.id}`}
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

                            {/*
                             * El pie del recibo de egreso lo firma quien
                             * cobra, así que acá no hay firmante que
                             * elegir: el renglón sale en blanco.
                             */}
                            <p className="text-xs text-muted-foreground">
                                El renglón de firma sale vacío: lo completa el
                                beneficiario al recibir el dinero.
                            </p>

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
                            titulo="Vista previa del recibo de egreso"
                            hojaAlto={HOJA_ALTO_A5}
                            className="self-start"
                        />
                    </div>

                    {/*
                     * Los rechazos del Action —la etiqueta que bloquea, el
                     * recibo de ingreso que falta, la cuota incompleta—
                     * llegan con la clave del campo del backend, que no
                     * está en el tipo de este formulario.
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
                                <HandCoins className="size-4" />
                                {soloEmite
                                    ? 'Emitir recibo'
                                    : 'Entregar y emitir'}
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
