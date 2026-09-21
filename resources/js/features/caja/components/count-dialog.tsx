import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useMemo } from 'react';
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
import {
    businessToday,
    date as formatDate,
    money,
    parseAmount,
    subtractAmounts,
    sumAmounts,
} from '@/lib/format';
import { store } from '@/routes/caja/arqueos';

type ConteoForm = {
    cashBoxId: number;
    countedOn: string;
    currency: string;
    denominations: Record<string, number>;
    uncountedAmount: string;
    uncountedReason: string;
    explanation: string;
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

    const form = useForm<ConteoForm>({
        cashBoxId,
        countedOn: fecha,
        currency,
        denominations: {},
        uncountedAmount: '',
        uncountedReason: '',
        explanation: '',
    });

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
    const arrastre = parseAmount(form.data.uncountedAmount) || '0.00';
    const enElCajon = sumAmounts([total, arrastre]);

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
             * El formulario crece: desplegar «¿Quedó algo sin recontar?»
             * le suma dos campos, y con diez denominaciones en pantalla ya
             * no entra en una notebook. Creciendo hacia abajo, «Registrar
             * arqueo» quedaba fuera de la ventana y no había forma de
             * llegar: el scroll de la página no corre mientras el modal
             * está abierto.
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
                        <div className="grid grid-cols-[1fr_6rem_1fr] gap-2 border-b bg-muted/50 px-3 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            <span>Billete</span>
                            <span className="text-center">Cantidad</span>
                            <span className="text-right">Subtotal</span>
                        </div>

                        <div className="max-h-72 overflow-y-auto">
                            {denominaciones.map((denominacion) => {
                                const cantidad =
                                    form.data.denominations[denominacion] ?? 0;

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
                                                setCantidad(
                                                    denominacion,
                                                    e.target.value,
                                                )
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

                        <div className="grid gap-1 border-t bg-muted/40 px-3 py-2">
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm">
                                    Recaudación del día
                                </span>
                                <Money value={total} className="text-sm" />
                            </div>
                            <div className="flex items-baseline justify-between">
                                <span className="text-sm text-muted-foreground">
                                    Saldo del día anterior
                                </span>
                                <Money
                                    value={arrastre}
                                    className="text-sm"
                                    dimWhenZero
                                />
                            </div>
                            <div className="flex items-baseline justify-between border-t pt-1">
                                <span className="text-sm font-medium">
                                    Total en el cajón
                                </span>
                                <Money
                                    value={enElCajon}
                                    className="text-base font-semibold"
                                />
                            </div>

                            {diferencia !== null && (
                                <div className="flex items-baseline justify-between">
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
                                    La diferencia se calcula al registrar: el
                                    día elegido no es el que trajo la pantalla.
                                </p>
                            )}
                        </div>
                    </div>
                    <InputError message={form.errors.denominations} />

                    {/*
                     * El renglón que la planilla del área llama «SALDO DIA
                     * ANTERIOR».
                     *
                     * Es la plata vieja que quedó en el cajón y que no se
                     * recuenta: arriba se cuenta billete por billete lo que
                     * entró en el día, acá se declara el resto. En junio de
                     * 2026 se usó los veinte días, y en cuatro de ellos fue
                     * el cajón entero.
                     *
                     * **Nace vacío y sin precarga.** El arrastre cambia casi
                     * todos los días porque casi todos los días sale plata:
                     * en ese mes repitió el del día anterior 2 de 19 veces.
                     * Sugerir un número que acierta una de cada diez es peor
                     * que no sugerir ninguno, porque invita a aceptarlo.
                     */}
                    <details className="rounded-lg border px-3 py-2">
                        <summary className="cursor-pointer text-sm font-medium">
                            Saldo del día anterior (no recontado)
                        </summary>

                        <div className="mt-3 grid gap-3">
                            <p className="text-xs text-muted-foreground">
                                La plata vieja del cajón que no se recuenta.
                                <strong className="font-medium text-foreground">
                                    {' '}
                                    Suma al total
                                </strong>
                                , así que declarar de más acá aparece como un
                                sobrante contra el libro. Es el renglón que la
                                planilla llama «SALDO DIA ANTERIOR».
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="uncountedAmount">
                                    Saldo del día anterior
                                </Label>
                                <Input
                                    id="uncountedAmount"
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={form.data.uncountedAmount}
                                    onChange={(e) =>
                                        form.setData(
                                            'uncountedAmount',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.uncountedAmount}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="uncountedReason">
                                    Por qué no se recontó
                                </Label>
                                <Textarea
                                    id="uncountedReason"
                                    rows={2}
                                    value={form.data.uncountedReason}
                                    onChange={(e) =>
                                        form.setData(
                                            'uncountedReason',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.uncountedReason}
                                />
                            </div>
                        </div>
                    </details>

                    <div className="grid gap-2">
                        <Label htmlFor="explanation">
                            Explicación de la diferencia
                        </Label>
                        <Textarea
                            id="explanation"
                            rows={2}
                            placeholder="Obligatoria solo si el conteo no coincide con el libro."
                            value={form.data.explanation}
                            onChange={(e) =>
                                form.setData('explanation', e.target.value)
                            }
                        />
                        <InputError message={form.errors.explanation} />
                    </div>
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
        </Dialog>
    );
}
