import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { useMemo } from 'react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { nombreDeMoneda } from '@/features/caja/apertura';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import {
    AdvertenciaDeApertura,
    AperturaHecha,
    CarteraDeCheques,
    ConteoDeBilletes,
    contadoCon,
    sumar,
    totalDeBilletes,
} from '@/features/caja/components/opening-parts';
import type {
    AperturaExistente,
    Cheque,
} from '@/features/caja/components/opening-parts';
import { conMoneda } from '@/features/caja/moneda';
import { businessToday } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { dia as caja } from '@/routes/caja';
import { index as apertura, store } from '@/routes/caja/apertura';

type Cuenta = { code: string; label: string };

const CHEQUES = 'CHEQUES_IN_CUSTODY';
const EFECTIVO = 'CASH_ON_HAND';

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
    existing: AperturaExistente | null;
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
        bankAccountId: number | null;
        notes: string;
        declaredEmpty: boolean;
    }>({
        cashBoxId: selected.cashBoxId,
        currency: selected.currency,
        date: hoy,
        balances: {},
        denominations: {},
        cheques: [],
        bankAccountId: bankAccounts.length === 1 ? bankAccounts[0].id : null,
        notes: '',
        declaredEmpty: false,
    });

    const nombre = nombreDeMoneda(selected.currency);

    /*
     * Sin cuenta bancaria en esta moneda no hay dónde estar depositado: el
     * renglón se apaga en vez de ofrecer cuentas de la otra moneda, que la
     * base rechazaría. Hoy pasa con los dólares.
     */
    const sinCuentaEnLaMoneda = bankAccounts.length === 0;

    /*
     * «No había nada al abrir» vacía lo cargado: un saldo escrito y después
     * declarado inexistente no puede viajar, y el Action lo rechazaría.
     */
    const declararVacio = (vacio: boolean) =>
        form.setData({
            ...form.data,
            declaredEmpty: vacio,
            balances: vacio ? {} : form.data.balances,
            denominations: vacio ? {} : form.data.denominations,
            cheques: vacio ? [] : form.data.cheques,
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
    const contado = useMemo(
        () => totalDeBilletes(form.data.denominations),
        [form.data.denominations],
    );

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
        /*
         * Solo cuentan los campos que están a la vista: con «no había nada
         * al abrir» marcado, los saldos y la cuenta se ocultan, y un error
         * suyo quedaría debajo de algo que no se ve.
         */
        const conCampo = new Set([
            'date',
            'notes',
            'declaredEmpty',
            ...(form.data.declaredEmpty
                ? []
                : [
                      'balances',
                      ...(declaraBanco ? ['bankAccountId'] : []),
                      ...accounts.map((cuenta) => `balances.${cuenta.code}`),
                      ...form.data.cheques.flatMap((_, indice) =>
                          [
                              'number',
                              'bank',
                              'issueDate',
                              'amount',
                              'expediente',
                              'company',
                              'beneficiary',
                          ].map((campo) => `cheques.${indice}.${campo}`),
                      ),
                  ]),
        ]);

        return Object.entries(errores)
            .filter(([clave]) => !conCampo.has(clave))
            .map(([, mensaje]) => mensaje);
    }, [
        errores,
        accounts,
        form.data.cheques,
        form.data.declaredEmpty,
        declaraBanco,
    ]);

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
                    <AperturaHecha existing={existing} />
                ) : (
                    <>
                        <AdvertenciaDeApertura />

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
                                /*
                                 * La cuenta viaja solo si hay depósitos
                                 * directos: sin ellos no hay pata bancaria,
                                 * y un selector vacío —no hay cuentas en esta
                                 * moneda— no es una cuenta que validar.
                                 */
                                form.transform((datos) => ({
                                    ...datos,
                                    bankAccountId: declaraBanco
                                        ? datos.bankAccountId
                                        : null,
                                }));
                                form.post(store().url);
                            }}
                            className="flex flex-col gap-6"
                        >
                            <div className="grid gap-2 sm:max-w-xs">
                                <Label htmlFor="date">
                                    Fecha de la apertura
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
                                <p className="text-xs text-muted-foreground">
                                    El primer día que opera el sistema. Los
                                    saldos son los del cierre del día anterior,
                                    y los papeles hasta ese cierre se cargan
                                    como cuota histórica.
                                </p>
                                <InputError message={form.errors.date} />
                            </div>

                            {/*
                             * El día que se abre un libro puede no haber nada
                             * en esa moneda —el primer dólar es el cobro que
                             * recién entra—. Sin apertura el libro no opera,
                             * así que se abre igual, pero declarándolo: un
                             * formulario en cero también podría ser un saldo
                             * que nadie cargó.
                             */}
                            <label className="flex items-start gap-3 rounded-lg border p-4 text-sm">
                                <Checkbox
                                    id="declaredEmpty"
                                    className="mt-0.5"
                                    checked={form.data.declaredEmpty}
                                    onCheckedChange={(v) =>
                                        declararVacio(v === true)
                                    }
                                />
                                <span>
                                    <span className="font-medium">
                                        No hay {nombre} al abrir los libros
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Ni en el cajón, ni en cheques, ni en la
                                        cuenta. La apertura queda registrada sin
                                        saldo, con tu nombre y la fecha.
                                    </span>
                                </span>
                            </label>
                            <InputError message={errores['declaredEmpty']} />

                            {!form.data.declaredEmpty && (
                                <>
                                    <div className="overflow-hidden rounded-lg border">
                                        <div className="border-b bg-muted/50 px-4 py-2">
                                            <h2 className="text-sm font-semibold">
                                                Dónde está el dinero
                                            </h2>
                                            <p className="text-xs text-muted-foreground">
                                                Solo cuentas de ubicación. De
                                                quién es cada peso se determina
                                                después, con su expediente.
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
                                                        {AYUDAS[
                                                            cuenta.code
                                                        ] && (
                                                            <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                                                                {
                                                                    AYUDAS[
                                                                        cuenta
                                                                            .code
                                                                    ]
                                                                }
                                                            </span>
                                                        )}
                                                        {cuenta.code ===
                                                            bankAccountCode &&
                                                            sinCuentaEnLaMoneda && (
                                                                <span className="mt-0.5 block text-xs font-normal text-warning-strong">
                                                                    No hay
                                                                    cuenta
                                                                    bancaria en{' '}
                                                                    {nombre}{' '}
                                                                    registrada.
                                                                    Se da de
                                                                    alta en
                                                                    Banco ›
                                                                    Cuentas.
                                                                </span>
                                                            )}
                                                    </Label>
                                                    <div className="w-44">
                                                        <AmountInput
                                                            id={cuenta.code}
                                                            placeholder="0,00"
                                                            readOnly={
                                                                cuenta.code ===
                                                                EFECTIVO
                                                            }
                                                            disabled={
                                                                cuenta.code ===
                                                                    bankAccountCode &&
                                                                sinCuentaEnLaMoneda
                                                            }
                                                            value={
                                                                cuenta.code ===
                                                                EFECTIVO
                                                                    ? contado
                                                                    : (form.data
                                                                          .balances[
                                                                          cuenta
                                                                              .code
                                                                      ] ?? '')
                                                            }
                                                            onChange={(valor) =>
                                                                form.setData(
                                                                    'balances',
                                                                    {
                                                                        ...form
                                                                            .data
                                                                            .balances,
                                                                        [cuenta.code]:
                                                                            valor,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    </div>
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
                                                    El contraasiento: plata que
                                                    existe y todavía no tiene
                                                    dueño asignado.
                                                </p>
                                            </div>
                                            <Money
                                                value={total}
                                                className="text-base font-semibold"
                                            />
                                        </div>
                                    </div>
                                    <InputError
                                        message={form.errors.balances}
                                    />

                                    {/*
                                     * Contar el cajón. El efectivo de arriba sale de
                                     * acá: es la única vez que se cuenta, y lo que le
                                     * da composición al fajo que después se arrastra.
                                     */}
                                    <ConteoDeBilletes
                                        billetes={suggestedDenominations}
                                        cantidades={form.data.denominations}
                                        onContar={contarBillete}
                                    />
                                    <InputError
                                        message={errores['denominations']}
                                    />

                                    {declaraCheques && (
                                        <CarteraDeCheques
                                            cheques={form.data.cheques}
                                            declarado={
                                                form.data.balances[CHEQUES] ??
                                                ''
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
                                                En qué cuenta están los
                                                depósitos directos
                                            </Label>
                                            <Select
                                                value={
                                                    form.data.bankAccountId
                                                        ? String(
                                                              form.data
                                                                  .bankAccountId,
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
                                                    {bankAccounts.map(
                                                        (cuenta) => (
                                                            <SelectItem
                                                                key={cuenta.id}
                                                                value={String(
                                                                    cuenta.id,
                                                                )}
                                                            >
                                                                {cuenta.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <InputError
                                                message={
                                                    form.errors.bankAccountId
                                                }
                                            />
                                        </div>
                                    )}
                                </>
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
