import { Head, useForm } from '@inertiajs/react';
import { HandCoins, Info } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import PersonPicker from '@/components/person-picker';
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
import { Textarea } from '@/components/ui/textarea';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import { conMoneda } from '@/features/caja/moneda';
import { businessToday, date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import {
    index as pagosAnteriores,
    store,
} from '@/routes/caja/pagos-anteriores';

type Pago = {
    id: number;
    number: string;
    formattedNumber: string;
    beneficiary: string | null;
    reference: string | null;
    medium: string;
    amount: string;
    date: string;
    voided: boolean;
};

type Props = {
    selected: {
        cashBoxId: number;
        currency: CurrencyCode;
        cashBoxName: string;
    };
    balances: {
        pending: string;
        cash: string;
        cheques: string;
        bank: string;
    };
    bankAccounts: { id: number; label: string }[];
    payments: Pago[];
};

/**
 * Los haberes que entraron antes de que el sistema existiera.
 *
 * **La pantalla responde una sola pregunta: cuánto queda del sistema
 * anterior.** Ese número baja con cada pago, y el día que llegue a cero se
 * puede apagar la planilla en paralelo — que es todo el objetivo de haber
 * abierto los libros con un saldo en vez de cargar los expedientes viejos
 * uno por uno.
 *
 * Lo que distingue a este pago de uno normal es que **no hay expediente que
 * verificar**: ni haber, ni cuota, ni Orden. La única evidencia es el
 * registro manual, y por eso su referencia es obligatoria.
 */
export default function PagosAnteriores({
    selected,
    balances,
    bankAccounts,
    payments,
}: Props) {
    const [pagando, setPagando] = useState(false);
    const quedaAlgo = /[1-9]/.test(balances.pending);

    return (
        <EnMoneda moneda={selected.currency}>
            <Head title="Haberes anteriores" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow={selected.cashBoxName}
                    title="Haberes anteriores al sistema"
                    description="Los casos que ya estaban cuando el sistema arrancó, y que se pagan contra el saldo de apertura."
                    actions={
                        <>
                            <SelectorDeMoneda
                                moneda={selected.currency}
                                href={(otra) =>
                                    conMoneda(pagosAnteriores().url, otra)
                                }
                            />
                            {quedaAlgo && (
                                <Button onClick={() => setPagando(true)}>
                                    <HandCoins className="size-4" />
                                    Registrar pago
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg border-2 border-primary/30 bg-card p-4">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Queda por pagar
                        </p>
                        <p className="mt-2 text-2xl">
                            <Money value={balances.pending} dimWhenZero />
                        </p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {quedaAlgo
                                ? 'Cuando llegue a cero, no queda ningún caso viejo por pagar.'
                                : 'No queda nada del sistema anterior.'}
                        </p>
                    </div>

                    <Saldo titulo="Efectivo en caja" valor={balances.cash} />
                    <Saldo
                        titulo="Cheques en custodia"
                        valor={balances.cheques}
                    />
                    {/*
                     * Lo que las empresas depositaron derecho en la cuenta
                     * de la Secretaría. Un caso viejo que llegó por ahí se
                     * paga por transferencia, no por el cajón.
                     */}
                    <Saldo titulo="Depósitos directos" valor={balances.bank} />
                </div>

                {!quedaAlgo && payments.length === 0 && (
                    <p className="flex items-center gap-2 rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                        <Info className="size-4 shrink-0" />
                        Esta caja no declaró saldo del sistema anterior al abrir
                        los libros, así que no hay nada que pagar por acá.
                    </p>
                )}

                {payments.length > 0 && (
                    <div className="overflow-hidden rounded-lg border bg-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Fecha
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Recibo
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Beneficiario
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Expediente anterior
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Importe
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {payments.map((pago) => (
                                        <tr
                                            key={pago.id}
                                            className={
                                                pago.voided
                                                    ? 'text-muted-foreground line-through'
                                                    : undefined
                                            }
                                        >
                                            <td className="px-4 py-2 whitespace-nowrap">
                                                {formatDate(pago.date)}
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {pago.number}
                                            </td>
                                            <td className="px-4 py-2">
                                                {pago.beneficiary}
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {pago.reference}
                                            </td>
                                            <td className="px-4 py-2 text-right">
                                                <Money value={pago.amount} />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>

            <DialogoPago
                abierto={pagando}
                cerrar={() => setPagando(false)}
                selected={selected}
                pendiente={balances.pending}
                bankAccounts={bankAccounts}
            />
        </EnMoneda>
    );
}

function Saldo({ titulo, valor }: { titulo: string; valor: string }) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {titulo}
            </p>
            <p className="mt-2 text-2xl">
                <Money value={valor} dimWhenZero />
            </p>
        </div>
    );
}

function DialogoPago({
    abierto,
    cerrar,
    selected,
    pendiente,
    bankAccounts,
}: {
    abierto: boolean;
    cerrar: () => void;
    selected: Props['selected'];
    pendiente: string;
    bankAccounts: Props['bankAccounts'];
}) {
    const hoy = businessToday();

    const form = useForm({
        cashBoxId: selected.cashBoxId,
        currency: selected.currency,
        personId: 0,
        amount: '',
        legacyReference: '',
        paymentDate: hoy,
        medium: 'cash',
        bankAccountId: bankAccounts.length === 1 ? bankAccounts[0].id : 0,
        talonarioNumber: '',
        notes: '',
    });

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
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pago de un haber anterior</DialogTitle>
                    <DialogDescription>
                        Queda por pagar <Money value={pendiente} />. El pago
                        emite su recibo de egreso y baja ese saldo.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-pago-anterior"
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
                    className="grid gap-4"
                >
                    <div className="grid gap-2">
                        <Label>Beneficiario</Label>
                        <PersonPicker
                            value={form.data.personId || null}
                            onChange={(id) => form.setData('personId', id ?? 0)}
                            /*
                             * Sin lista previa: estos beneficiarios son de
                             * casos que el sistema no tiene cargados, así
                             * que se buscan —o se dan de alta— en el momento.
                             */
                            options={[]}
                            role="beneficiary"
                        />
                        <InputError message={form.errors.personId} />
                    </div>

                    {/*
                     * La referencia es lo único que ata este pago a la
                     * realidad: no hay expediente en el sistema contra el
                     * cual verificarlo. Por eso va con el mismo peso visual
                     * que el importe y no como un campo opcional más.
                     */}
                    <div className="grid gap-2">
                        <Label htmlFor="legacyReference">
                            Expediente del sistema anterior
                        </Label>
                        <Input
                            id="legacyReference"
                            value={form.data.legacyReference}
                            onChange={(e) =>
                                form.setData('legacyReference', e.target.value)
                            }
                            placeholder="131010/2023"
                            className="font-mono"
                        />
                        <p className="text-xs text-muted-foreground">
                            Es el único respaldo del pago: no hay expediente
                            cargado contra el cual verificarlo.
                        </p>
                        <InputError message={form.errors.legacyReference} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Importe</Label>
                            <Input
                                id="amount"
                                inputMode="decimal"
                                placeholder="0.00"
                                className="text-right font-mono tabular-nums"
                                value={form.data.amount}
                                onChange={(e) =>
                                    form.setData('amount', e.target.value)
                                }
                            />
                            <InputError message={form.errors.amount} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="medium">Con qué se paga</Label>
                            <Select
                                value={form.data.medium}
                                onValueChange={(valor) =>
                                    form.setData('medium', valor)
                                }
                            >
                                <SelectTrigger id="medium">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="cash">
                                        Efectivo
                                    </SelectItem>
                                    <SelectItem value="cheque">
                                        Cheque en custodia
                                    </SelectItem>
                                    <SelectItem value="bank">
                                        Transferencia
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.medium} />
                        </div>
                    </div>

                    {/*
                     * La cuenta solo aparece cuando el dinero sale del
                     * banco. El libro es append-only: una línea bancaria
                     * sin cuenta no se corrige después, se revierte.
                     */}
                    {form.data.medium === 'bank' && (
                        <div className="grid gap-2">
                            <Label htmlFor="bankAccountId">
                                De qué cuenta sale
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
                            <InputError message={form.errors.bankAccountId} />
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="paymentDate">Fecha del pago</Label>
                            <Input
                                id="paymentDate"
                                type="date"
                                max={hoy}
                                value={form.data.paymentDate}
                                onChange={(e) =>
                                    form.setData('paymentDate', e.target.value)
                                }
                            />
                            <InputError message={form.errors.paymentDate} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="talonarioNumber">
                                N.º del talonario
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    opcional
                                </span>
                            </Label>
                            <Input
                                id="talonarioNumber"
                                value={form.data.talonarioNumber}
                                onChange={(e) =>
                                    form.setData(
                                        'talonarioNumber',
                                        e.target.value,
                                    )
                                }
                                className="font-mono tabular-nums"
                            />
                            <InputError message={form.errors.talonarioNumber} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Observaciones</Label>
                        <Textarea
                            id="notes"
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-pago-anterior"
                        disabled={form.processing}
                    >
                        Registrar el pago
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
