import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, Landmark, Wallet } from 'lucide-react';
import { useMemo } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
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
    ConteoDeBilletes,
    contadoCon,
    totalDeBilletes,
} from '@/features/caja/components/opening-parts';
import type { AperturaExistente } from '@/features/caja/components/opening-parts';
import { businessToday } from '@/lib/format';
import { dia } from '@/routes/aranceles/caja';
import { store } from '@/routes/aranceles/caja/apertura';
import { index as cuentasDeLaCaja } from '@/routes/aranceles/caja/cuentas';
import { index as cuentasBancarias } from '@/routes/banco/cuentas';

type CuentaBancaria = { id: number; label: string };

const EFECTIVO = 'CASH_ON_HAND';

/**
 * La apertura de la caja de Aranceles: una sola vez, con su efectivo. Sin saldo
 * bancario, porque la cuenta es compartida con Multas, y sin cheques, porque la
 * caja no los acepta.
 */
export default function AperturaDeAranceles({
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
            <Head title="Apertura de la caja de Aranceles" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Apertura de la caja"
                    description="Lo que había en la caja al empezar a operar con el sistema, y la cuenta bancaria con la que opera."
                    actions={
                        opening && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link href={cuentasDeLaCaja().url}>
                                        <Landmark className="size-4" />
                                        Cuentas de la caja
                                    </Link>
                                </Button>
                                <Button asChild>
                                    <Link
                                        href={
                                            dia({
                                                query: { fecha: opening.date },
                                            }).url
                                        }
                                    >
                                        <Wallet className="size-4" />
                                        Ir al día de la apertura
                                    </Link>
                                </Button>
                            </>
                        )
                    }
                />

                {opening ? (
                    <>
                        <AperturaHecha existing={opening} />
                        {bankAccountLabel && (
                            <p className="text-sm text-muted-foreground">
                                Abrió con la cuenta{' '}
                                <span className="font-medium text-foreground">
                                    {bankAccountLabel}
                                </span>
                                .
                            </p>
                        )}
                    </>
                ) : canOpen ? (
                    <Formulario
                        cuentas={bankAccounts}
                        billetes={suggestedDenominations}
                    />
                ) : (
                    <p className="rounded-lg border bg-card p-5 text-sm text-muted-foreground shadow-raised">
                        La caja de Aranceles todavía no se abrió. La abre, una
                        sola vez, quien tiene el permiso de apertura.
                    </p>
                )}
            </div>
        </>
    );
}

function Formulario({
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
        notes: string;
        declaredEmpty: boolean;
    }>({
        date: businessToday(),
        bankAccountId: primera?.id ?? null,
        balances: {},
        denominations: {},
        notes: '',
        declaredEmpty: false,
    });

    const errores = form.errors as unknown as Record<string, string>;
    const contado = useMemo(
        () => totalDeBilletes(form.data.denominations),
        [form.data.denominations],
    );

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
                [EFECTIVO]: contadoCon(
                    form.data.denominations,
                    denominacion,
                    cuantos,
                ),
            },
        });
    };

    if (cuentas.length === 0) {
        return (
            <p className="rounded-lg border bg-card p-5 text-sm shadow-raised">
                No hay ninguna cuenta bancaria en pesos activa. Se da de alta en{' '}
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
                            Multas y cada caja reconoce solo lo que identifica.
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
                        <InputError message={form.errors.date} />
                    </div>
                </div>

                <label className="flex items-start gap-3 rounded-lg border p-4 text-sm">
                    <Checkbox
                        className="mt-0.5"
                        checked={form.data.declaredEmpty}
                        onCheckedChange={(v) =>
                            form.setData({
                                ...form.data,
                                declaredEmpty: v === true,
                                balances: v === true ? {} : form.data.balances,
                                denominations:
                                    v === true ? {} : form.data.denominations,
                            })
                        }
                    />
                    <span className="font-medium">
                        No hay efectivo al abrir
                    </span>
                </label>
                <InputError message={errores['declaredEmpty']} />

                {!form.data.declaredEmpty && (
                    <>
                        <div className="flex items-center gap-4 rounded-lg border px-4 py-2.5">
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
                        <InputError message={form.errors.balances} />

                        <ConteoDeBilletes
                            billetes={billetes}
                            cantidades={form.data.denominations}
                            onContar={contarBillete}
                        />
                        <InputError message={errores['denominations']} />
                    </>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="notes">Observaciones</Label>
                    <Textarea
                        id="notes"
                        rows={2}
                        maxLength={300}
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <InputError message={form.errors.notes} />
                </div>

                <div>
                    <Button type="submit" disabled={form.processing}>
                        <BookOpen className="size-4" aria-hidden="true" />
                        Abrir la caja de Aranceles
                    </Button>
                </div>
            </form>
        </>
    );
}
