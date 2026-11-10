import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    FileCheck,
    Landmark,
    Paperclip,
    Undo2,
    WalletCards,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
import ListViewTab from '@/components/list-view-tab';
import PageHeader from '@/components/page-header';
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
import CancelTransferDialog from '@/features/banking/components/cancel-transfer-dialog';
import CreditMatchDialog from '@/features/banking/components/credit-match-dialog';
import CashBoxNotReady from '@/features/caja/components/cash-box-not-ready';
import { useCuentasDelDia } from '@/features/caja/cuentas-del-dia';
import type { PeriodoDeCuenta } from '@/features/caja/cuentas-del-dia';
import { businessToday, date, dateTime, money } from '@/lib/format';
import { download, preview } from '@/routes/adjuntos';
import {
    cancel,
    candidates,
    confirm,
    index,
    reparto,
    store,
} from '@/routes/aranceles/depositos';
import { create as cargarComprobante } from '@/routes/aranceles/depositos/comprobante';

type Deposito = App.Modules.Aranceles.Data.FeeDepositData;
type Parte = App.Modules.Aranceles.Data.FeeCashShareData;
type Pestana = 'comprobante' | 'transito' | 'acreditados' | 'cancelados';
type Permisos = {
    deposit: boolean;
    adjustTransfer: boolean;
    confirm: boolean;
    cancel: boolean;
    viewAttachments: boolean;
};

type Props = {
    tab: Pestana;
    available: string;
    isOpen: boolean;
    /** Si quien mira puede abrir la caja: si no, el aviso no lleva a un 403. */
    canOpenCash: boolean;
    cuentas: PeriodoDeCuenta[];
    sinComprobante: Deposito[];
    enTransito: Deposito[];
    acreditados: Deposito[];
    cancelados: Deposito[];
    can: Permisos;
};

/**
 * El efectivo de Aranceles camino al banco, en dos pasos (reglas A83 a A89a).
 *
 * El traspaso se registra cuando el efectivo sale: la caja queda igual al
 * cajón y un comprobante pendiente no frena ni el cierre ni los cobros del
 * día siguiente. Después se carga el comprobante y se acredita con el extracto.
 */
export default function Depositos({
    tab,
    available,
    isOpen,
    canOpenCash,
    cuentas,
    sinComprobante,
    enTransito,
    acreditados,
    cancelados,
    can,
}: Props) {
    const [traspasando, setTraspasando] = useState(false);

    const pestanas: {
        valor: Pestana;
        titulo: string;
        filas: Deposito[];
        vacio: string;
    }[] = [
        {
            valor: 'comprobante',
            titulo: 'Sin comprobante',
            filas: sinComprobante,
            vacio: 'No hay traspasos esperando el comprobante.',
        },
        {
            valor: 'transito',
            titulo: 'Sin acreditar',
            filas: enTransito,
            vacio: 'No hay traspasos esperando al extracto.',
        },
        {
            valor: 'acreditados',
            titulo: 'Acreditados',
            filas: acreditados,
            vacio: 'Todavía no hay traspasos acreditados.',
        },
        {
            valor: 'cancelados',
            titulo: 'Cancelados',
            filas: cancelados,
            vacio: 'No hay traspasos cancelados.',
        },
    ];

    const actual = pestanas.find((p) => p.valor === tab) ?? pestanas[0];

    return (
        <>
            <Head title="Traspasos de aranceles" />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Traspasos"
                    description="El efectivo sale de la caja con el traspaso. El comprobante y la acreditación se cargan después, sin frenar el trabajo del día."
                />

                <section className="flex flex-wrap items-center justify-between gap-4 rounded-lg border bg-card p-5 shadow-raised">
                    <div>
                        <p className="text-xs text-field-label">
                            Efectivo para traspasar
                        </p>
                        <p className="font-mono text-2xl font-semibold tabular-nums">
                            {money(available)}
                        </p>
                    </div>
                    {can.deposit && isOpen && (
                        <Button onClick={() => setTraspasando(true)}>
                            <WalletCards
                                className="size-4"
                                aria-hidden="true"
                            />
                            Registrar traspaso
                        </Button>
                    )}
                    {!isOpen && (
                        <CashBoxNotReady
                            caja="aranceles"
                            pendiente={{ canFix: canOpenCash }}
                            consecuencia="Hasta abrirla no se registran traspasos."
                        />
                    )}
                </section>

                <nav
                    aria-label="Depósitos"
                    className="-mb-2 flex overflow-x-auto overflow-y-hidden border-b"
                >
                    {pestanas.map((p) => (
                        <ListViewTab
                            key={p.valor}
                            active={actual.valor === p.valor}
                            onClick={() =>
                                router.get(
                                    index({ query: { pestana: p.valor } }).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        preserveState: true,
                                        replace: true,
                                    },
                                )
                            }
                        >
                            {p.titulo}
                            <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                                {p.filas.length}
                            </span>
                        </ListViewTab>
                    ))}
                </nav>

                {actual.filas.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {actual.vacio}
                    </p>
                ) : (
                    <ul className="divide-y rounded-lg border bg-card shadow-raised">
                        {actual.filas.map((deposito) => (
                            <FilaDeDeposito
                                key={deposito.id}
                                deposito={deposito}
                                can={can}
                            />
                        ))}
                    </ul>
                )}
            </div>

            {traspasando && (
                <NuevoTraspaso
                    disponible={available}
                    puedeAjustar={can.adjustTransfer}
                    cuentas={cuentas}
                    onCerrar={() => setTraspasando(false)}
                />
            )}
        </>
    );
}

function FilaDeDeposito({
    deposito,
    can,
}: {
    deposito: Deposito;
    can: Permisos;
}) {
    const [dialogo, setDialogo] = useState<'acreditar' | 'cancelar' | null>(
        null,
    );
    const traslado = {
        id: deposito.id,
        amount: deposito.amount,
        depositDate: deposito.ticketDate ?? deposito.depositDate,
    };
    const esperaComprobante = deposito.status === 'awaiting_ticket';
    const enTransito = deposito.status === 'deposited';

    return (
        <li className="grid gap-3 p-5 text-sm">
            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                <span className="font-mono text-base font-semibold tabular-nums">
                    {money(deposito.amount)}
                </span>
                <span>Traspaso del {date(deposito.depositDate)}</span>
                <span className="text-xs text-muted-foreground">
                    Registrado el {dateTime(deposito.registeredAt)} ·{' '}
                    {deposito.accountLabel}
                    {deposito.depositedByName &&
                        ` · ${deposito.depositedByName}`}
                </span>
            </div>

            {(deposito.ticketDate ||
                deposito.creditedOn ||
                deposito.notes ||
                (can.viewAttachments &&
                    deposito.ticketAttachmentId !== null)) && (
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                    {deposito.ticketDate && (
                        <span>
                            Depositado el {date(deposito.ticketDate)}
                            {deposito.operationNumber &&
                                ` · operación ${deposito.operationNumber}`}
                            {deposito.terminal &&
                                ` · terminal ${deposito.terminal}`}
                        </span>
                    )}
                    {deposito.creditedOn && (
                        <span>Acreditado el {date(deposito.creditedOn)}</span>
                    )}
                    {can.viewAttachments &&
                        deposito.ticketAttachmentId !== null && (
                            <a
                                href={
                                    deposito.ticketViewable
                                        ? preview(deposito.ticketAttachmentId)
                                              .url
                                        : download(deposito.ticketAttachmentId)
                                              .url
                                }
                                target={
                                    deposito.ticketViewable
                                        ? '_blank'
                                        : undefined
                                }
                                rel="noreferrer"
                                className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                            >
                                <Paperclip
                                    className="size-3"
                                    aria-hidden="true"
                                />
                                Ver comprobante
                            </a>
                        )}
                    {deposito.notes && <span>{deposito.notes}</span>}
                </p>
            )}

            <Reparto partes={deposito.shares} />

            {(esperaComprobante || enTransito) && (
                <div className="flex flex-wrap gap-2">
                    {esperaComprobante && can.deposit && (
                        <Button asChild size="sm">
                            <Link href={cargarComprobante(deposito.id)}>
                                <FileCheck aria-hidden="true" />
                                Cargar comprobante
                            </Link>
                        </Button>
                    )}
                    {enTransito && can.confirm && (
                        <Button
                            size="sm"
                            onClick={() => setDialogo('acreditar')}
                        >
                            <Landmark aria-hidden="true" />
                            Acreditar
                        </Button>
                    )}
                    {can.cancel && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setDialogo('cancelar')}
                        >
                            <Undo2 aria-hidden="true" />
                            Cancelar
                        </Button>
                    )}
                </div>
            )}

            {dialogo === 'acreditar' && (
                <CreditMatchDialog
                    traslado={traslado}
                    candidatesUrl={candidates(deposito.id).url}
                    confirmUrl={confirm(deposito.id).url}
                    abierto
                    onCerrar={() => setDialogo(null)}
                />
            )}
            {dialogo === 'cancelar' && (
                <CancelTransferDialog
                    traslado={traslado}
                    url={cancel(deposito.id).url}
                    titulo="Cancelar el traspaso"
                    consecuencia="El efectivo vuelve a figurar en la caja y todo su reparto queda libre."
                    abierto
                    onCerrar={() => setDialogo(null)}
                />
            )}
        </li>
    );
}

/** De qué recibos sale la plata, en el orden en que se reparte. */
function Reparto({ partes }: { partes: Parte[] }) {
    return (
        <ul className="grid gap-1 rounded-md bg-muted/40 p-3 text-xs">
            {partes.map((parte, i) => (
                <li
                    key={i}
                    className="flex flex-wrap items-baseline justify-between gap-x-4"
                >
                    <span className="min-w-0">
                        {parte.opening
                            ? 'Efectivo de la apertura'
                            : `Recibo ${parte.receiptNumber ?? '—'}`}
                        {parte.payerName && ` · ${parte.payerName}`}
                        <span className="text-muted-foreground">
                            {' '}
                            · {date(parte.receivedOn)}
                        </span>
                    </span>
                    <span className="font-mono tabular-nums">
                        {money(parte.amount)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

type VistaPrevia = { available: string; exceeds: boolean; shares: Parte[] };

function NuevoTraspaso({
    disponible,
    puedeAjustar,
    cuentas,
    onCerrar,
}: {
    disponible: string;
    puedeAjustar: boolean;
    cuentas: PeriodoDeCuenta[];
    onCerrar: () => void;
}) {
    const [clave] = useState(() => `traspaso-arancel:${crypto.randomUUID()}`);
    const [vista, setVista] = useState<VistaPrevia | null>(null);

    // Sin el permiso la fecha no viaja, y el importe es todo lo disponible.
    const form = useForm({
        transferDate: puedeAjustar ? businessToday() : '',
        amount: disponible,
        bankAccountId: '',
        notes: '',
        idempotencyKey: clave,
    });

    const { transferDate } = form.data;
    // La prop, no el formulario: si un rechazo avisa que cambió, se ve y se manda el nuevo.
    const amount = puedeAjustar ? form.data.amount : disponible;
    const fecha = transferDate || businessToday();

    const vigentes = useCuentasDelDia(
        cuentas,
        fecha,
        form.data.bankAccountId,
        (id) => form.setData('bankAccountId', id),
    );

    // El reparto lo calcula el servidor: acá no se opera con importes.
    useEffect(() => {
        let vigente = true;
        const espera = setTimeout(() => {
            fetch(
                reparto({
                    query: {
                        ...(transferDate === '' ? {} : { transferDate }),
                        ...(amount === '' ? {} : { amount }),
                    },
                }).url,
                { headers: { Accept: 'application/json' } },
            )
                .then((r) => (r.ok ? r.json() : null))
                .then((d: VistaPrevia | null) => vigente && setVista(d))
                .catch(() => vigente && setVista(null));
        }, 250);

        return () => {
            vigente = false;
            clearTimeout(espera);
        };
    }, [transferDate, amount]);

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((datos) => ({ ...datos, amount }));
        form.post(store().url, { onSuccess: () => onCerrar() });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onCerrar()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        Registrar el traspaso del efectivo
                    </DialogTitle>
                    <DialogDescription>
                        El efectivo sale de la caja ahora. El comprobante del
                        banco se carga después.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            {puedeAjustar ? (
                                <>
                                    <Label htmlFor="traspaso-importe">
                                        Importe
                                    </Label>
                                    <AmountInput
                                        id="traspaso-importe"
                                        value={form.data.amount}
                                        onChange={(valor) =>
                                            form.setData('amount', valor)
                                        }
                                    />
                                </>
                            ) : (
                                <>
                                    <p className="text-xs text-field-label">
                                        Importe
                                    </p>
                                    <p className="text-sm">
                                        {money(amount)}, todo lo disponible
                                    </p>
                                </>
                            )}
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            {puedeAjustar ? (
                                <>
                                    <Label htmlFor="traspaso-fecha">
                                        Fecha
                                    </Label>
                                    <DateInput
                                        id="traspaso-fecha"
                                        max={businessToday()}
                                        value={form.data.transferDate}
                                        onChange={(e) =>
                                            form.setData(
                                                'transferDate',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </>
                            ) : (
                                <>
                                    <p className="text-xs text-field-label">
                                        Fecha
                                    </p>
                                    <p className="text-sm">
                                        {date(fecha)}, al confirmar
                                    </p>
                                </>
                            )}
                            <InputError message={form.errors.transferDate} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        {vigentes.length > 1 ? (
                            <>
                                <Label htmlFor="traspaso-cuenta">Cuenta</Label>
                                <Select
                                    value={form.data.bankAccountId}
                                    onValueChange={(v) =>
                                        form.setData('bankAccountId', v)
                                    }
                                >
                                    <SelectTrigger id="traspaso-cuenta">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {vigentes.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.label}
                                                {c.isDefault &&
                                                    ' (predeterminada)'}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </>
                        ) : (
                            <>
                                <p className="text-xs text-field-label">
                                    Cuenta
                                </p>
                                <p className="text-sm">
                                    {vigentes[0]?.label ??
                                        'Ninguna cuenta de la caja vale ese día.'}
                                </p>
                            </>
                        )}
                        <InputError message={form.errors.bankAccountId} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="traspaso-notas">
                            Observaciones
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                opcional
                            </span>
                        </Label>
                        <Input
                            id="traspaso-notas"
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    {vista && (
                        <div className="grid gap-2">
                            <p className="text-xs text-field-label">
                                Al {date(fecha)} hay {money(vista.available)}{' '}
                                para traspasar. Sale de:
                            </p>
                            {vista.exceeds && (
                                <p className="text-sm text-warning-strong">
                                    El importe supera lo que se puede traspasar
                                    a esa fecha.
                                </p>
                            )}
                            {vista.shares.length > 0 && (
                                <Reparto partes={vista.shares} />
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                vigentes.length === 0 ||
                                vista === null ||
                                vista.exceeds
                            }
                        >
                            <WalletCards
                                className="size-4"
                                aria-hidden="true"
                            />
                            Registrar traspaso
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
