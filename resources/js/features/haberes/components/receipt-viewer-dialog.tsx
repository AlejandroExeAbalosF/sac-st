import { Printer } from 'lucide-react';
import { useMemo, useState } from 'react';
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
import { Switch } from '@/components/ui/switch';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { print as imprimirRecibo, view as verRecibo } from '@/routes/recibos';
import DocumentFrame, { HOJA_ALTO_A5 } from './document-frame';

type Recibo = App.Modules.Haberes.Data.InstallmentReceiptData;

/**
 * El comprobante ya emitido, para mirarlo antes de mandarlo al papel.
 *
 * **Reemplaza a los dos botones sueltos** que había —«Imprimir» y «Sobre
 * el talonario»—: cada uno abría una pestaña con un PDF y no había forma
 * de ver cuál era cuál sin abrirlos. Acá se elige mirando.
 *
 * Lo que se puede cambiar es cómo sale este papel, no lo que dice: el
 * importe, las partes, la fecha y la aclaración quedaron congelados el día
 * que se emitió, y un comprobante entregado no se reescribe.
 */
export default function ReceiptViewerDialog({
    recibo,
    titulo = 'Recibo de ingreso',
    abierto,
    onCerrar,
}: {
    recibo: Recibo;
    /*
     * Cómo se llama el papel. El visor sirve para los dos comprobantes
     * —las rutas eligen la plantilla por el tipo del propio recibo— y lo
     * único que no puede deducir es cómo nombrarlo en el encabezado.
     */
    titulo?: string;
    abierto: boolean;
    onCerrar: () => void;
}) {
    /*
     * Arranca en lo que se eligió al emitir, que es para lo que se guarda.
     * Sin número del talonario no hay nada que alternar.
     */
    const [encabezaTalonario, setEncabezaTalonario] = useState(
        recibo.printsTalonarioNumber,
    );
    const [sobreTalonario, setSobreTalonario] = useState(false);

    const hayTalonario = recibo.talonarioNumber !== null;
    const conTalonarioArriba = hayTalonario && encabezaTalonario;

    const parametros = useMemo(() => {
        const params = new URLSearchParams();

        if (sobreTalonario) {
            params.set('talonario', '1');
        }

        /*
         * Siempre explícito, incluso cuando coincide con lo guardado: sin
         * el parámetro manda lo del recibo, y apagar el interruptor tiene
         * que poder decir «este no».
         */
        params.set('printsTalonarioNumber', conTalonarioArriba ? '1' : '0');

        return `?${params.toString()}`;
    }, [sobreTalonario, conTalonarioArriba]);

    const urlVista = verRecibo(recibo.id).url + parametros;
    const urlImpresion = imprimirRecibo(recibo.id).url + parametros;

    /*
     * El comprobante se mira, no se adivina.
     *
     * La hoja se escala al ancho que le sobra, así que el ancho del
     * diálogo es el tamaño con el que se ve el recibo: con `max-w-4xl` la
     * columna daba unos 560 px para un papel de 794 y salía al 70%, con la
     * letra chica del formulario reducida otro tanto.
     *
     * A `6xl` la hoja llega a su tamaño real. El tope de alto es lo que
     * permite darle ese ancho sin romper nada: en una pantalla baja la hoja
     * se angosta sola antes de empujar el pie del diálogo fuera de la
     * ventana.
     */
    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-6xl">
                <DialogHeader>
                    <DialogTitle>
                        {titulo} {recibo.formattedNumber}
                    </DialogTitle>
                    <DialogDescription>
                        Así va a salir impreso. Lo que se elige acá es la
                        salida; lo que el comprobante dice quedó fijado al
                        emitirlo.
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-4 grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
                    <div className="space-y-4">
                        <dl className="space-y-2 rounded-lg border p-3 text-sm">
                            <Dato etiqueta="Emitido">
                                {date(recibo.issueDate)}
                            </Dato>
                            <Dato etiqueta="Importe">
                                <span className="font-mono font-semibold tabular-nums">
                                    {money(recibo.amount)}
                                </span>
                            </Dato>
                            <Dato etiqueta="N.º del sistema">
                                <span className="font-mono">
                                    {recibo.formattedNumber}
                                </span>
                            </Dato>
                            {hayTalonario && (
                                <Dato etiqueta="N.º del talonario">
                                    <span className="font-mono">
                                        {recibo.talonarioNumber}
                                    </span>
                                </Dato>
                            )}
                            <div>
                                <dt className="text-xs text-field-label">
                                    Aclaración al pie
                                </dt>
                                <dd>
                                    {recibo.signedByName ?? (
                                        <span className="text-muted-foreground">
                                            Sin aclaración impresa
                                        </span>
                                    )}
                                    {recibo.signedByTitle !== null && (
                                        <span className="block text-xs text-muted-foreground">
                                            {recibo.signedByTitle}
                                        </span>
                                    )}
                                </dd>
                            </div>
                        </dl>

                        {/*
                         * Cuál de los dos números encabeza. Solo tiene
                         * sentido con los dos cargados; los dos llevan al
                         * mismo registro, porque la relación está guardada.
                         */}
                        {hayTalonario && (
                            <div className="flex items-start gap-2.5">
                                <Switch
                                    id={`ver-encabeza-${recibo.id}`}
                                    checked={conTalonarioArriba}
                                    onCheckedChange={setEncabezaTalonario}
                                    className="mt-0.5"
                                />
                                <div className="space-y-0.5">
                                    <Label
                                        htmlFor={`ver-encabeza-${recibo.id}`}
                                        className="text-xs font-normal"
                                    >
                                        Encabezar con el número del talonario
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        {conTalonarioArriba
                                            ? 'El del sistema queda abajo, como registro.'
                                            : 'El papel sale con el número del sistema.'}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/*
                         * Sobre el talonario se apaga el fondo: el papel ya
                         * trae el formulario y solo hay que poner los datos.
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

                    <DocumentFrame
                        url={urlVista}
                        titulo={titulo}
                        hojaAlto={HOJA_ALTO_A5}
                        creceHasta="62vh"
                        className="self-start"
                    />
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button variant="ghost" asChild>
                        <a href={urlVista} target="_blank" rel="noreferrer">
                            Abrir en una pestaña
                        </a>
                    </Button>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onCerrar}>
                            Cerrar
                        </Button>
                        <Button asChild>
                            <a
                                href={urlImpresion}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <Printer className="size-4" />
                                Imprimir
                            </a>
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
        <div className="flex justify-between gap-3">
            <dt className="text-xs text-field-label">{etiqueta}</dt>
            <dd>{children}</dd>
        </div>
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
                'flex-1 rounded px-2 py-1 transition-colors',
                activa
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted',
            )}
        >
            {etiqueta}
        </button>
    );
}
