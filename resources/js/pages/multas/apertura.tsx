import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, Wallet } from 'lucide-react';
import { useMemo } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
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
import { businessToday } from '@/lib/format';
import { index as cuentasBancarias } from '@/routes/banco/cuentas';
import { dia as diaDeMultas } from '@/routes/multas/caja';
import { store } from '@/routes/multas/caja/apertura';

type CuentaBancaria = { id: number; label: string };

const EFECTIVO = 'CASH_ON_HAND';
const CHEQUES = 'CHEQUES_IN_CUSTODY';

/**
 * La apertura de la caja de Multas: una sola vez, al empezar a operar.
 *
 * Abre como la de Haberes —el efectivo contado por billete y los cheques
 * detallados—, pero **sin saldo bancario**: la cuenta es compartida con
 * Aranceles y su saldo no se reparte. Hecha, muestra lo declarado y la
 * cuenta con la que opera; el día a día está en la caja del día, que enlaza
 * acá desde el día de la apertura.
 */
export default function AperturaDeMultas({
    opening,
    bankAccountLabel,
    bankAccounts,
    suggestedDenominations,
    canOpen,
}: {
    opening: AperturaExistente | null;
    bankAccountLabel: string | null;
    bankAccounts: CuentaBancaria[];
    suggestedDenominations: number[];
    canOpen: boolean;
}) {
    return (
        <>
            <Head title="Apertura de la caja de Multas" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Apertura de la caja"
                    description="Lo que había en la caja al empezar a operar con el sistema, y la cuenta bancaria con la que opera."
                    actions={
                        opening && (
                            <Button asChild>
                                <Link
                                    href={
                                        diaDeMultas({
                                            query: { fecha: opening.date },
                                        }).url
                                    }
                                >
                                    <Wallet className="size-4" />
                                    Ir al día de la apertura
                                </Link>
                            </Button>
                        )
                    }
                />

                {opening ? (
                    <>
                        <AperturaHecha existing={opening} />
                        {bankAccountLabel && (
                            <p className="text-sm text-muted-foreground">
                                Opera con la cuenta{' '}
                                <span className="font-medium text-foreground">
                                    {bankAccountLabel}
                                </span>
                                , compartida con Aranceles: su saldo no se suma
                                a la caja.
                            </p>
                        )}
                    </>
                ) : canOpen ? (
                    <FormularioDeApertura
                        cuentas={bankAccounts}
                        billetes={suggestedDenominations}
                    />
                ) : (
                    <p className="rounded-lg border bg-card p-5 text-sm text-muted-foreground shadow-raised">
                        La caja de Multas todavía no se abrió. La abre, una sola
                        vez, quien tiene el permiso de apertura.
                    </p>
                )}
            </div>
        </>
    );
}

function FormularioDeApertura({
    cuentas,
    billetes,
}: {
    cuentas: CuentaBancaria[];
    billetes: number[];
}) {
    const primera = cuentas.length === 1 ? cuentas[0] : null;

    const form = useForm<{
        date: string;
        bankAccountId: number | null;
        balances: Record<string, string>;
        denominations: Record<string, number>;
        cheques: Cheque[];
        notes: string;
        declaredEmpty: boolean;
    }>({
        date: businessToday(),
        bankAccountId: primera?.id ?? null,
        balances: {},
        denominations: {},
        cheques: [],
        notes: '',
        declaredEmpty: false,
    });

    const errores = form.errors as unknown as Record<string, string>;
    const contado = useMemo(
        () => totalDeBilletes(form.data.denominations),
        [form.data.denominations],
    );
    const declaraCheques = /[1-9]/.test(form.data.balances[CHEQUES] ?? '');
    const total = sumar(contado, form.data.balances[CHEQUES] ?? '');

    const contarBillete = (denominacion: number, valor: string) => {
        const cantidad = Number.parseInt(valor, 10);
        const cuantos =
            Number.isFinite(cantidad) && cantidad > 0 ? cantidad : 0;

        form.setData({
            ...form.data,
            denominations: {
                ...form.data.denominations,
                [denominacion]: cuantos,
            },
            balances: {
                ...form.data.balances,
                [EFECTIVO]: contadoCon(
                    form.data.denominations,
                    denominacion,
                    cuantos,
                ),
            },
        });
    };

    const declararVacio = (vacio: boolean) =>
        form.setData({
            ...form.data,
            declaredEmpty: vacio,
            balances: vacio ? {} : form.data.balances,
            denominations: vacio ? {} : form.data.denominations,
            cheques: vacio ? [] : form.data.cheques,
        });

    if (cuentas.length === 0) {
        return (
            <p className="rounded-lg border bg-card p-5 text-sm shadow-raised">
                No hay ninguna cuenta bancaria en pesos activa. La caja de
                Multas abre con la cuenta con la que opera: se da de alta en{' '}
                <Link
                    href={cuentasBancarias()}
                    className="text-primary hover:underline"
                >
                    Banco › Cuentas
                </Link>
                .
            </p>
        );
    }

    return (
        <>
            <AdvertenciaDeApertura />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(store().url);
                }}
                className="flex flex-col gap-6"
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="bankAccountId">
                            Cuenta bancaria con la que opera
                        </Label>
                        <Select
                            value={
                                form.data.bankAccountId
                                    ? String(form.data.bankAccountId)
                                    : ''
                            }
                            onValueChange={(valor) =>
                                form.setData('bankAccountId', Number(valor))
                            }
                        >
                            <SelectTrigger id="bankAccountId">
                                <SelectValue placeholder="Elegí la cuenta" />
                            </SelectTrigger>
                            <SelectContent>
                                {cuentas.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            Su saldo no se suma acá: la cuenta es compartida con
                            Aranceles y cada caja reconoce solo lo que
                            identifica.
                        </p>
                        <InputError message={form.errors.bankAccountId} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">Fecha de la apertura</Label>
                        <DateInput
                            id="date"
                            max={businessToday()}
                            value={form.data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            El primer día que opera la caja. Los saldos son los
                            del cierre del día anterior.
                        </p>
                        <InputError message={form.errors.date} />
                    </div>
                </div>

                <label className="flex items-start gap-3 rounded-lg border p-4 text-sm">
                    <Checkbox
                        id="declaredEmpty"
                        className="mt-0.5"
                        checked={form.data.declaredEmpty}
                        onCheckedChange={(v) => declararVacio(v === true)}
                    />
                    <span>
                        <span className="font-medium">
                            No hay efectivo ni cheques al abrir
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            La apertura queda registrada sin saldo, con tu
                            nombre y la fecha.
                        </span>
                    </span>
                </label>
                <InputError message={errores['declaredEmpty']} />

                {!form.data.declaredEmpty && (
                    <>
                        <div className="overflow-hidden rounded-lg border">
                            <div className="flex items-center gap-4 border-b px-4 py-2.5">
                                <Label className="flex-1 font-normal">
                                    Efectivo
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Sale de los billetes contados abajo.
                                    </span>
                                </Label>
                                <div className="w-44">
                                    <AmountInput
                                        readOnly
                                        aria-label="Efectivo"
                                        value={contado}
                                        onChange={() => undefined}
                                    />
                                </div>
                            </div>
                            <div className="flex items-center gap-4 border-b px-4 py-2.5">
                                <Label
                                    htmlFor={CHEQUES}
                                    className="flex-1 font-normal"
                                >
                                    Cheques en cartera
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Los cheques guardados, todavía sin
                                        depositar.
                                    </span>
                                </Label>
                                <div className="w-44">
                                    <AmountInput
                                        id={CHEQUES}
                                        placeholder="0,00"
                                        value={
                                            form.data.balances[CHEQUES] ?? ''
                                        }
                                        onChange={(valor) =>
                                            form.setData('balances', {
                                                ...form.data.balances,
                                                [CHEQUES]: valor,
                                            })
                                        }
                                    />
                                </div>
                            </div>
                            <div className="flex items-baseline justify-between bg-muted/40 px-4 py-3">
                                <p className="text-sm font-medium">
                                    Fondos del sistema anterior
                                </p>
                                <Money
                                    value={total}
                                    className="text-base font-semibold"
                                />
                            </div>
                        </div>
                        <InputError message={form.errors.balances} />
                        <InputError message={errores[`balances.${CHEQUES}`]} />

                        <ConteoDeBilletes
                            billetes={billetes}
                            cantidades={form.data.denominations}
                            onContar={contarBillete}
                        />
                        <InputError message={errores['denominations']} />

                        {declaraCheques && (
                            <CarteraDeCheques
                                cheques={form.data.cheques}
                                declarado={form.data.balances[CHEQUES] ?? ''}
                                errores={errores}
                                onChange={(cheques) =>
                                    form.setData('cheques', cheques)
                                }
                                campos={[
                                    {
                                        campo: 'expediente',
                                        etiqueta: 'Expediente',
                                    },
                                    { campo: 'company', etiqueta: 'Librador' },
                                ]}
                            />
                        )}
                    </>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="notes">Observaciones</Label>
                    <Textarea
                        id="notes"
                        rows={2}
                        maxLength={300}
                        placeholder="De dónde sale este saldo: acta, arqueo previo, planilla manual."
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <InputError message={form.errors.notes} />
                </div>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        <BookOpen className="size-4" aria-hidden="true" />
                        Abrir la caja de Multas
                    </Button>
                </div>
            </form>
        </>
    );
}
