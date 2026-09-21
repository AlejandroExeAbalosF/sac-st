import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, FileUp, Link2, Pencil, X } from 'lucide-react';
import { useRef, useState } from 'react';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { date } from '@/lib/format';
import { preview } from '@/routes/adjuntos';
import { search as buscarTicket, update as corregir } from '@/routes/depositos';

type Ticket = App.Modules.Haberes.Data.InstallmentTicketData;
type Cuenta = { id: number; label: string };

const TIPO: Record<App.Modules.Haberes.Enums.DepositKind, string> = {
    cash_deposit: 'Depósito en efectivo',
    transfer: 'Transferencia',
    cheque_deposit: 'Depósito de cheque',
};

/**
 * El comprobante, sin salir del haber.
 *
 * Mirar qué decía el papel es una consulta de segundos y no merece
 * cambiar de pantalla. Corregir un dígito, tampoco: por eso el mismo
 * modal alterna a edición en vez de navegar, y la foto queda a la vista
 * mientras se corrige —que es justamente contra lo que se compara—.
 *
 * **Solo mientras el ticket espera.** Vinculado a un movimiento sus datos
 * ya son la base de una afirmación hecha, y el modal lo dice en vez de
 * ofrecer un botón que iba a fallar.
 */
export default function DepositTicketDialog({
    ticket,
    cuentas,
    puedeEditar,
    abierto,
    onCerrar,
}: {
    ticket: Ticket;
    cuentas: Cuenta[];
    puedeEditar: boolean;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [editando, setEditando] = useState(false);

    const editable = puedeEditar && ticket.editable;

    /*
     * Cerrar vuelve a modo lectura: reabrirlo directo en edición
     * sorprendería. Va acá y no en un efecto porque es la consecuencia de
     * una acción, no una sincronización con nada externo.
     */
    const cerrar = () => {
        setEditando(false);
        onCerrar();
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && cerrar()}>
            <DialogContent className="max-h-[90vh] gap-0 overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>
                        {editando
                            ? 'Corregir el comprobante'
                            : 'Comprobante del depósito'}
                    </DialogTitle>
                    <DialogDescription>
                        {editando
                            ? 'Lo que se corrige es la transcripción del papel, no el depósito.'
                            : `Depositado el ${date(ticket.depositedAt)} en ${ticket.accountLabel}.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-4 grid gap-5 sm:grid-cols-[1fr_18rem]">
                    <div>
                        {editando ? (
                            <Formulario
                                ticket={ticket}
                                cuentas={cuentas}
                                onListo={() => setEditando(false)}
                            />
                        ) : (
                            <Lectura ticket={ticket} />
                        )}
                    </div>

                    <Foto attachmentId={ticket.attachmentId} />
                </div>

                {!editando && (
                    <DialogFooter className="mt-6 gap-2 sm:justify-between">
                        <div className="text-xs text-muted-foreground">
                            {ticket.bankTransactionId !== null ? (
                                <span className="flex items-center gap-1.5">
                                    <Link2 className="size-3.5" />
                                    Vinculado a un movimiento del extracto. Para
                                    corregirlo hay que desvincularlo.
                                </span>
                            ) : (
                                `Esperando en el banco hace ${ticket.waitingDays} ${
                                    ticket.waitingDays === 1 ? 'día' : 'días'
                                }.`
                            )}
                        </div>

                        <div className="flex gap-2">
                            {editable && (
                                <Button
                                    variant="outline"
                                    onClick={() => setEditando(true)}
                                >
                                    <Pencil className="size-4" />
                                    Corregir
                                </Button>
                            )}
                            <Button asChild>
                                <Link href={buscarTicket(ticket.id)}>
                                    {ticket.bankTransactionId === null
                                        ? 'Buscar en el extracto'
                                        : 'Ver el cruce'}
                                </Link>
                            </Button>
                        </div>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Lectura({ ticket }: { ticket: Ticket }) {
    return (
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
            <Dato etiqueta="Fecha" valor={date(ticket.depositedAt)} />
            <Dato etiqueta="Hora" valor={ticket.depositedTime ?? '—'} />
            <div>
                <dt className="text-xs text-field-label">Importe</dt>
                <dd>
                    <Money value={ticket.amount} className="font-semibold" />
                </dd>
            </div>
            <Dato etiqueta="Tipo" valor={TIPO[ticket.depositKind]} />
            <Dato etiqueta="Cuenta" valor={ticket.accountLabel} />
            <Dato
                etiqueta="Nº de operación"
                valor={ticket.operationNumber ?? '—'}
            />
            <Dato etiqueta="Terminal" valor={ticket.terminal ?? '—'} />
            {ticket.notes && (
                <div className="sm:col-span-2">
                    <Dato etiqueta="Observaciones" valor={ticket.notes} />
                </div>
            )}
        </dl>
    );
}

/**
 * El formulario de corrección.
 *
 * No incluye el expediente ni el beneficiario: eso no es una corrección
 * de lo transcripto. Si el papel resultó ser de otro expediente, el
 * ticket se descarta y se carga el que corresponde.
 */
function Formulario({
    ticket,
    cuentas,
    onListo,
}: {
    ticket: Ticket;
    cuentas: Cuenta[];
    onListo: () => void;
}) {
    const inputFoto = useRef<HTMLInputElement>(null);
    const [foto, setFoto] = useState<File | null>(null);

    const form = useForm({
        bankAccountId: ticket.bankAccountId.toString(),
        depositedAt: ticket.depositedAt,
        depositedTime: ticket.depositedTime ?? '',
        amount: ticket.amount,
        operationNumber: ticket.operationNumber ?? '',
        terminal: ticket.terminal ?? '',
        depositKind: ticket.depositKind as string,
        notes: ticket.notes ?? '',
        photo: null as File | null,
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(corregir(ticket.id).url, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onListo(),
        });
    };

    return (
        <form onSubmit={enviar} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="depositedAt">Fecha del depósito</Label>
                    <Input
                        id="depositedAt"
                        type="date"
                        value={form.data.depositedAt}
                        onChange={(e) =>
                            form.setData('depositedAt', e.target.value)
                        }
                    />
                    <InputError message={form.errors.depositedAt} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="depositedTime">
                        Hora
                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                            si figura
                        </span>
                    </Label>
                    <Input
                        id="depositedTime"
                        type="time"
                        value={form.data.depositedTime}
                        onChange={(e) =>
                            form.setData('depositedTime', e.target.value)
                        }
                    />
                    <InputError message={form.errors.depositedTime} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="amount">Importe</Label>
                    <Input
                        id="amount"
                        inputMode="decimal"
                        value={form.data.amount}
                        onChange={(e) => form.setData('amount', e.target.value)}
                        className="font-mono tabular-nums"
                    />
                    <InputError message={form.errors.amount} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="depositKind">Tipo</Label>
                    <Select
                        value={form.data.depositKind}
                        onValueChange={(v) => form.setData('depositKind', v)}
                    >
                        <SelectTrigger id="depositKind">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(TIPO).map(([valor, etiqueta]) => (
                                <SelectItem key={valor} value={valor}>
                                    {etiqueta}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.depositKind} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="bankAccountId">Cuenta</Label>
                    <Select
                        value={form.data.bankAccountId}
                        onValueChange={(v) => form.setData('bankAccountId', v)}
                    >
                        <SelectTrigger id="bankAccountId">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {cuentas.map((cuenta) => (
                                <SelectItem
                                    key={cuenta.id}
                                    value={cuenta.id.toString()}
                                >
                                    {cuenta.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.bankAccountId} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="operationNumber">Nº de operación</Label>
                    <Input
                        id="operationNumber"
                        value={form.data.operationNumber}
                        onChange={(e) =>
                            form.setData('operationNumber', e.target.value)
                        }
                        className="tabular-nums"
                    />
                    <InputError message={form.errors.operationNumber} />
                </div>

                <div className="space-y-1.5">
                    <Label htmlFor="terminal">Terminal</Label>
                    <Input
                        id="terminal"
                        value={form.data.terminal}
                        onChange={(e) =>
                            form.setData('terminal', e.target.value)
                        }
                    />
                    <InputError message={form.errors.terminal} />
                </div>

                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="notes">Observaciones</Label>
                    <Input
                        id="notes"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                    <InputError message={form.errors.notes} />
                </div>
            </div>

            {/*
             * La foto no se reemplaza: se agrega otra y la anterior queda
             * como rastro, porque `attachments` es inmutable. Por eso el
             * texto dice «agregar», no «cambiar».
             */}
            <div className="space-y-1.5">
                <Label>Foto del comprobante</Label>
                <input
                    ref={inputFoto}
                    type="file"
                    accept="image/*,application/pdf"
                    className="hidden"
                    onChange={(e) => {
                        const archivo = e.target.files?.[0] ?? null;
                        setFoto(archivo);
                        form.setData('photo', archivo);
                    }}
                />
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => inputFoto.current?.click()}
                    >
                        <FileUp className="size-4" />
                        {ticket.attachmentId === null
                            ? 'Agregar foto'
                            : 'Reemplazar por otra foto'}
                    </Button>
                    {foto && (
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            {foto.name}
                            <button
                                type="button"
                                onClick={() => {
                                    setFoto(null);
                                    form.setData('photo', null);

                                    if (inputFoto.current) {
                                        inputFoto.current.value = '';
                                    }
                                }}
                                aria-label="Quitar la foto elegida"
                            >
                                <X className="size-3.5" />
                            </button>
                        </span>
                    )}
                </div>
                {ticket.attachmentId !== null && (
                    <p className="text-xs text-muted-foreground">
                        La foto anterior no se borra: queda como rastro de lo
                        que se estuvo mirando.
                    </p>
                )}
                <InputError message={form.errors.photo} />
            </div>

            <div className="flex justify-end gap-2 pt-2">
                <Button type="button" variant="ghost" onClick={onListo}>
                    Cancelar
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Guardar corrección
                </Button>
            </div>
        </form>
    );
}

function Foto({ attachmentId }: { attachmentId: number | null }) {
    if (attachmentId === null) {
        return (
            <div className="flex h-fit flex-col items-center gap-2 rounded-lg border border-dashed px-4 py-10 text-center">
                <AlertTriangle className="size-5 text-warning-strong" />
                <p className="text-sm font-medium">Sin foto</p>
                <p className="text-xs text-muted-foreground">
                    El área no la exige, pero sin ella no hay contra qué
                    comparar lo que se cargó.
                </p>
            </div>
        );
    }

    return (
        <a
            href={preview(attachmentId).url}
            target="_blank"
            rel="noreferrer"
            title="Abrir el comprobante en tamaño completo"
            className="h-fit rounded-lg border p-2"
        >
            <img
                src={preview(attachmentId).url}
                alt="Comprobante del depósito"
                className="w-full rounded"
            />
        </a>
    );
}

function Dato({ etiqueta, valor }: { etiqueta: string; valor: string }) {
    return (
        <div>
            <dt className="text-xs text-field-label">{etiqueta}</dt>
            <dd className="break-words">{valor}</dd>
        </div>
    );
}
