import { Head, useForm } from '@inertiajs/react';
import { Landmark, Pencil, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cbu as formatCbu, dateTime, money } from '@/lib/format';
import { store, update } from '@/routes/banco/cuentas';

type Cuenta = App.Modules.Banking.Data.BankAccountData;

type Props = {
    accounts: Cuenta[];
    canManage: boolean;
};

type FormValues = {
    label: string;
    bankName: string;
    accountNumber: string;
    cbu: string;
    alias: string;
    currency: string;
    isActive: boolean;
};

const VACIO: FormValues = {
    label: '',
    bankName: '',
    accountNumber: '',
    cbu: '',
    alias: '',
    currency: 'ARS',
    isActive: true,
};

/**
 * Cuentas bancarias del organismo.
 *
 * Es el maestro del que cuelga todo lo bancario y casi nunca cambia, pero
 * el número de cuenta que se carga acá es el que después se compara contra
 * la cabecera del extracto para rechazar el archivo equivocado. Por eso la
 * pantalla insiste con ese dato en lugar de tratarlo como opcional de
 * relleno.
 */
export default function BancoCuentas({ accounts, canManage }: Props) {
    const [abierto, setAbierto] = useState(false);
    const [editando, setEditando] = useState<Cuenta | null>(null);

    const form = useForm<FormValues>(VACIO);

    useEffect(() => {
        if (!abierto) {
            return;
        }

        form.setDefaults(
            editando
                ? {
                      label: editando.label,
                      bankName: editando.bankName,
                      accountNumber: editando.accountNumber ?? '',
                      cbu: editando.cbu ?? '',
                      alias: editando.alias ?? '',
                      currency: editando.currency,
                      isActive: editando.isActive,
                  }
                : VACIO,
        );
        form.reset();
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [abierto, editando]);

    const abrir = (cuenta: Cuenta | null) => {
        setEditando(cuenta);
        setAbierto(true);
    };

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();

        const onSuccess = () => setAbierto(false);

        if (editando) {
            form.patch(update(editando.id).url, { onSuccess });

            return;
        }

        form.post(store().url, { onSuccess });
    };

    return (
        <>
            <Head title="Cuentas bancarias" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco"
                    title="Cuentas del organismo"
                    description="Las cuentas contra las que se importan los extractos y se concilia el saldo."
                    actions={
                        canManage ? (
                            <Button onClick={() => abrir(null)}>
                                <Plus className="size-4" />
                                Nueva cuenta
                            </Button>
                        ) : null
                    }
                />

                {accounts.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <Landmark className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            Todavía no hay ninguna cuenta registrada.
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            Sin una cuenta no se puede importar ningún extracto:
                            es contra ella que se cargan los movimientos.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="grid gap-3 md:hidden">
                            {accounts.map((cuenta) => (
                                <CuentaMovil
                                    key={cuenta.id}
                                    cuenta={cuenta}
                                    canManage={canManage}
                                    onEdit={() => abrir(cuenta)}
                                />
                            ))}
                        </div>
                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium text-field-label">
                                            Cuenta
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Número
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            CBU
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Moneda
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Último saldo
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Extractos
                                        </th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.map((cuenta) => (
                                        <tr
                                            key={cuenta.id}
                                            className="border-t"
                                        >
                                            <td className="p-3">
                                                <div className="font-medium">
                                                    {cuenta.label}
                                                </div>
                                                <div className="text-muted-foreground">
                                                    {cuenta.bankName}
                                                </div>
                                                {!cuenta.isActive && (
                                                    <StatusBadge
                                                        label="Inactiva"
                                                        tone="neutral"
                                                    />
                                                )}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {cuenta.accountNumber ?? '—'}
                                            </td>
                                            <td className="p-3 tabular-nums">
                                                {cuenta.cbu
                                                    ? formatCbu(cuenta.cbu)
                                                    : '—'}
                                            </td>
                                            <td className="p-3">
                                                {cuenta.currency}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {money(cuenta.lastKnownBalance)}
                                                {cuenta.lastImportedAt && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {dateTime(
                                                            cuenta.lastImportedAt,
                                                        )}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3 text-right tabular-nums">
                                                {cuenta.importCount}
                                            </td>
                                            <td className="p-3 text-right">
                                                {canManage && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            abrir(cuenta)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Editar
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            <Dialog open={abierto} onOpenChange={setAbierto}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={enviar}>
                        <DialogHeader>
                            <DialogTitle>
                                {editando
                                    ? 'Corregir cuenta'
                                    : 'Nueva cuenta del organismo'}
                            </DialogTitle>
                            <DialogDescription>
                                El número es el que informa el extracto en su
                                cabecera. Con él, el sistema puede rechazar el
                                archivo de otra cuenta.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="label">Nombre</Label>
                                <Input
                                    id="label"
                                    value={form.data.label}
                                    onChange={(e) =>
                                        form.setData('label', e.target.value)
                                    }
                                    placeholder="Cta. Cte. 2693 — Haberes"
                                />
                                <InputError message={form.errors.label} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bankName">Banco</Label>
                                <Input
                                    id="bankName"
                                    value={form.data.bankName}
                                    onChange={(e) =>
                                        form.setData('bankName', e.target.value)
                                    }
                                    placeholder="Banco Macro"
                                />
                                <InputError message={form.errors.bankName} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="accountNumber">
                                    Número de cuenta
                                </Label>
                                <Input
                                    id="accountNumber"
                                    value={form.data.accountNumber}
                                    onChange={(e) =>
                                        form.setData(
                                            'accountNumber',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="310000123456789"
                                />
                                <InputError
                                    message={form.errors.accountNumber}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="cbu">CBU</Label>
                                <Input
                                    id="cbu"
                                    inputMode="numeric"
                                    value={form.data.cbu}
                                    onChange={(e) =>
                                        form.setData('cbu', e.target.value)
                                    }
                                    placeholder="22 dígitos"
                                />
                                <InputError message={form.errors.cbu} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="currency">Moneda</Label>
                                <Select
                                    value={form.data.currency}
                                    onValueChange={(value) =>
                                        form.setData('currency', value)
                                    }
                                >
                                    <SelectTrigger
                                        id="currency"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Elegí la moneda" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="ARS">
                                            Pesos (ARS)
                                        </SelectItem>
                                        <SelectItem value="USD">
                                            Dólares (USD)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.currency} />
                            </div>

                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    id="isActive"
                                    checked={form.data.isActive}
                                    onCheckedChange={(v) =>
                                        form.setData('isActive', v === true)
                                    }
                                />
                                Activa para nuevas importaciones
                            </label>
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAbierto(false)}
                            >
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Guardar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

function CuentaMovil({
    cuenta,
    canManage,
    onEdit,
}: {
    cuenta: Cuenta;
    canManage: boolean;
    onEdit: () => void;
}) {
    return (
        <article className="rounded-lg border bg-card p-4 shadow-xs">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="truncate font-medium">{cuenta.label}</h2>
                    <p className="text-sm text-muted-foreground">
                        {cuenta.bankName}
                    </p>
                </div>
                {!cuenta.isActive && (
                    <StatusBadge label="Inactiva" tone="neutral" />
                )}
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <DatoMovil
                    titulo="Número"
                    valor={cuenta.accountNumber ?? '—'}
                />
                <DatoMovil titulo="Moneda" valor={cuenta.currency} />
                <DatoMovil
                    titulo="CBU"
                    valor={cuenta.cbu ? formatCbu(cuenta.cbu) : '—'}
                    className="col-span-2 break-all"
                />
                <DatoMovil
                    titulo="Último saldo"
                    valor={money(cuenta.lastKnownBalance)}
                    pista={
                        cuenta.lastImportedAt
                            ? dateTime(cuenta.lastImportedAt)
                            : undefined
                    }
                />
                <DatoMovil
                    titulo="Extractos"
                    valor={cuenta.importCount.toString()}
                />
            </dl>

            {canManage && (
                <Button
                    variant="outline"
                    className="mt-4 min-h-11 w-full"
                    onClick={onEdit}
                >
                    <Pencil className="size-4" aria-hidden="true" />
                    Editar cuenta
                </Button>
            )}
        </article>
    );
}

function DatoMovil({
    titulo,
    valor,
    pista,
    className,
}: {
    titulo: string;
    valor: string;
    pista?: string;
    className?: string;
}) {
    return (
        <div className={className}>
            <dt className="text-xs font-medium text-field-label">{titulo}</dt>
            <dd className="mt-0.5 tabular-nums">{valor}</dd>
            {pista && <p className="text-xs text-muted-foreground">{pista}</p>}
        </div>
    );
}
