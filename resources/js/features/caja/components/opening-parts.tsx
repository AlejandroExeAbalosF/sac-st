import { CheckCircle2, Plus, Trash2, TriangleAlert } from 'lucide-react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { date as formatDate } from '@/lib/format';

/*
 * Las piezas de la apertura de un libro que comparten las cajas: Haberes
 * (`caja/apertura`) y Multas (`multas/caja`). La regla es una sola
 * —`RegisterOpeningBalance`—, así que el formulario también.
 */

/** Un cheque del cajón al abrir los libros, como lo lista el reverso. */
export type Cheque = {
    number: string;
    bank: string;
    issueDate: string;
    amount: string;
    expediente: string;
    company: string;
    beneficiary: string;
};

export const CHEQUE_VACIO: Cheque = {
    number: '',
    bank: '',
    issueDate: '',
    amount: '',
    expediente: '',
    company: '',
    beneficiary: '',
};

/** Los datos del cheque que declara quien abre y que cada caja nombra a su modo. */
export type CampoOpcional = {
    campo: 'expediente' | 'company' | 'beneficiary';
    etiqueta: string;
};

const CAMPOS_DE_HABERES: CampoOpcional[] = [
    { campo: 'expediente', etiqueta: 'Expediente' },
    { campo: 'company', etiqueta: 'Empresa' },
    { campo: 'beneficiary', etiqueta: 'Beneficiario' },
];

export type AperturaExistente = {
    date: string;
    postedAt: string | null;
    description: string | null;
    /** Se abrió declarando que no había nada en esta moneda. */
    isEmpty: boolean;
    lines: { account: string; label: string; amount: string }[];
};

/**
 * El total de los billetes con un renglón cambiado.
 *
 * Se calcula sobre el mapa que va a quedar, no sobre el que hay: dentro de
 * un `setData` el estado todavía es el anterior, y el efectivo saldría un
 * billete atrasado.
 */
export function contadoCon(
    cantidades: Record<string, number>,
    denominacion: number,
    cantidad: number,
): string {
    const conElCambio: Record<string, number> = { ...cantidades };
    conElCambio[denominacion] = cantidad;

    return totalDeBilletes(conElCambio);
}

/** Lo que suman los billetes contados, sin pasar por `number`. */
export function totalDeBilletes(cantidades: Record<string, number>): string {
    const centavos = Object.entries(cantidades).reduce(
        (acumulado, [billete, cuantos]) =>
            acumulado + BigInt(billete) * BigInt(cuantos || 0) * 100n,
        0n,
    );

    const texto = centavos.toString().padStart(3, '0');

    return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/**
 * Suma dos importes decimales sin pasar por `number`.
 *
 * La misma regla que rige del lado de PHP: el punto flotante no existe en
 * ningún punto de la pila. Un importe a medio tipear —`12.` o vacío— suma
 * cero en vez de romper el total mientras se escribe.
 */
export function sumar(a: string, b: string): string {
    const centavos = (valor: string) => {
        const limpio = (valor ?? '').trim();

        if (limpio === '' || !/^\d*\.?\d*$/.test(limpio)) {
            return 0n;
        }

        const [entero = '0', decimal = ''] = limpio.split('.');

        return (
            BigInt(entero || '0') * 100n +
            BigInt((decimal + '00').slice(0, 2) || '0')
        );
    };

    const total = (centavos(a) + centavos(b)).toString().padStart(3, '0');

    return `${total.slice(0, -2)}.${total.slice(-2)}`;
}

/**
 * Contar el cajón, billete por billete.
 *
 * El efectivo de la apertura sale de acá: es la única vez que se cuenta, y
 * lo que le da composición al fajo que después se arrastra sin recontar.
 */
export function ConteoDeBilletes({
    billetes,
    cantidades,
    onContar,
}: {
    billetes: number[];
    cantidades: Record<string, number>;
    onContar: (denominacion: number, valor: string) => void;
}) {
    return (
        <div className="overflow-hidden rounded-lg border">
            <div className="border-b px-4 py-3">
                <p className="text-sm font-medium">Billetes en el cajón</p>
                <p className="text-xs text-muted-foreground">
                    De acá sale el efectivo declarado arriba.
                </p>
            </div>

            <div className="grid grid-cols-[1fr_6rem_1fr] gap-2 border-b bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                <span>Billete</span>
                <span className="text-center">Cantidad</span>
                <span className="text-right">Subtotal</span>
            </div>

            <div className="max-h-72 overflow-y-auto">
                {billetes.map((billete) => {
                    const cantidad = cantidades[billete] ?? 0;

                    return (
                        <div
                            key={billete}
                            className="grid grid-cols-[1fr_6rem_1fr] items-center gap-2 border-b px-4 py-1.5 last:border-b-0"
                        >
                            <Money value={`${billete}.00`} />
                            <Input
                                type="number"
                                min={0}
                                inputMode="numeric"
                                className="h-8 text-center"
                                value={cantidad || ''}
                                onChange={(e) =>
                                    onContar(billete, e.target.value)
                                }
                                aria-label={`Cantidad de billetes de ${billete}`}
                            />
                            <span className="text-right">
                                <Money
                                    value={`${billete * cantidad}.00`}
                                    dimWhenZero
                                />
                            </span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

/**
 * Los cheques del cajón, uno por renglón.
 *
 * El saldo dice cuánto valen; esto dice cuáles son. Es lo único que
 * permite cotejarlos contra el papel, y lo que el reverso de la planilla
 * lista: un cheque puede quedar años en custodia, y sin estos datos
 * aparecería como un importe anónimo en cada planilla mensual hasta que
 * se cobre.
 *
 * Los datos opcionales son **lo que declara quien abre los libros**, no un
 * dato verificado: el sistema no conoce ese expediente. Cada caja los
 * nombra a su modo (`campos`).
 *
 * El total se compara contra el saldo declarado a la vista. Son dos formas
 * de decir lo mismo y el Action exige que coincidan; mostrarlo acá evita
 * que el operador se entere recién al enviar.
 */
export function CarteraDeCheques({
    cheques,
    declarado,
    errores,
    onChange,
    campos = CAMPOS_DE_HABERES,
}: {
    cheques: Cheque[];
    declarado: string;
    errores: Record<string, string>;
    onChange: (cheques: Cheque[]) => void;
    campos?: CampoOpcional[];
}) {
    const total = cheques.reduce(
        (acumulado, cheque) => sumar(acumulado, cheque.amount),
        '0.00',
    );

    const cuadra = sumar(total, '0.00') === sumar(declarado, '0.00');

    const editar = (indice: number, campo: keyof Cheque, valor: string) =>
        onChange(
            cheques.map((cheque, i) =>
                i === indice ? { ...cheque, [campo]: valor } : cheque,
            ),
        );

    const clave = (indice: number, campo: string) =>
        errores['cheques.' + indice + '.' + campo];

    return (
        <div className="grid gap-3 rounded-lg border p-4">
            <div>
                <p className="text-sm font-medium">Cheques en cartera</p>
                <p className="text-xs text-muted-foreground">
                    Uno por renglón, como los lista el reverso de la planilla.
                    Detallarlos es opcional: sin detalle el inventario arranca
                    vacío y el libro solo sabe cuánto valen.
                </p>
            </div>

            {cheques.map((cheque, indice) => (
                <div key={indice} className="grid gap-2 rounded-md border p-3">
                    <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
                        <Campo
                            etiqueta="Número"
                            valor={cheque.number}
                            error={clave(indice, 'number')}
                            onChange={(v) => editar(indice, 'number', v)}
                        />
                        <Campo
                            etiqueta="Banco"
                            valor={cheque.bank}
                            error={clave(indice, 'bank')}
                            onChange={(v) => editar(indice, 'bank', v)}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="self-end"
                            aria-label={'Quitar el cheque ' + (indice + 1)}
                            onClick={() =>
                                onChange(cheques.filter((_, i) => i !== indice))
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        <Campo
                            etiqueta="Emisión"
                            tipo="date"
                            valor={cheque.issueDate}
                            error={clave(indice, 'issueDate')}
                            onChange={(v) => editar(indice, 'issueDate', v)}
                        />
                        <Campo
                            etiqueta="Importe"
                            importe
                            valor={cheque.amount}
                            error={clave(indice, 'amount')}
                            onChange={(v) => editar(indice, 'amount', v)}
                        />
                    </div>

                    {campos.length > 0 && (
                        <div
                            className={
                                campos.length === 3
                                    ? 'grid gap-2 sm:grid-cols-3'
                                    : 'grid gap-2 sm:grid-cols-2'
                            }
                        >
                            {campos.map(({ campo, etiqueta }) => (
                                <Campo
                                    key={campo}
                                    etiqueta={etiqueta}
                                    valor={cheque[campo]}
                                    error={clave(indice, campo)}
                                    onChange={(v) => editar(indice, campo, v)}
                                />
                            ))}
                        </div>
                    )}
                </div>
            ))}

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...cheques, { ...CHEQUE_VACIO }])}
                >
                    <Plus className="size-4" />
                    Agregar cheque
                </Button>

                {cheques.length > 0 && (
                    <p className="text-xs text-muted-foreground">
                        Detallado <Money value={total} /> de{' '}
                        <Money value={declarado} />
                        {!cuadra && (
                            <span className="ml-1 font-medium text-destructive-strong">
                                — tienen que coincidir
                            </span>
                        )}
                    </p>
                )}
            </div>
        </div>
    );
}

/** Un campo del renglón del cheque, con su rótulo y su error. */
function Campo({
    etiqueta,
    valor,
    error,
    onChange,
    tipo = 'text',
    importe = false,
}: {
    etiqueta: string;
    valor: string;
    error?: string;
    onChange: (valor: string) => void;
    tipo?: string;
    importe?: boolean;
}) {
    return (
        <div className="grid gap-1">
            <Label className="text-xs font-normal text-muted-foreground">
                {etiqueta}
            </Label>
            {importe ? (
                <AmountInput
                    value={valor}
                    onChange={onChange}
                    aria-label={etiqueta}
                    aria-invalid={Boolean(error)}
                />
            ) : (
                <Input
                    type={tipo}
                    value={valor}
                    onChange={(e) => onChange(e.target.value)}
                />
            )}
            <InputError message={error} />
        </div>
    );
}

export function AdvertenciaDeApertura() {
    return (
        <div className="flex gap-3 rounded-lg border border-warning-soft bg-warning-soft p-4 text-warning-strong">
            <TriangleAlert className="mt-0.5 size-5 shrink-0" />
            <div className="space-y-1 text-sm">
                <p className="font-medium">Esto se hace una sola vez.</p>
                <p>
                    Define todos los saldos posteriores: contra este número se
                    compara cada arqueo y cada cierre. Hasta que no se haga, el
                    libro de esta moneda no admite movimientos. Una vez
                    registrada no se edita ni se vuelve a cargar: revisala antes
                    de confirmar.
                </p>
            </div>
        </div>
    );
}

export function AperturaHecha({ existing }: { existing: AperturaExistente }) {
    return (
        <div className="overflow-hidden rounded-lg border bg-card">
            <div className="flex items-start gap-3 border-b bg-success-soft p-4 text-success-strong">
                <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                <div className="text-sm">
                    <p className="font-medium">
                        Esta caja ya tiene sus libros abiertos.
                    </p>
                    <p>
                        Con fecha {formatDate(existing.date)}
                        {existing.isEmpty
                            ? ', sin saldo: se declaró que no había nada en esta moneda.'
                            : '.'}{' '}
                        No se vuelve a cargar: abrir de nuevo duplicaría el
                        saldo histórico.
                    </p>
                </div>
            </div>

            {existing.lines.length > 0 && (
                <table className="w-full text-sm">
                    <tbody className="divide-y">
                        {existing.lines.map((linea) => (
                            <tr key={linea.account}>
                                <td className="px-4 py-2">{linea.label}</td>
                                <td className="px-4 py-2 text-right">
                                    <Money value={linea.amount} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}

            {existing.description && (
                <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                    {existing.description}
                </p>
            )}
        </div>
    );
}
