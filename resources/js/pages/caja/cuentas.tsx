import { Head, router, useForm } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { rutasDeCaja } from '@/features/caja/rutas';
import type { RutasDeCaja } from '@/features/caja/rutas';
import { date, dateTime } from '@/lib/format';

type Cuenta = {
    id: number;
    bankAccountId: number;
    label: string;
    accountNumber: string | null;
    citation: string | null;
    currency: string;
    validFrom: string | null;
    validUntil: string | null;
    isDefault: boolean;
    active: boolean;
    createdBy: string | null;
    createdAt: string;
};

type Disponible = { id: number; label: string; currency: string };

type Dialogo =
    { tipo: 'baja'; cuenta: Cuenta } | { tipo: 'inicio'; cuenta: Cuenta };

/**
 * Las cuentas bancarias con las que opera una caja.
 *
 * Cada una vale desde una fecha —vacía es «inicio histórico desconocido»—
 * hasta su baja, que incluye ese día. Depositar e identificar un crédito se
 * hacen solo con las vigentes; lo que ya se hizo con una cuenta dada de baja
 * sigue: un depósito en tránsito se acredita contra ella. Hay exactamente una
 * predeterminada por moneda mientras haya alguna vigente: la que se propone
 * al depositar.
 */
export default function CuentasDeLaCaja({
    cashBox,
    accounts,
    available,
    today,
    can,
}: {
    cashBox: { code: string; name: string };
    accounts: Cuenta[];
    available: Disponible[];
    today: string;
    can: { manage: boolean };
}) {
    const rutas = rutasDeCaja(cashBox.code);
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);

    return (
        <>
            <Head title={`Cuentas de la caja · ${cashBox.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow={cashBox.name}
                    title="Cuentas de la caja"
                    description="Las cuentas bancarias con las que opera la caja: dónde se deposita y de qué cuentas se ofrecen créditos para identificar. Que se ofrezca un crédito no lo hace de esta caja."
                />

                <section className="rounded-lg border bg-card shadow-raised">
                    {accounts.length === 0 ? (
                        <p className="px-5 py-6 text-sm text-muted-foreground">
                            La caja todavía no tiene cuentas habilitadas.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {accounts.map((c) => (
                                <FilaDeCuenta
                                    key={c.id}
                                    cuenta={c}
                                    puede={can.manage}
                                    rutas={rutas}
                                    onDialogo={setDialogo}
                                />
                            ))}
                        </ul>
                    )}
                </section>

                {can.manage && available.length > 0 && (
                    <HabilitarCuenta
                        disponibles={available}
                        hoy={today}
                        rutas={rutas}
                    />
                )}
            </div>

            {dialogo?.tipo === 'baja' && (
                <DarDeBaja
                    cuenta={dialogo.cuenta}
                    otras={accounts.filter(
                        (c) =>
                            c.id !== dialogo.cuenta.id &&
                            c.currency === dialogo.cuenta.currency &&
                            c.validUntil === null,
                    )}
                    hoy={today}
                    rutas={rutas}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo?.tipo === 'inicio' && (
                <CambiarInicio
                    cuenta={dialogo.cuenta}
                    hoy={today}
                    rutas={rutas}
                    onClose={() => setDialogo(null)}
                />
            )}
        </>
    );
}

function FilaDeCuenta({
    cuenta,
    puede,
    rutas,
    onDialogo,
}: {
    cuenta: Cuenta;
    puede: boolean;
    rutas: RutasDeCaja;
    onDialogo: (d: Dialogo) => void;
}) {
    const vigente = cuenta.validUntil === null;

    return (
        <li className="grid gap-2 px-5 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Landmark
                    className="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <span className="font-medium">{cuenta.label}</span>
                <span className="text-xs text-muted-foreground">
                    {cuenta.currency}
                    {cuenta.accountNumber && ` · ${cuenta.accountNumber}`}
                </span>
                {cuenta.isDefault && (
                    <StatusBadge label="Predeterminada" tone="done" />
                )}
                {!vigente && (
                    <StatusBadge label="Dada de baja" tone="neutral" />
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Vale desde{' '}
                {cuenta.validFrom === null
                    ? 'su inicio histórico (sin fecha conocida)'
                    : `el ${date(cuenta.validFrom)}`}
                {cuenta.validUntil !== null &&
                    ` hasta el ${date(cuenta.validUntil)}, inclusive`}
                . Cargada{cuenta.createdBy && ` por ${cuenta.createdBy}`} el{' '}
                {dateTime(cuenta.createdAt)}.
                {cuenta.citation && ` Se cita como «${cuenta.citation}».`}
            </p>
            {puede && (
                <div className="flex flex-wrap gap-2">
                    {vigente && !cuenta.isDefault && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    rutas.cuentas.default({ link: cuenta.id })
                                        .url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Hacer predeterminada
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onDialogo({ tipo: 'inicio', cuenta })}
                    >
                        Cambiar desde cuándo vale
                    </Button>
                    {vigente && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onDialogo({ tipo: 'baja', cuenta })}
                        >
                            Dar de baja
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

/** Habilitar otra cuenta, o volver a habilitar una dada de baja. */
function HabilitarCuenta({
    disponibles,
    hoy,
    rutas,
}: {
    disponibles: Disponible[];
    hoy: string;
    rutas: RutasDeCaja;
}) {
    const form = useForm({
        bankAccountId: '',
        validFrom: hoy,
        makeDefault: false,
    });

    return (
        <section className="rounded-lg border bg-card p-5 shadow-raised">
            <h2 className="text-sm font-semibold">Habilitar una cuenta</h2>
            <form
                className="mt-4 grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(rutas.cuentas.store().url, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <div className="grid content-start gap-2">
                    <Label htmlFor="cuenta-nueva">Cuenta</Label>
                    <Select
                        value={form.data.bankAccountId}
                        onValueChange={(v) => form.setData('bankAccountId', v)}
                    >
                        <SelectTrigger id="cuenta-nueva" className="w-full">
                            <SelectValue placeholder="Elegí la cuenta" />
                        </SelectTrigger>
                        <SelectContent>
                            {disponibles.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.label} · {d.currency}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={form.errors.bankAccountId} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="cuenta-desde">Vale desde</Label>
                    <DateInput
                        id="cuenta-desde"
                        max={hoy}
                        value={form.data.validFrom}
                        onChange={(e) =>
                            form.setData('validFrom', e.target.value)
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        El día en que la cuenta empieza a corresponder a la
                        caja: sus créditos se ofrecen desde ahí. Vacío, si se
                        usaba desde antes y no se sabe desde cuándo.
                    </p>
                    <InputError message={form.errors.validFrom} />
                </div>
                <label className="flex items-start gap-2 text-sm sm:col-span-2">
                    <Checkbox
                        checked={form.data.makeDefault}
                        onCheckedChange={(v) =>
                            form.setData('makeDefault', v === true)
                        }
                    />
                    <span>
                        Hacerla la predeterminada de su moneda
                        <span className="block text-xs text-muted-foreground">
                            La que se propone al depositar. La primera cuenta de
                            una moneda queda predeterminada sola.
                        </span>
                    </span>
                </label>
                <div className="sm:col-span-2">
                    <Button type="submit" disabled={form.processing}>
                        Habilitar
                    </Button>
                </div>
            </form>
        </section>
    );
}

function DarDeBaja({
    cuenta,
    otras,
    hoy,
    rutas,
    onClose,
}: {
    cuenta: Cuenta;
    otras: Cuenta[];
    hoy: string;
    rutas: RutasDeCaja;
    onClose: () => void;
}) {
    const form = useForm({
        until: hoy,
        newDefaultBankAccountId: otras[0] ? String(otras[0].bankAccountId) : '',
    });
    const eligeReemplazo = cuenta.isDefault && otras.length > 0;

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            until: d.until,
                            newDefaultBankAccountId: eligeReemplazo
                                ? Number(d.newDefaultBankAccountId)
                                : null,
                        }));
                        form.post(
                            rutas.cuentas.retire({ link: cuenta.id }).url,
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Dar de baja {cuenta.label}</DialogTitle>
                        <DialogDescription>
                            Desde el día siguiente no admite depósitos ni
                            identificaciones nuevas. Lo ya hecho sigue: un
                            depósito en tránsito se acredita contra ella.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="baja-hasta">
                                Último día en que opera
                            </Label>
                            <DateInput
                                id="baja-hasta"
                                max={hoy}
                                value={form.data.until}
                                onChange={(e) =>
                                    form.setData('until', e.target.value)
                                }
                            />
                            <InputError message={form.errors.until} />
                        </div>
                        {eligeReemplazo && (
                            <div className="grid gap-2">
                                <Label htmlFor="baja-reemplazo">
                                    Nueva predeterminada
                                </Label>
                                <Select
                                    value={form.data.newDefaultBankAccountId}
                                    onValueChange={(v) =>
                                        form.setData(
                                            'newDefaultBankAccountId',
                                            v,
                                        )
                                    }
                                >
                                    <SelectTrigger id="baja-reemplazo">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {otras.map((o) => (
                                            <SelectItem
                                                key={o.id}
                                                value={String(o.bankAccountId)}
                                            >
                                                {o.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError
                                    message={
                                        form.errors.newDefaultBankAccountId
                                    }
                                />
                            </div>
                        )}
                        {cuenta.isDefault && otras.length === 0 && (
                            <p className="text-sm text-warning-strong">
                                Es la última cuenta habilitada en{' '}
                                {cuenta.currency}: la caja queda sin cuenta para
                                depositar ni identificar créditos nuevos en esa
                                moneda.
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Dar de baja
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function CambiarInicio({
    cuenta,
    hoy,
    rutas,
    onClose,
}: {
    cuenta: Cuenta;
    hoy: string;
    rutas: RutasDeCaja;
    onClose: () => void;
}) {
    const form = useForm({ validFrom: cuenta.validFrom ?? '', reason: '' });

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(
                            rutas.cuentas.start({ link: cuenta.id }).url,
                            { preserveScroll: true, onSuccess: onClose },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Desde cuándo vale {cuenta.label}
                        </DialogTitle>
                        <DialogDescription>
                            Adelantar el inicio ofrece para identificar todos
                            los créditos de la cuenta en el período que se suma,
                            no solo uno. Atrasarlo no puede dejar afuera nada de
                            lo ya registrado.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-2">
                            <Label htmlFor="inicio-desde">Vale desde</Label>
                            <DateInput
                                id="inicio-desde"
                                max={hoy}
                                value={form.data.validFrom}
                                onChange={(e) =>
                                    form.setData('validFrom', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Vacío: inicio histórico desconocido.
                            </p>
                            <InputError message={form.errors.validFrom} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="inicio-motivo">Motivo</Label>
                            <Textarea
                                id="inicio-motivo"
                                rows={2}
                                maxLength={300}
                                value={form.data.reason}
                                onChange={(e) =>
                                    form.setData('reason', e.target.value)
                                }
                            />
                            <InputError message={form.errors.reason} />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
