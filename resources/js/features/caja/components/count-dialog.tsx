import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
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
import { composicionCompleta } from '@/features/caja/components/carry-composition';
import {
    businessToday,
    compareAmounts,
    date as formatDate,
    money,
    parseAmount,
    subtractAmounts,
    sumAmounts,
} from '@/lib/format';
import { store } from '@/routes/caja/arqueos';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;

/**
 * El recuento del fajo de días anteriores, cuando se lo abrió.
 *
 * Viaja aparte de las denominaciones del día —y no fundido con ellas—
 * para que el fajo conserve su composición y para que un faltante viejo
 * no se lea como un faltante de la recaudación de hoy.
 */
type RecuentoDelFajo = {
    reason: string;
    denominations: Record<string, number>;
};

type ConteoForm = {
    cashBoxId: number;
    countedOn: string;
    currency: string;
    denominations: Record<string, number>;
    explanation: string;
    carryRecount: RecuentoDelFajo | null;
};

/**
 * El formulario del arqueo: contar los billetes por denominación.
 *
 * Vive acá y no en la pantalla de Arqueos porque lo abren las dos: la
 * lista de arqueos y la caja del día, que es donde el cajero está parado
 * cuando termina la jornada. Un segundo formulario copiado se iría
 * separando del primero, y el arqueo es justamente donde no conviene tener
 * dos verdades.
 *
 * **La fecha llega por prop.** Es lo único que cambia entre las dos
 * pantallas: la lista arranca en el día de hoy y la caja del día, en el
 * día que se está mirando.
 */
export default function DialogoConteo({
    abierto,
    cerrar,
    cashBoxId,
    cajaNombre,
    currency,
    fecha,
    denominaciones,
    esperado = null,
    recaudacion = null,
    referenciaComposicion = null,
    fechaEditable = false,
    cajonMovido = false,
}: {
    abierto: boolean;
    cerrar: () => void;
    cashBoxId: number;
    cajaNombre: string;
    currency: string;
    /** El día que se está arqueando. */
    fecha: string;
    denominaciones: number[];
    /**
     * El saldo que el libro tiene para `fecha`, si se conoce.
     *
     * Con él la pantalla puede adelantar la diferencia que va a quedar,
     * antes de registrar. **Es información, no una meta**: el conteo no se
     * ajusta hasta que dé cero, y la revisión --que la hace otra persona--
     * sigue siendo donde la diferencia se explica o se imputa.
     */
    esperado?: string | null;
    /**
     * Lo que el libro dice que entró hoy y sigue en el cajón.
     *
     * Es contra esto que se compara el conteo: el saldo del día anterior
     * lo declara quien cuenta, así que **siempre puede elegir el número
     * que hace cuadrar**. Esta cifra no la elige nadie.
     */
    recaudacion?: string | null;
    /** Última foto completa anterior, disponible para consultar al recontar. */
    referenciaComposicion?: Arqueo | null;
    /**
     * Si el día se elige acá adentro.
     *
     * Apagado por omisión: casi siempre el diálogo se abre desde un día
     * concreto y la fecha viene con él. Solo la pantalla de Arqueos, donde
     * elegir el día **es** la acción, lo enciende.
     */
    fechaEditable?: boolean;
    /** Si la caja tuvo movimientos después del día que se está contando. */
    cajonMovido?: boolean;
}) {
    const hoy = businessToday();

    /* Solo molesta cuando el conteo es de un día anterior al de hoy. */
    const avisaCajonMovido = cajonMovido && fecha !== businessToday();

    const [abriendoRecuento, setAbriendoRecuento] = useState(false);

    /*
     * El arrastre sale del libro: lo que tiene que haber en el cajón menos
     * lo que entró hoy y sigue ahí.
     *
     * Con el campo cargado, la diferencia deja de poder acomodarse. Se
     * reduce a `contado − saldo de hoy`: mide el conteo contra lo que los
     * comprobantes del día dicen que entró, que es lo único que el arqueo
     * puede verificar de verdad.
     *
     * El servidor repite este cálculo y es quien decide el importe guardado:
     * el navegador solo lo adelanta para que el cajero vea el resultado.
     */
    const arrastreDelLibro =
        esperado !== null && recaudacion !== null
            ? subtractAmounts(esperado, recaudacion)
            : '';

    /*
     * Sin el saldo del libro —otra fecha, una pantalla que no lo manda— no
     * se inventa un valor: el servidor lo calculará al registrar.
     */
    const loCalculaElLibro = arrastreDelLibro !== '';

    const form = useForm<ConteoForm>({
        cashBoxId,
        countedOn: fecha,
        currency,
        denominations: {},
        explanation: '',
        /*
         * El fajo del día anterior, si se abrió y se contó. Se carga en
         * su propia ventana: no hay un tercer camino —o se confía en el
         * cálculo o se cuenta—, porque escribir a mano un número que
         * nadie contó no es corregir, es adivinar mejor.
         */
        carryRecount: null,
    });

    const recuento = form.data.carryRecount;

    /*
     * Los errores del recuento llegan con clave anidada y `useForm` tipa
     * las suyas por campo del formulario. Se leen por nombre, igual que en
     * el cuadro de cuotas.
     */
    const errores = form.errors as Record<string, string | undefined>;

    /*
     * El total se calcula en enteros de centavos. Las denominaciones son
     * pesos enteros y las cantidades también, así que el producto es
     * exacto sin tocar punto flotante.
     */
    const total = useMemo(() => {
        const centavos = Object.entries(form.data.denominations).reduce(
            (acumulado, [denominacion, cantidad]) =>
                acumulado + BigInt(denominacion) * BigInt(cantidad || 0) * 100n,
            0n,
        );

        const texto = centavos.toString().padStart(3, '0');

        return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
    }, [form.data.denominations]);

    /*
     * Las tres líneas del reverso de la planilla, con sus nombres.
     *
     * Lo que el sistema compara contra el libro es el **total en el
     * cajón**, no lo contado: el arrastre suma. Mostrando solo lo contado,
     * un arqueo que cuadraba podía terminar con un sobrante del tamaño del
     * arrastre sin que nada lo anticipara en pantalla.
     *
     * La fila del arrastre va siempre, aunque dé cero, igual que en el
     * papel: es una fila fija del formulario.
     */
    const arrastre =
        recuento === null ? parseAmount(arrastreDelLibro) || '0.00' : '0.00';

    /*
     * Los billetes del fajo están en el cajón igual que los del día, así
     * que suman al total. Lo que cambia es que se sabe cuáles son.
     */
    const contadoDelFajo =
        recuento === null ? null : totalDe(recuento.denominations);

    const enElCajon = sumAmounts([total, contadoDelFajo ?? '0.00', arrastre]);

    /** Lo que faltó en el fajo: encontrado contra lo que el libro decía. */
    const faltanteDelFajo =
        contadoDelFajo === null
            ? null
            : subtractAmounts(contadoDelFajo, arrastreDelLibro || '0.00');

    /*
     * El esperado vino calculado para `fecha`. En la pantalla de Arqueos
     * el día se elige acá adentro, así que en cuanto se mueve deja de
     * corresponder: mostrar esa diferencia sería mostrar una cuenta contra
     * el libro de otro día.
     */
    const esperadoAplica = esperado !== null && form.data.countedOn === fecha;
    const diferencia = esperadoAplica
        ? subtractAmounts(enElCajon, esperado)
        : null;
    const diferenciaDelDia =
        recaudacion !== null && esperadoAplica
            ? subtractAmounts(total, recaudacion)
            : null;
    const hayConteoDelDia = Object.values(form.data.denominations).some(
        (cantidad) => cantidad > 0,
    );
    const recuentoCompletoCuadra =
        recuento !== null &&
        diferencia !== null &&
        compareAmounts(diferencia, '0.00') === 0;

    const mostrarExplicacion =
        (hayConteoDelDia &&
            !recuentoCompletoCuadra &&
            (diferenciaDelDia === null ||
                compareAmounts(diferenciaDelDia, '0.00') !== 0)) ||
        form.errors.explanation !== undefined ||
        form.data.explanation.trim() !== '';

    const setCantidad = (denominacion: number, valor: string) => {
        const cantidad = Number.parseInt(valor, 10);

        form.setData('denominations', {
            ...form.data.denominations,
            [denominacion]:
                Number.isFinite(cantidad) && cantidad > 0 ? cantidad : 0,
        });
    };

    return (
        <Dialog
            open={abierto}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    cerrar();
                }
            }}
        >
            {/*
             * Este diálogo scrollea adentro suyo, al revés que el resto.
             *
             * El formulario crece: recontar el fajo suma otro juego de
             * denominaciones, y con diez denominaciones en pantalla ya no
             * entra en una notebook. Creciendo hacia abajo, «Registrar
             * arqueo» quedaba fuera de la ventana y no había forma de llegar:
             * el scroll de la página no corre mientras el modal está abierto.
             *
             * Con el alto acotado, lo que scrollea es el formulario y el
             * pie queda siempre a la vista. En un diálogo que confirma un
             * arqueo, el botón no puede depender de cuánto mide la
             * pantalla.
             */}
            <DialogContent className="flex flex-col overflow-y-hidden sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Arqueo de caja</DialogTitle>
                    <DialogDescription>
                        Contá los billetes por denominación. El total lo calcula
                        el sistema y lo compara con el libro.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-arqueo"
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
                    className="grid min-h-0 flex-1 gap-4 overflow-y-auto pr-1"
                >
                    {/*
                     * El cajón de ahora ya no es el de esa jornada.
                     *
                     * No es un aviso sobre la aritmética --el sistema no
                     * mezcla días, y la pantalla ya muestra los
                     * movimientos del día que se cuenta-- sino sobre la
                     * plata física: los billetes son fungibles y ya están
                     * mezclados con los que entraron después, así que el
                     * conteo del 14 no se puede rehacer hoy. Lo único
                     * válido es cargar el que se hizo ese día.
                     *
                     * El texto dice qué cargar, no «que coincida»: pedir
                     * exactitud contra el libro sería pedir que se fuerce
                     * el número, y un arqueo que se fuerza no controla
                     * nada.
                     */}
                    {avisaCajonMovido && (
                        <p className="flex gap-2 rounded-lg border border-warning-soft bg-warning-soft px-3 py-2.5 text-sm text-warning-strong">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>
                                Cargá el conteo que se hizo al cierre del{' '}
                                {formatDate(form.data.countedOn)}, no lo que hay
                                en el cajón ahora: la caja se movió después de
                                esa fecha.
                            </span>
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="countedOn">Fecha del arqueo</Label>
                            {/*
                             * Abierto desde un día, la fecha es un dato, no
                             * una decisión: dejarla editable permitía abrir
                             * el 15, cambiarla al 14 y que el conteo
                             * aterrizara en un día que no se está mirando.
                             * Elegir el día es lo que se hace en la pantalla
                             * de Arqueos, y solo ahí se edita.
                             */}
                            {fechaEditable ? (
                                <>
                                    <Input
                                        id="countedOn"
                                        type="date"
                                        max={hoy}
                                        value={form.data.countedOn}
                                        onChange={(e) =>
                                            form.setData(
                                                'countedOn',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.countedOn}
                                    />
                                </>
                            ) : (
                                <>
                                    {/*
                                     * Se muestra el día en que se carga y,
                                     * al lado, para qué jornada es.
                                     *
                                     * Con la fecha del día contado sola,
                                     * cargar hoy el arqueo de ayer se leía
                                     * como si el conteo se hubiera hecho
                                     * ayer. Son dos hechos distintos y la
                                     * base guarda los dos: `counted_at` es
                                     * cuándo se registró, `counted_on` a
                                     * qué jornada pertenece.
                                     */}
                                    <p className="flex h-9 items-center text-sm text-muted-foreground">
                                        {formatDate(hoy)}
                                        {form.data.countedOn !== hoy && (
                                            <span className="ml-1 font-medium text-foreground">
                                                (corresponde al día{' '}
                                                {formatDate(
                                                    form.data.countedOn,
                                                )}
                                                )
                                            </span>
                                        )}
                                    </p>
                                    <InputError
                                        message={form.errors.countedOn}
                                    />
                                </>
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label>Caja</Label>
                            <p className="flex h-9 items-center text-sm text-muted-foreground">
                                {cajaNombre}
                            </p>
                            <InputError message={form.errors.cashBoxId} />
                        </div>
                    </div>

                    <div className="rounded-lg border">
                        <TablaDeBilletes
                            denominaciones={denominaciones}
                            cantidades={form.data.denominations}
                            onChange={setCantidad}
                        />

                        <div className="grid gap-1 border-t bg-muted/40 px-3 py-2">
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm">
                                    Recaudación del día
                                </span>
                                <Money value={total} className="text-sm" />
                            </div>

                            {/*
                             * Lo que el libro espera de la jornada, al lado
                             * de lo que se contó. Es la única comparación
                             * que el operador no puede acomodar: el arrastre
                             * lo declara él, esto no.
                             */}
                            {recaudacion !== null && esperadoAplica && (
                                <div className="flex items-baseline justify-between">
                                    <span className="text-sm text-muted-foreground">
                                        Saldo de hoy
                                    </span>
                                    <Money
                                        value={recaudacion}
                                        className="text-sm"
                                        dimWhenZero
                                    />
                                </div>
                            )}
                        </div>
                    </div>
                    <InputError message={form.errors.denominations} />

                    {/*
                     * El renglón que la planilla del área llama «SALDO DIA
                     * ANTERIOR»: la plata vieja del cajón que no se recuenta.
                     * Arriba se cuenta billete por billete lo que entró en el
                     * día, acá va el resto.
                     *
                     * A la vista y no plegado: en junio de 2026 se usó los
                     * veinte días, y en cuatro de ellos fue el cajón entero.
                     * Esconder detrás de un acordeón lo que se llena todas
                     * las tardes es esconder el arqueo.
                     */}
                    <div className="grid gap-2 rounded-lg border px-3 py-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <Label
                                htmlFor="uncountedAmount"
                                className="text-sm font-medium"
                            >
                                {recuento === null
                                    ? 'Saldo del día anterior (no recontado)'
                                    : 'Saldo del día anterior (recontado)'}
                            </Label>

                            {loCalculaElLibro && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (recuento !== null) {
                                            form.setData({
                                                ...form.data,
                                                carryRecount: null,
                                            });

                                            return;
                                        }

                                        setAbriendoRecuento(true);
                                    }}
                                >
                                    {recuento !== null
                                        ? 'Deshacer el recuento'
                                        : 'Recontar'}
                                </Button>
                            )}
                        </div>

                        <Input
                            id="uncountedAmount"
                            inputMode="decimal"
                            placeholder="0.00"
                            readOnly
                            className="bg-muted/50 text-right font-mono text-muted-foreground tabular-nums"
                            value={
                                recuento === null ? arrastreDelLibro : '0.00'
                            }
                        />

                        {recuento !== null && (
                            <div className="grid gap-1 rounded-md bg-muted/40 px-3 py-2">
                                <div className="flex items-baseline justify-between">
                                    <span className="text-sm text-muted-foreground">
                                        Encontrado en el fajo
                                    </span>
                                    <Money
                                        value={contadoDelFajo ?? '0.00'}
                                        className="text-sm"
                                    />
                                </div>
                                <div className="flex items-baseline justify-between">
                                    <span className="text-sm text-muted-foreground">
                                        Según el libro
                                    </span>
                                    <Money
                                        value={arrastreDelLibro || '0.00'}
                                        className="text-sm"
                                    />
                                </div>
                                <div className="flex items-baseline justify-between border-t pt-1">
                                    <span className="text-sm font-medium">
                                        Diferencia en el fajo
                                    </span>
                                    <Money
                                        value={faltanteDelFajo ?? '0.00'}
                                        className="text-sm font-semibold"
                                        dimWhenZero
                                    />
                                </div>
                                <p className="pt-1 text-xs text-muted-foreground">
                                    {recuento.reason}
                                </p>
                            </div>
                        )}

                        <InputError message={errores['carryRecount.reason']} />
                    </div>

                    {/*
                     * El resultado va después de las dos cosas que lo
                     * producen. Arriba estaba antes del campo del arrastre:
                     * se escribía el número y había que volver a subir para
                     * ver qué efecto tuvo.
                     */}
                    <div className="grid gap-1 rounded-lg border bg-muted/40 px-3 py-2">
                        <div className="flex items-baseline justify-between">
                            <span className="text-sm font-medium">
                                Total en el cajón
                            </span>
                            <Money
                                value={enElCajon}
                                className="text-base font-semibold"
                            />
                        </div>

                        {diferencia !== null && (
                            <div className="flex items-baseline justify-between border-t pt-1">
                                <span className="text-sm text-muted-foreground">
                                    Diferencia contra el libro
                                </span>
                                <Money
                                    value={diferencia}
                                    className="text-sm"
                                    dimWhenZero
                                />
                            </div>
                        )}

                        {esperado !== null && !esperadoAplica && (
                            <p className="text-xs text-muted-foreground">
                                La diferencia se calcula al registrar: el día
                                elegido no es el que trajo la pantalla.
                            </p>
                        )}
                    </div>

                    {mostrarExplicacion && (
                        <div className="grid gap-2">
                            <Label htmlFor="explanation">
                                Explicación de la diferencia
                            </Label>
                            <Textarea
                                id="explanation"
                                rows={2}
                                placeholder="Qué ocurrió y qué medida se tomó."
                                value={form.data.explanation}
                                onChange={(e) =>
                                    form.setData('explanation', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Este campo explica únicamente la diferencia de
                                la recaudación del día.
                            </p>
                            <InputError message={form.errors.explanation} />
                        </div>
                    )}
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-arqueo"
                        disabled={form.processing}
                    >
                        Registrar arqueo
                    </Button>
                </DialogFooter>
            </DialogContent>

            {abriendoRecuento && (
                <DialogoRecuento
                    denominaciones={denominaciones}
                    declarado={arrastreDelLibro || '0.00'}
                    referenciaComposicion={referenciaComposicion}
                    cerrar={() => setAbriendoRecuento(false)}
                    confirmar={(recontado) => {
                        form.setData({
                            ...form.data,
                            carryRecount: recontado,
                        });
                        setAbriendoRecuento(false);
                    }}
                />
            )}
        </Dialog>
    );
}

/** El total de un mapa de denominaciones, en centavos enteros. */
function totalDe(cantidades: Record<string, number>): string {
    const centavos = Object.entries(cantidades).reduce(
        (acumulado, [denominacion, cantidad]) =>
            acumulado + BigInt(denominacion) * BigInt(cantidad || 0) * 100n,
        0n,
    );

    const texto = centavos.toString().padStart(3, '0');

    return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/** El cuadro de billetes: un renglón por denominación, con su subtotal. */
function TablaDeBilletes({
    denominaciones,
    cantidades,
    onChange,
}: {
    denominaciones: number[];
    cantidades: Record<string, number>;
    onChange: (denominacion: number, valor: string) => void;
}) {
    return (
        <>
            <div className="grid grid-cols-[1fr_6rem_1fr] gap-2 border-b bg-muted/50 px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <span>Billete</span>
                <span className="text-center">Cantidad</span>
                <span className="text-right">Subtotal</span>
            </div>

            <div className="max-h-72 overflow-y-auto">
                {denominaciones.map((denominacion) => {
                    const cantidad = cantidades[denominacion] ?? 0;

                    return (
                        <div
                            key={denominacion}
                            className="grid grid-cols-[1fr_6rem_1fr] items-center gap-2 border-b px-3 py-1.5 last:border-b-0"
                        >
                            <span className="font-mono text-sm tabular-nums">
                                {money(`${denominacion}.00`)}
                            </span>
                            <Input
                                type="number"
                                min={0}
                                inputMode="numeric"
                                className="h-8 text-center"
                                value={cantidad || ''}
                                onChange={(e) =>
                                    onChange(denominacion, e.target.value)
                                }
                                aria-label={`Cantidad de billetes de ${denominacion}`}
                            />
                            <span className="text-right">
                                <Money
                                    value={`${denominacion * cantidad}.00`}
                                    dimWhenZero
                                />
                            </span>
                        </div>
                    );
                })}
            </div>
        </>
    );
}

/**
 * Abrir el fajo del día anterior y contarlo.
 *
 * Va en su propia ventana para que no se confunda con el conteo de la
 * jornada: son dos actos distintos y el de arriba es el de todos los días.
 * Acá se entra cuando no se le cree al saldo calculado —sospecha de un
 * faltante, un recuento periódico— y lo que se busca es justamente ver
 * **qué billetes faltan**, no un número.
 *
 * Al confirmar, el saldo anterior pasa a cero —deja de haber algo «no
 * recontado», porque se recontó— y el recuento queda guardado aparte, con
 * su motivo, sus observaciones y sus billetes.
 *
 * **El motivo es obligatorio.** No es el campo que sacamos hace poco: aquel
 * pedía justificar lo que se hacía todas las tardes y terminaba lleno de
 * cualquier cosa. Este se escribe una vez cada tanto, y es lo único que
 * explica por qué ese día alguien abrió el fondo histórico y, si hubo una
 * diferencia, permite dejar la observación en este mismo lugar.
 */
function DialogoRecuento({
    denominaciones,
    declarado,
    referenciaComposicion,
    cerrar,
    confirmar,
}: {
    denominaciones: number[];
    /** Lo que el libro dice que hay en el fajo. */
    declarado: string;
    referenciaComposicion: Arqueo | null;
    cerrar: () => void;
    confirmar: (recuento: RecuentoDelFajo) => void;
}) {
    const [cantidades, setCantidades] = useState<Record<string, number>>({});
    const [motivo, setMotivo] = useState('');

    const contado = totalDe(cantidades);
    const diferencia = subtractAmounts(contado, declarado);

    return (
        <Dialog open onOpenChange={(abierto) => abierto || cerrar()}>
            <DialogContent className="flex flex-col overflow-y-hidden sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Recuento del saldo anterior</DialogTitle>
                    <DialogDescription>
                        Contá el fajo que viene de días anteriores. Se suma al
                        conteo del día y el saldo deja de declararse.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid min-h-0 flex-1 gap-4 overflow-y-auto pr-1">
                    <div className="rounded-lg border">
                        <TablaDeBilletes
                            denominaciones={denominaciones}
                            cantidades={cantidades}
                            onChange={(denominacion, valor) => {
                                const cantidad = Number.parseInt(valor, 10);

                                setCantidades({
                                    ...cantidades,
                                    [denominacion]:
                                        Number.isFinite(cantidad) &&
                                        cantidad > 0
                                            ? cantidad
                                            : 0,
                                });
                            }}
                        />

                        <div className="grid gap-1 border-t bg-muted/40 px-3 py-2">
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm">Contado</span>
                                <Money value={contado} className="text-sm" />
                            </div>
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm text-muted-foreground">
                                    Según el libro
                                </span>
                                <Money value={declarado} className="text-sm" />
                            </div>
                            <div className="flex items-baseline justify-between border-t pt-1">
                                <span className="text-sm font-medium">
                                    Diferencia en el fajo
                                </span>
                                <Money
                                    value={diferencia}
                                    className="text-base font-semibold"
                                    dimWhenZero
                                />
                            </div>
                        </div>
                    </div>

                    {referenciaComposicion !== null && (
                        <ConteoCompletoAnterior
                            arqueo={referenciaComposicion}
                        />
                    )}
                    {referenciaComposicion === null && (
                        <p className="rounded-lg border px-3 py-2 text-xs text-muted-foreground">
                            Todavía no hay un conteo completo anterior para
                            consultar sus billetes.
                        </p>
                    )}

                    <div className="grid gap-2">
                        <Label htmlFor="motivo-recuento">
                            Motivo u observación del recuento
                        </Label>
                        <Textarea
                            id="motivo-recuento"
                            rows={2}
                            placeholder="Verificación periódica; se encontró un faltante y se labró acta…"
                            value={motivo}
                            onChange={(e) => setMotivo(e.target.value)}
                        />
                        <p className="text-xs text-muted-foreground">
                            Indicá por qué se abrió el fajo. Si hubo una
                            diferencia, agregá acá qué se encontró o qué medida
                            se tomó.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" type="button" onClick={cerrar}>
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        disabled={motivo.trim() === ''}
                        onClick={() =>
                            confirmar({
                                reason: motivo.trim(),
                                denominations: cantidades,
                            })
                        }
                    >
                        Usar este recuento
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ConteoCompletoAnterior({ arqueo }: { arqueo: Arqueo }) {
    const lineas = composicionCompleta(arqueo);
    const totalBilletes = lineas.reduce(
        (total, linea) => total + linea.quantity,
        0,
    );

    return (
        <details className="rounded-lg border px-3 py-2 text-sm">
            <summary className="cursor-pointer font-medium">
                Ver último conteo completo ({formatDate(arqueo.countedOn)})
            </summary>
            <p className="mt-2 text-xs text-muted-foreground">
                Es el total del cajón contado en esa fecha. Los movimientos
                posteriores pueden haber cambiado estos billetes; usalo como
                antecedente, no como cantidad esperada hoy.
            </p>
            <table className="mt-3 w-full text-sm">
                <thead>
                    <tr className="text-xs text-muted-foreground">
                        <th className="text-left font-normal">Billete</th>
                        <th className="text-right font-normal">Cantidad</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {lineas.map((linea) => (
                        <tr key={linea.denomination}>
                            <td className="py-1">
                                <Money value={linea.denomination} />
                            </td>
                            <td className="py-1 text-right tabular-nums">
                                {linea.quantity}
                            </td>
                        </tr>
                    ))}
                </tbody>
                <tfoot>
                    <tr className="border-t font-medium">
                        <th scope="row" className="pt-2 text-left">
                            Total de billetes
                        </th>
                        <td className="pt-2 text-right tabular-nums">
                            {totalBilletes}
                        </td>
                    </tr>
                </tfoot>
            </table>
            <p className="mt-2 text-right text-xs text-muted-foreground">
                Importe contado: <Money value={arqueo.countedAmount} />
            </p>
        </details>
    );
}
