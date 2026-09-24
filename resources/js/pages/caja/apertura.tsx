import { Head, Link, useForm } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useMemo } from 'react';
import InputError from '@/components/input-error';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
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
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import { conMoneda } from '@/features/caja/moneda';
import { businessToday, date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { dia as caja } from '@/routes/caja';
import { index as apertura, store } from '@/routes/caja/apertura';

type Cuenta = { code: string; label: string };

/**
 * El total de los billetes con un renglón cambiado.
 *
 * Se calcula sobre el mapa que va a quedar, no sobre el que hay: dentro de
 * un `setData` el estado todavía es el anterior, y el efectivo saldría un
 * billete atrasado.
 */
function contadoCon(
    cantidades: Record<string, number>,
    denominacion: number,
    cantidad: number,
): string {
    const conElCambio: Record<string, number> = { ...cantidades };
    conElCambio[denominacion] = cantidad;

    const centavos = Object.entries(conElCambio).reduce(
        (acumulado, [billete, cuantos]) =>
            acumulado + BigInt(billete) * BigInt(cuantos || 0) * 100n,
        0n,
    );

    const texto = centavos.toString().padStart(3, '0');

    return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/** Un cheque del cajón al abrir los libros, como lo lista el reverso. */
type Cheque = {
    number: string;
    bank: string;
    issueDate: string;
    amount: string;
    expediente: string;
    company: string;
    beneficiary: string;
};

const CHEQUES = 'CHEQUES_IN_CUSTODY';
const EFECTIVO = 'CASH_ON_HAND';

const CHEQUE_VACIO: Cheque = {
    number: '',
    bank: '',
    issueDate: '',
    amount: '',
    expediente: '',
    company: '',
    beneficiary: '',
};

/**
 * Qué va en cada renglón, en el vocabulario de la planilla.
 *
 * El plan de cuentas nombra las cosas como las nombra la contabilidad
 * —«Cuenta bancaria»— y la planilla del área las nombra como las nombra el
 * área —«DEPOSITOS DIRECTOS»—. Son lo mismo, pero quien abre los libros
 * tiene la planilla en la mano y no el plan de cuentas: sin la traducción,
 * la tercera columna parece que falta.
 *
 * Vive acá y no en `LedgerAccount` a propósito: «depósitos directos» es
 * vocabulario de Haberes, y el motor contable no puede saber que existe.
 */
const AYUDAS: Record<string, string> = {
    CASH_ON_HAND: 'La plata física que hay en el cajón.',
    CHEQUES_IN_CUSTODY: 'Los cheques guardados, todavía sin depositar.',
    CASH_IN_TRANSIT:
        'Salió de la caja camino al banco y todavía no se acreditó. Casi siempre va en cero.',
    BANK_ACCOUNT:
        'La columna «DEPOSITOS DIRECTOS» de la planilla: lo que las empresas depositaron derecho en la cuenta.',
};

type Props = {
    selected: {
        cashBoxId: number;
        cashBoxName: string;
        currency: CurrencyCode;
    };
    accounts: Cuenta[];
    suggestedDenominations: number[];
    bankAccounts: { id: number; label: string }[];
    /** Qué cuenta del plan es la bancaria, para saber cuándo pedir la cuenta. */
    bankAccountCode: string;
    existing: {
        date: string;
        postedAt: string | null;
        description: string | null;
        lines: { account: string; label: string; amount: string }[];
    } | null;
};

/**
 * La apertura de los libros.
 *
 * **Ocurre una sola vez en la vida del sistema**, y es lo primero que tiene
 * que pasar: el día que arranque hay plata física en el cajón que él no
 * conoce. Sin este asiento el saldo teórico empieza en cero y el primer
 * arqueo da una diferencia igual a todo el saldo histórico.
 *
 * Por eso la pantalla insiste con dos cosas antes que con el formulario: que
 * esto no se repite, y que corregirlo no es volver a cargarlo.
 */
export default function CajaApertura({
    selected,
    accounts,
    suggestedDenominations,
    bankAccounts,
    bankAccountCode,
    existing,
}: Props) {
    const hoy = businessToday();

    const form = useForm<{
        cashBoxId: number;
        currency: string;
        date: string;
        balances: Record<string, string>;
        denominations: Record<string, number>;
        cheques: Cheque[];
        bankAccountId: number;
        notes: string;
    }>({
        cashBoxId: selected.cashBoxId,
        currency: selected.currency,
        date: hoy,
        balances: {},
        denominations: {},
        cheques: [],
        bankAccountId: bankAccounts.length === 1 ? bankAccounts[0].id : 0,
        notes: '',
    });

    /*
     * «DEPOSITOS DIRECTOS» es lo que las empresas depositaron derecho en la
     * cuenta de la Secretaría, así que su saldo abre una pata bancaria y
     * hay que decir en qué cuenta está. El libro es append-only: una línea
     * sin cuenta no se corrige después, se revierte.
     */
    const declaraBanco = /[1-9]/.test(
        form.data.balances[bankAccountCode] ?? '',
    );

    /*
     * Detallar los cheques es opcional, pero sin detalle el inventario del
     * reverso arranca vacío: el libro sabe cuánto valen y no cuáles son.
     * Se ofrece en cuanto hay saldo de cheques, que es cuando la pregunta
     * tiene sentido.
     */
    const declaraCheques = /[1-9]/.test(form.data.balances[CHEQUES] ?? '');

    /*
     * El efectivo se cuenta, no se escribe.
     *
     * Es la única vez que contar el cajón sale barato —se hace una sola
     * vez— y es lo que le da composición al fajo que después se arrastra
     * sin recontar. Sin esto el sistema sabría cuánto vale y no de qué
     * está hecho, así que el día que alguien lo abra buscando un faltante
     * no tendría contra qué comparar.
     */
    const contado = useMemo(() => {
        const centavos = Object.entries(form.data.denominations).reduce(
            (acumulado, [denominacion, cantidad]) =>
                acumulado + BigInt(denominacion) * BigInt(cantidad || 0) * 100n,
            0n,
        );

        const texto = centavos.toString().padStart(3, '0');

        return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
    }, [form.data.denominations]);

    const contarBillete = (denominacion: number, valor: string) => {
        const cantidad = Number.parseInt(valor, 10);

        form.setData({
            ...form.data,
            denominations: {
                ...form.data.denominations,
                [denominacion]:
                    Number.isFinite(cantidad) && cantidad > 0 ? cantidad : 0,
            },
            balances: {
                ...form.data.balances,
                [EFECTIVO]: contadoCon(
                    form.data.denominations,
                    denominacion,
                    Number.isFinite(cantidad) && cantidad > 0 ? cantidad : 0,
                ),
            },
        });
    };

    /*
     * El contraasiento se muestra mientras se carga, y no es adorno: es lo
     * que va a `LEGACY_FUNDS`. Ver crecer ese número al lado de los saldos
     * es lo que hace entender qué se está declarando — que esa plata existe
     * y todavía no se sabe de quién es.
     */
    /*
     * Los errores por clave, incluidas las que no son campos del formulario.
     *
     * `form.errors` está tipado por la forma de los datos, así que
     * `balances.CASH_ON_HAND` —la clave que devuelve la regla `balances.*`—
     * no se puede leer de ahí aunque el servidor la haya mandado.
     */
    const errores = form.errors as unknown as Record<string, string>;

    /*
     * Lo que no tiene un campo donde mostrarse.
     *
     * **Un error sin lugar donde aparecer es un botón que no hace nada.**
     * Pasaba con el formato del importe: `9.852.300,00` no es un número
     * para el validador, la respuesta volvía con `balances.CASH_ON_HAND` y
     * la pantalla no pintaba esa clave en ningún lado. El operador apretaba
     * «Abrir los libros» y no ocurría nada visible.
     *
     * Por eso esto no enumera las claves que faltan —volvería a quedar
     * corto la próxima vez que el servidor agregue una— sino que muestra
     * todo lo que ningún campo reclamó.
     */
    const sinLugar = useMemo(() => {
        const conCampo = new Set([
            'date',
            'notes',
            'balances',
            'bankAccountId',
            ...accounts.map((cuenta) => `balances.${cuenta.code}`),
            ...form.data.cheques.flatMap((_, indice) =>
                Object.keys(CHEQUE_VACIO).map(
                    (campo) => `cheques.${indice}.${campo}`,
                ),
            ),
        ]);

        return Object.entries(errores)
            .filter(([clave]) => !conCampo.has(clave))
            .map(([, mensaje]) => mensaje);
    }, [errores, accounts, form.data.cheques]);

    const total = useMemo(
        () =>
            Object.values(form.data.balances).reduce(
                (acumulado, importe) => sumar(acumulado, importe),
                '0.00',
            ),
        [form.data.balances],
    );

    return (
        <EnMoneda moneda={selected.currency}>
            <Head title="Apertura de los libros" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow={selected.cashBoxName}
                    title="Apertura de los libros"
                    description="El saldo que ya está en el cajón el día que el sistema arranca."
                    actions={
                        <SelectorDeMoneda
                            moneda={selected.currency}
                            href={(otra) => conMoneda(apertura().url, otra)}
                        />
                    }
                />

                {existing ? (
                    <YaAbierta existing={existing} />
                ) : (
                    <>
                        <Advertencia />

                        {sinLugar.length > 0 && (
                            <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
                                {sinLugar.map((mensaje) => (
                                    <p key={mensaje}>{mensaje}</p>
                                ))}
                            </div>
                        )}

                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.post(store().url);
                            }}
                            className="flex flex-col gap-6"
                        >
                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor="date">Fecha del saldo</Label>
                                <Input
                                    id="date"
                                    type="date"
                                    max={hoy}
                                    value={form.data.date}
                                    onChange={(e) =>
                                        form.setData('date', e.target.value)
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    La víspera del primer movimiento: lo que hay
                                    en el cajón antes de empezar.
                                </p>
                                <InputError message={form.errors.date} />
                            </div>

                            <div className="overflow-hidden rounded-lg border">
                                <div className="border-b bg-muted/50 px-4 py-2">
                                    <h2 className="text-sm font-semibold">
                                        Dónde está el dinero
                                    </h2>
                                    <p className="text-xs text-muted-foreground">
                                        Solo cuentas de ubicación. De quién es
                                        cada peso se determina después, con su
                                        expediente.
                                    </p>
                                </div>

                                {accounts.map((cuenta) => (
                                    <div
                                        key={cuenta.code}
                                        className="border-b px-4 py-2.5 last:border-b-0"
                                    >
                                        <div className="flex items-center gap-4">
                                            <Label
                                                htmlFor={cuenta.code}
                                                className="flex-1 font-normal"
                                            >
                                                {cuenta.label}
                                                {AYUDAS[cuenta.code] && (
                                                    <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                                                        {AYUDAS[cuenta.code]}
                                                    </span>
                                                )}
                                            </Label>
                                            <Input
                                                id={cuenta.code}
                                                inputMode="decimal"
                                                placeholder="0.00"
                                                readOnly={
                                                    cuenta.code === EFECTIVO
                                                }
                                                className="w-44 text-right font-mono tabular-nums read-only:bg-muted/50 read-only:text-muted-foreground"
                                                value={
                                                    cuenta.code === EFECTIVO
                                                        ? contado
                                                        : (form.data.balances[
                                                              cuenta.code
                                                          ] ?? '')
                                                }
                                                onChange={(e) =>
                                                    form.setData('balances', {
                                                        ...form.data.balances,
                                                        [cuenta.code]:
                                                            e.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        {/*
                                         * El error de un importe va debajo de
                                         * su importe. La regla `balances.*`
                                         * devuelve la clave con el nombre de
                                         * la cuenta —`balances.CASH_ON_HAND`—
                                         * y esa clave no está en el tipo del
                                         * formulario, así que se lee del mapa.
                                         */}
                                        <InputError
                                            className="mt-1"
                                            message={
                                                errores[
                                                    `balances.${cuenta.code}`
                                                ]
                                            }
                                        />
                                    </div>
                                ))}

                                <div className="flex items-baseline justify-between border-t bg-muted/40 px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">
                                            Fondos del sistema anterior
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            El contraasiento: plata que existe y
                                            todavía no tiene dueño asignado.
                                        </p>
                                    </div>
                                    <Money
                                        value={total}
                                        className="text-base font-semibold"
                                    />
                                </div>
                            </div>
                            <InputError message={form.errors.balances} />

                            {/*
                             * Contar el cajón. El efectivo de arriba sale de
                             * acá: es la única vez que se cuenta, y lo que le
                             * da composición al fajo que después se arrastra.
                             */}
                            <div className="overflow-hidden rounded-lg border">
                                <div className="border-b px-4 py-3">
                                    <p className="text-sm font-medium">
                                        Billetes en el cajón
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        De acá sale el efectivo declarado
                                        arriba.
                                    </p>
                                </div>

                                <div className="grid grid-cols-[1fr_6rem_1fr] gap-2 border-b bg-muted/50 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    <span>Billete</span>
                                    <span className="text-center">
                                        Cantidad
                                    </span>
                                    <span className="text-right">Subtotal</span>
                                </div>

                                <div className="max-h-72 overflow-y-auto">
                                    {suggestedDenominations.map((billete) => {
                                        const cantidad =
                                            form.data.denominations[billete] ??
                                            0;

                                        return (
                                            <div
                                                key={billete}
                                                className="grid grid-cols-[1fr_6rem_1fr] items-center gap-2 border-b px-4 py-1.5 last:border-b-0"
                                            >
                                                <Money
                                                    value={`${billete}.00`}
                                                />
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    inputMode="numeric"
                                                    className="h-8 text-center"
                                                    value={cantidad || ''}
                                                    onChange={(e) =>
                                                        contarBillete(
                                                            billete,
                                                            e.target.value,
                                                        )
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
                            <InputError message={errores['denominations']} />

                            {declaraCheques && (
                                <CarteraDeCheques
                                    cheques={form.data.cheques}
                                    declarado={
                                        form.data.balances[CHEQUES] ?? ''
                                    }
                                    errores={errores}
                                    onChange={(cheques) =>
                                        form.setData('cheques', cheques)
                                    }
                                />
                            )}

                            {declaraBanco && (
                                <div className="grid gap-2">
                                    <Label htmlFor="bankAccountId">
                                        En qué cuenta están los depósitos
                                        directos
                                    </Label>
                                    <Select
                                        value={
                                            form.data.bankAccountId
                                                ? String(
                                                      form.data.bankAccountId,
                                                  )
                                                : ''
                                        }
                                        onValueChange={(valor) =>
                                            form.setData(
                                                'bankAccountId',
                                                Number(valor),
                                            )
                                        }
                                    >
                                        <SelectTrigger id="bankAccountId">
                                            <SelectValue placeholder="Elegí la cuenta" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {bankAccounts.map((cuenta) => (
                                                <SelectItem
                                                    key={cuenta.id}
                                                    value={String(cuenta.id)}
                                                >
                                                    {cuenta.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.bankAccountId}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="notes">Observaciones</Label>
                                <Textarea
                                    id="notes"
                                    rows={2}
                                    placeholder="De dónde sale este saldo: acta, arqueo previo, planilla manual."
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.notes} />
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    <BookOpen className="size-4" />
                                    Abrir los libros
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={conMoneda(
                                            caja().url,
                                            selected.currency,
                                        )}
                                    >
                                        Cancelar
                                    </Link>
                                </Button>
                            </div>
                        </form>
                    </>
                )}
            </div>
        </EnMoneda>
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
 * Expediente, empresa y beneficiario son **lo que declara quien abre los
 * libros**, no un dato verificado: el sistema no conoce ese expediente. El
 * día que el cheque se impute a una cuota real manda el recibo.
 *
 * El total se compara contra el saldo declarado a la vista. Son dos formas
 * de decir lo mismo y el Action exige que coincidan; mostrarlo acá evita
 * que el operador se entere recién al enviar.
 */
function CarteraDeCheques({
    cheques,
    declarado,
    errores,
    onChange,
}: {
    cheques: Cheque[];
    declarado: string;
    errores: Record<string, string>;
    onChange: (cheques: Cheque[]) => void;
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
                            alineado
                            valor={cheque.amount}
                            error={clave(indice, 'amount')}
                            onChange={(v) => editar(indice, 'amount', v)}
                        />
                    </div>

                    <div className="grid gap-2 sm:grid-cols-3">
                        <Campo
                            etiqueta="Expediente"
                            valor={cheque.expediente}
                            error={clave(indice, 'expediente')}
                            onChange={(v) => editar(indice, 'expediente', v)}
                        />
                        <Campo
                            etiqueta="Empresa"
                            valor={cheque.company}
                            error={clave(indice, 'company')}
                            onChange={(v) => editar(indice, 'company', v)}
                        />
                        <Campo
                            etiqueta="Beneficiario"
                            valor={cheque.beneficiary}
                            error={clave(indice, 'beneficiary')}
                            onChange={(v) => editar(indice, 'beneficiary', v)}
                        />
                    </div>
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
    alineado = false,
}: {
    etiqueta: string;
    valor: string;
    error?: string;
    onChange: (valor: string) => void;
    tipo?: string;
    alineado?: boolean;
}) {
    return (
        <div className="grid gap-1">
            <Label className="text-xs font-normal text-muted-foreground">
                {etiqueta}
            </Label>
            <Input
                type={tipo}
                inputMode={alineado ? 'decimal' : undefined}
                className={alineado ? 'text-right font-mono tabular-nums' : ''}
                value={valor}
                onChange={(e) => onChange(e.target.value)}
            />
            <InputError message={error} />
        </div>
    );
}

function Advertencia() {
    return (
        <div className="flex gap-3 rounded-lg border border-warning-soft bg-warning-soft p-4 text-warning-strong">
            <TriangleAlert className="mt-0.5 size-5 shrink-0" />
            <div className="space-y-1 text-sm">
                <p className="font-medium">Esto se hace una sola vez.</p>
                <p>
                    Define todos los saldos posteriores: contra este número se
                    compara cada arqueo y cada cierre. Si después resulta
                    equivocado no se edita — se revierte el asiento y se abre de
                    nuevo.
                </p>
            </div>
        </div>
    );
}

function YaAbierta({ existing }: { existing: NonNullable<Props['existing']> }) {
    return (
        <div className="overflow-hidden rounded-lg border bg-card">
            <div className="flex items-start gap-3 border-b bg-success-soft p-4 text-success-strong">
                <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                <div className="text-sm">
                    <p className="font-medium">
                        Esta caja ya tiene sus libros abiertos.
                    </p>
                    <p>
                        Con fecha {formatDate(existing.date)}. Para corregirlo
                        hay que revertir el asiento, no cargarlo de nuevo:
                        volver a abrir duplicaría el saldo histórico.
                    </p>
                </div>
            </div>

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

            {existing.description && (
                <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                    {existing.description}
                </p>
            )}
        </div>
    );
}

/**
 * Suma dos importes decimales sin pasar por `number`.
 *
 * La misma regla que rige del lado de PHP: el punto flotante no existe en
 * ningún punto de la pila. Un importe a medio tipear —`12.` o vacío— suma
 * cero en vez de romper el total mientras se escribe.
 */
function sumar(a: string, b: string): string {
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
