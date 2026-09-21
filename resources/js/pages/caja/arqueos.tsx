import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Scissors } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import { conMoneda } from '@/features/caja/moneda';
import { date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { dia as caja } from '@/routes/caja';
import { adjust, index as arqueos, review } from '@/routes/caja/arqueos';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;

type Props = {
    selected: {
        currency: CurrencyCode;
        defaultDate: string;
    };
    counts: Arqueo[];
    can: { review: boolean; adjust: boolean };
};

/**
 * Arqueos de caja.
 *
 * El conteo nuevo se inicia en Caja del día, con fecha y saldo a la vista.
 * Este listado permite consultar, revisar e imputar arqueos existentes.
 */
export default function CajaArqueos({ selected, counts, can }: Props) {
    const [imputando, setImputando] = useState<Arqueo | null>(null);

    return (
        <EnMoneda moneda={selected.currency}>
            <Head title="Arqueos" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Caja"
                    title="Arqueos"
                    description="El conteo físico del efectivo, contra lo que dice el libro."
                    actions={
                        <SelectorDeMoneda
                            moneda={selected.currency}
                            href={(otra) =>
                                conMoneda(
                                    arqueos({
                                        query: {
                                            fecha: selected.defaultDate,
                                        },
                                    }).url,
                                    otra,
                                )
                            }
                        />
                    }
                />

                <div className="overflow-hidden rounded-lg border bg-card">
                    {counts.length === 0 ? (
                        <div className="flex flex-col items-center gap-4 px-4 py-12 text-center">
                            <p className="text-sm text-muted-foreground">
                                Todavía no se registró ningún arqueo.
                            </p>
                            <Button variant="outline" asChild>
                                <Link
                                    href={conMoneda(
                                        caja({
                                            query: {
                                                fecha: selected.defaultDate,
                                            },
                                        }).url,
                                        selected.currency,
                                    )}
                                >
                                    Ir a Caja del día
                                </Link>
                            </Button>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                                    <tr>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Fecha
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Contado
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            No recontado
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Saldo del libro
                                        </th>
                                        <th className="px-4 py-2 text-right font-medium">
                                            Diferencia
                                        </th>
                                        <th className="px-4 py-2 text-left font-medium">
                                            Estado
                                        </th>
                                        <th className="px-4 py-2" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {counts.map((arqueo) => (
                                        <Renglon
                                            key={arqueo.id}
                                            arqueo={arqueo}
                                            can={can}
                                            onImputar={() =>
                                                setImputando(arqueo)
                                            }
                                        />
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            <DialogoImputacion
                arqueo={imputando}
                cerrar={() => setImputando(null)}
            />
        </EnMoneda>
    );
}

function Renglon({
    arqueo,
    can,
    onImputar,
}: {
    arqueo: Arqueo;
    can: Props['can'];
    onImputar: () => void;
}) {
    const revisar = useForm({});
    const [confirmando, setConfirmando] = useState(false);

    return (
        <>
            <tr>
                <td className="px-4 py-2 whitespace-nowrap">
                    {formatDate(arqueo.countedOn)}
                    {arqueo.sequence > 1 && (
                        <span className="ml-1.5 text-xs text-muted-foreground">
                            turno {arqueo.sequence}
                        </span>
                    )}
                </td>
                <td className="px-4 py-2 text-right">
                    <Money value={arqueo.countedAmount} />
                </td>
                <td className="px-4 py-2 text-right">
                    <Money value={arqueo.uncountedAmount} dimWhenZero />
                </td>
                <td className="px-4 py-2 text-right">
                    <Money value={arqueo.expectedAmount} />
                </td>
                <td className="px-4 py-2 text-right">
                    <Money value={arqueo.differenceAmount} dimWhenZero />
                </td>
                <td className="px-4 py-2">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <StatusBadge
                            label={arqueo.statusLabel}
                            tone={
                                arqueo.status === 'draft'
                                    ? 'action'
                                    : arqueo.status === 'closed'
                                      ? 'done'
                                      : 'progress'
                            }
                        />
                        {/*
                         * La distinción que justifica la columna: cuadrar no
                         * es haber contado todo. Sin este aviso, un arqueo con
                         * medio cajón sin contar se ve igual que uno completo.
                         */}
                        {arqueo.balanced && !arqueo.fullyCounted && (
                            <span
                                className="inline-flex items-center gap-1 text-xs text-warning-strong"
                                title={arqueo.uncountedReason ?? undefined}
                            >
                                <Scissors className="size-3" />
                                parcial
                            </span>
                        )}
                    </div>
                </td>
                <td className="px-4 py-2 text-right whitespace-nowrap">
                    {can.review && arqueo.status === 'draft' && (
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={revisar.processing}
                            onClick={() => setConfirmando(true)}
                        >
                            <Check className="size-4" />
                            Revisar
                        </Button>
                    )}
                    {can.adjust &&
                        arqueo.status === 'reviewed' &&
                        !arqueo.balanced && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={onImputar}
                            >
                                Imputar diferencia
                            </Button>
                        )}
                </td>
            </tr>
            <Dialog open={confirmando} onOpenChange={setConfirmando}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Revisar arqueo del {formatDate(arqueo.countedOn)}
                        </DialogTitle>
                        <DialogDescription>
                            Al revisarlo, el conteo queda firme y ya no se puede
                            editar.
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid gap-2 rounded-lg border bg-muted/30 p-3 text-sm">
                        <Resumen
                            termino="Contado"
                            valor={arqueo.countedAmount}
                        />
                        <Resumen
                            termino="Saldo del libro"
                            valor={arqueo.expectedAmount}
                        />
                        <Resumen
                            termino="Diferencia"
                            valor={arqueo.differenceAmount}
                            fuerte
                        />
                        {!arqueo.fullyCounted && (
                            <p className="text-xs text-warning-strong">
                                Hay efectivo declarado como no recontado.
                            </p>
                        )}
                    </dl>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => setConfirmando(false)}
                        >
                            Volver
                        </Button>
                        <Button
                            type="button"
                            disabled={revisar.processing}
                            onClick={() =>
                                revisar.post(
                                    review({ cashCount: arqueo.id }).url,
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setConfirmando(false),
                                    },
                                )
                            }
                        >
                            Confirmar revisión
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Resumen({
    termino,
    valor,
    fuerte = false,
}: {
    termino: string;
    valor: string;
    fuerte?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className={fuerte ? 'font-medium' : 'text-muted-foreground'}>
                {termino}
            </dt>
            <dd>
                <Money
                    value={valor}
                    className={fuerte ? 'font-semibold' : undefined}
                />
            </dd>
        </div>
    );
}

function DialogoImputacion({
    arqueo,
    cerrar,
}: {
    arqueo: Arqueo | null;
    cerrar: () => void;
}) {
    const form = useForm({ authorization: '' });

    return (
        <Dialog
            open={arqueo !== null}
            onOpenChange={(open) => {
                if (!open) {
                    form.reset();
                    form.clearErrors();
                    cerrar();
                }
            }}
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Imputar la diferencia</DialogTitle>
                    <DialogDescription>
                        Asienta{' '}
                        {arqueo && <Money value={arqueo.differenceAmount} />}{' '}
                        contra Diferencia de arqueo. Después de esto el libro va
                        a decir lo que hay en el cajón.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id="form-imputar"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (arqueo === null) {
                            return;
                        }

                        form.post(adjust({ cashCount: arqueo.id }).url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                cerrar();
                            },
                        });
                    }}
                    className="grid gap-2"
                >
                    <Label htmlFor="authorization">Autorización</Label>
                    <Textarea
                        id="authorization"
                        rows={3}
                        placeholder="Nota, resolución o instrucción que autoriza la imputación."
                        value={form.data.authorization}
                        onChange={(e) =>
                            form.setData('authorization', e.target.value)
                        }
                    />
                    <InputError message={form.errors.authorization} />
                </form>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form="form-imputar"
                        disabled={form.processing}
                    >
                        Imputar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
