import { Head, Link, useForm } from '@inertiajs/react';
import { BookOpen, Landmark, Wallet } from 'lucide-react';
import { useMemo } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
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
import CollectionDeposit from '@/features/multas/components/collection-deposit';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { businessToday, date, isNonZero } from '@/lib/format';
import { index as cuentasBancarias } from '@/routes/banco/cuentas';
import { show as verMulta } from '@/routes/multas';
import { store } from '@/routes/multas/caja/apertura';

type CuentaBancaria = { id: number; label: string; cutoffOn: string | null };
type Banco = App.Modules.Multas.Data.FineBankPanelData;
type Cobro = App.Modules.Multas.Data.FinePendingPaymentData;

type Props = {
    opening: AperturaExistente | null;
    bank: Banco | null;
    bankAccounts: CuentaBancaria[];
    suggestedDenominations: number[];
    canOpen: boolean;
    paraDepositar: Cobro[];
    enTransito: Cobro[];
    canDeposit: boolean;
    canConfirmDeposit: boolean;
    canViewAttachments: boolean;
    rechazados: Cobro[];
    canManageRejections: boolean;
    canReplaceCheque: boolean;
};

const EFECTIVO = 'CASH_ON_HAND';
const CHEQUES = 'CHEQUES_IN_CUSTODY';

/** El día siguiente a una fecha `AAAA-MM-DD`, sin pasar por la zona horaria. */
function diaSiguiente(fecha: string): string {
    const [anio, mes, dia] = fecha.split('-').map(Number);
    const siguiente = new Date(Date.UTC(anio, mes - 1, dia + 1));

    return siguiente.toISOString().slice(0, 10);
}

/**
 * La caja de Multas: su apertura y la cuenta con la que opera.
 *
 * Abre como la de Haberes —el efectivo contado por billete y los cheques
 * detallados—, pero **sin saldo bancario**: la cuenta es compartida con
 * Aranceles y su saldo se declara una vez, para la cuenta, en Banco ›
 * Cuentas. Abierta, muestra los dos números que no se suman nunca: lo que
 * dice el banco de la cuenta entera y lo que Multas tiene cobrado en ella.
 */
export default function CajaDeMultas({
    opening,
    bank,
    bankAccounts,
    suggestedDenominations,
    canOpen,
    paraDepositar,
    enTransito,
    canDeposit,
    canConfirmDeposit,
    canViewAttachments,
    rechazados,
    canManageRejections,
    canReplaceCheque,
}: Props) {
    const permisos = {
        canDeposit,
        canConfirmDeposit,
        canViewAttachments,
        canManageRejections,
        canReplaceCheque,
    };

    return (
        <>
            <Head title="Caja de Multas" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Caja de Multas"
                    description="El efectivo y los cheques de la caja, y la cuenta bancaria con la que opera."
                />

                {opening ? (
                    <>
                        <AperturaHecha existing={opening} />
                        {bank && <PanelBancario banco={bank} />}
                        <Cobros
                            titulo="Cobros por depositar"
                            vacio="No hay cobros en la caja esperando el depósito."
                            cobros={paraDepositar}
                            {...permisos}
                        />
                        <Cobros
                            titulo="Depositado, sin acreditar"
                            vacio="No hay depósitos esperando al extracto."
                            cobros={enTransito}
                            {...permisos}
                        />
                        {/*
                         * Un rechazo sigue acá mientras falte algo: el
                         * reemplazo de la empresa, un hecho del banco o los
                         * papeles. Con reemplazo y débito pendiente, también.
                         */}
                        <Cobros
                            titulo="Cheques rechazados con algo pendiente"
                            vacio="No hay cheques rechazados con algo pendiente."
                            cobros={rechazados}
                            {...permisos}
                        />
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
        date: primera?.cutoffOn
            ? diaSiguiente(primera.cutoffOn)
            : businessToday(),
        bankAccountId: primera?.id ?? null,
        balances: {},
        denominations: {},
        cheques: [],
        notes: '',
        declaredEmpty: false,
    });

    const errores = form.errors as unknown as Record<string, string>;
    const cuenta = cuentas.find((c) => c.id === form.data.bankAccountId);
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
                            onValueChange={(valor) => {
                                const elegida = cuentas.find(
                                    (c) => c.id === Number(valor),
                                );
                                form.setData({
                                    ...form.data,
                                    bankAccountId: Number(valor),
                                    date: elegida?.cutoffOn
                                        ? diaSiguiente(elegida.cutoffOn)
                                        : form.data.date,
                                });
                            }}
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
                            {cuenta?.cutoffOn
                                ? `Su saldo está declarado al ${date(cuenta.cutoffOn)}: la caja abre después.`
                                : 'Su saldo no se suma acá: es compartido y se declara para la cuenta en Banco › Cuentas.'}
                        </p>
                        <InputError message={form.errors.bankAccountId} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="date">Fecha de la apertura</Label>
                        <Input
                            id="date"
                            type="date"
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
                                <Input
                                    readOnly
                                    aria-label="Efectivo"
                                    className="w-44 bg-muted/50 text-right font-mono text-muted-foreground tabular-nums"
                                    value={contado}
                                />
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
                                <Input
                                    id={CHEQUES}
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    className="w-44 text-right font-mono tabular-nums"
                                    value={form.data.balances[CHEQUES] ?? ''}
                                    onChange={(e) =>
                                        form.setData('balances', {
                                            ...form.data.balances,
                                            [CHEQUES]: e.target.value,
                                        })
                                    }
                                />
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

/**
 * Los dos números de la cuenta, separados y nunca sumados (M08, M12).
 */
function PanelBancario({ banco }: { banco: Banco }) {
    return (
        <section
            aria-label="Cuenta bancaria"
            className="rounded-lg border bg-card shadow-raised"
        >
            <div className="flex items-center gap-2 border-b px-5 py-3">
                <Landmark
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 className="text-sm font-semibold">
                    {banco.bankAccountLabel}
                </h2>
            </div>

            <div className="grid gap-px bg-border sm:grid-cols-2">
                <div className="bg-card p-5">
                    <p className="text-xs tracking-wide text-field-label uppercase">
                        Saldo de la cuenta según el banco
                    </p>
                    <p className="mt-1 text-lg">
                        <Money value={banco.latestStatementBalance} />
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {banco.latestStatementDate
                            ? `Último extracto: ${date(banco.latestStatementDate)}. `
                            : 'Sin extractos importados. '}
                        Es la cuenta entera, compartida con Aranceles: no es
                        plata de Multas.
                    </p>
                    <p className="mt-2 text-xs text-muted-foreground">
                        {banco.cutoffOn ? (
                            <>
                                Saldo declarado al {date(banco.cutoffOn)}:{' '}
                                <Money value={banco.cutoffBalance} />, sin
                                repartir.
                            </>
                        ) : (
                            <span className="text-warning-strong">
                                La cuenta todavía no tiene su corte declarado en
                                Banco › Cuentas.
                            </span>
                        )}
                    </p>
                </div>

                <div className="bg-card p-5">
                    <p className="flex items-center gap-1.5 text-xs tracking-wide text-field-label uppercase">
                        <Wallet className="size-3.5" aria-hidden="true" />
                        Cobros de Multas en el banco
                    </p>
                    <p className="mt-1 text-lg font-semibold">
                        <Money value={banco.bookBalance} />
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Lo que el libro de Multas tiene en la cuenta: los
                        depósitos de la caja ya acreditados y los pagos por el
                        banco ya verificados.
                    </p>
                    {isNonZero(banco.unclassifiedAmount) && (
                        <p className="mt-2 text-xs text-warning-strong">
                            {banco.unclassifiedCount}{' '}
                            {banco.unclassifiedCount === 1
                                ? 'crédito posterior al corte sin reconocer'
                                : 'créditos posteriores al corte sin reconocer'}{' '}
                            por <Money value={banco.unclassifiedAmount} />:
                            todavía no se sabe de qué caja{' '}
                            {banco.unclassifiedCount === 1 ? 'es' : 'son'}.
                        </p>
                    )}
                </div>
            </div>
        </section>
    );
}

/**
 * Los cobros de la caja en un mismo lugar —por depositar, o depositados
 * esperando al extracto—, del más viejo al más nuevo. Cada uno va al banco
 * entero y en su propio depósito (M06).
 */
function Cobros({
    titulo,
    vacio,
    cobros,
    canDeposit,
    canConfirmDeposit,
    canViewAttachments,
    canManageRejections,
    canReplaceCheque,
}: {
    titulo: string;
    vacio: string;
    cobros: Cobro[];
    canDeposit: boolean;
    canConfirmDeposit: boolean;
    canViewAttachments: boolean;
    canManageRejections: boolean;
    canReplaceCheque: boolean;
}) {
    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
                <h2 className="text-sm font-semibold">{titulo}</h2>
                <StatusBadge
                    label={String(cobros.length)}
                    tone={cobros.length > 0 ? 'action' : 'neutral'}
                />
            </div>
            {cobros.length === 0 ? (
                <p className="px-5 py-4 text-sm text-muted-foreground">
                    {vacio}
                </p>
            ) : (
                <ul className="divide-y">
                    {cobros.map((fila) => (
                        <li
                            key={fila.payment.id}
                            className="grid gap-2 px-5 py-3 text-sm"
                        >
                            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                                <Link
                                    href={verMulta(fila.fineId)}
                                    className="font-medium hover:underline"
                                >
                                    {fila.fineCaption}
                                </Link>
                                <span className="font-mono text-xs text-muted-foreground">
                                    Expte. {fila.caseLabel}
                                </span>
                                <span>
                                    {PAYMENT_MEDIUM[fila.payment.medium]}
                                    {(fila.payment.deposit?.chequeNumber ??
                                        fila.payment.chequeNumber) &&
                                        ` n.º ${fila.payment.deposit?.chequeNumber ?? fila.payment.chequeNumber}`}{' '}
                                    · <Money value={fila.payment.amount} />
                                </span>
                                <span className="w-full text-xs text-muted-foreground sm:ml-auto sm:w-auto">
                                    Recibo{' '}
                                    {fila.payment.talonarioNumber ??
                                        fila.payment.receiptNumber ??
                                        '—'}
                                    {fila.payment.receiptDate &&
                                        ` del ${date(fila.payment.receiptDate)}`}
                                </span>
                            </div>
                            {fila.payment.deposit && (
                                <CollectionDeposit
                                    paymentId={fila.payment.id}
                                    amount={fila.payment.amount}
                                    deposito={fila.payment.deposit}
                                    desde="caja"
                                    canDeposit={canDeposit}
                                    canConfirm={canConfirmDeposit}
                                    canViewAttachments={canViewAttachments}
                                    isCheque={fila.payment.medium === 'cheque'}
                                    canManageRejections={canManageRejections}
                                    canReplaceCheque={canReplaceCheque}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
