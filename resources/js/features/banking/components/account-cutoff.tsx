import { useForm } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    businessToday,
    date,
    dateTime,
    isNonZero,
    parseAmount,
} from '@/lib/format';
import { preview } from '@/routes/adjuntos';
import { store } from '@/routes/banco/cuentas/corte';

type Cuenta = App.Modules.Banking.Data.BankAccountData;
type Corte = App.Modules.Banking.Data.BankAccountCutoffData;

/**
 * Declarar el saldo de una cuenta compartida al corte.
 *
 * Se hace una vez y no se corrige: lo que el extracto trae hasta ese día
 * queda dentro de este saldo, sin repartir entre las cajas que usan la
 * cuenta, y no entra a ningún libro como dinero nuevo.
 */
export function CutoffDialog({
    cuenta,
    onClose,
}: {
    cuenta: Cuenta;
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm<{
        cutoffOn: string;
        statementBalance: string;
        notes: string;
        evidence: File | null;
    }>({ cutoffOn: '', statementBalance: '', notes: '', evidence: null });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        transform((datos) => ({
            ...datos,
            statementBalance: parseAmount(datos.statementBalance),
        }));
        post(store(cuenta.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={enviar} className="grid gap-4">
                    <DialogHeader>
                        <DialogTitle>Corte de «{cuenta.label}»</DialogTitle>
                        <DialogDescription>
                            El saldo que informa el banco para la cuenta entera,
                            sin repartir entre las cajas que la usan. Lo que el
                            extracto trae hasta ese día queda adentro y no entra
                            a ningún libro como cobro.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex gap-3 rounded-lg border border-warning-soft bg-warning-soft p-3 text-sm text-warning-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p>
                            Se declara una sola vez y no se corrige. Las cajas
                            que operen con esta cuenta abren después de esta
                            fecha.
                        </p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="cutoffOn">
                                Último día incluido
                            </Label>
                            <Input
                                id="cutoffOn"
                                type="date"
                                max={businessToday()}
                                value={data.cutoffOn}
                                onChange={(e) =>
                                    setData('cutoffOn', e.target.value)
                                }
                            />
                            <InputError message={errors.cutoffOn} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="statementBalance">
                                Saldo según el banco
                            </Label>
                            <Input
                                id="statementBalance"
                                inputMode="decimal"
                                className="text-right font-mono tabular-nums"
                                placeholder="0,00"
                                value={data.statementBalance}
                                onChange={(e) =>
                                    setData('statementBalance', e.target.value)
                                }
                            />
                            <InputError message={errors.statementBalance} />
                        </div>
                    </div>

                    <PaperPhotoDropzone
                        id="evidence"
                        label="Comprobante del saldo"
                        foto={data.evidence}
                        onElegir={(archivo) => setData('evidence', archivo)}
                        error={errors.evidence}
                    />

                    <div className="grid gap-2">
                        <Label htmlFor="notes">Nota</Label>
                        <Textarea
                            id="notes"
                            rows={2}
                            maxLength={300}
                            placeholder="De dónde sale el saldo: extracto del banco, nota de Contaduría."
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Guardando…' : 'Declarar corte'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Un corte ya declarado, y si lo importado después cierra contra el último
 * saldo del extracto.
 */
export function CutoffSummary({
    corte,
    cuenta,
}: {
    corte: Corte;
    cuenta: Cuenta | undefined;
}) {
    const conciliacion = corte.reconciliation;
    const cierra = conciliacion !== null && !isNonZero(conciliacion.difference);
    const delExtracto = corte.statementAtCutoff;
    // Los dos llegan del servidor con dos decimales: se comparan como texto.
    const coincide = delExtracto === corte.statementBalance;

    return (
        <article className="rounded-lg border bg-card p-4 shadow-raised">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate font-medium">
                        {cuenta?.label ?? `Cuenta ${corte.bankAccountId}`}
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Declarado por {corte.declaredByName ?? 'sin dato'} ·{' '}
                        {dateTime(corte.declaredAt)}
                    </p>
                </div>
                {conciliacion === null ? (
                    <StatusBadge label="Sin extractos posteriores" />
                ) : (
                    <StatusBadge
                        label={cierra ? 'Cierra con el extracto' : 'No cierra'}
                        tone={cierra ? 'done' : 'blocked'}
                    />
                )}
            </div>

            <dl className="mt-3 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div>
                    <dt className="text-xs text-field-label">
                        Saldo al {date(corte.cutoffOn)}
                    </dt>
                    <dd className="mt-0.5">
                        <Money value={corte.statementBalance} />
                    </dd>
                </div>
                {conciliacion !== null && (
                    <>
                        <div>
                            <dt className="text-xs text-field-label">
                                Esperado con lo importado
                            </dt>
                            <dd className="mt-0.5">
                                <Money value={conciliacion.expected} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-field-label">
                                Último saldo del extracto (
                                {date(conciliacion.latestDate)})
                            </dt>
                            <dd className="mt-0.5">
                                <Money value={conciliacion.latest} />
                            </dd>
                        </div>
                    </>
                )}
            </dl>

            {/*
             * El saldo declarado contra un extracto que abarque el día del
             * corte. Sin ese archivo el sistema no lo sabe, y lo dice.
             */}
            {delExtracto === null ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    Verificación pendiente: todavía no se importó un extracto
                    que abarque el {date(corte.cutoffOn)}.
                </p>
            ) : coincide ? (
                <p className="mt-3 text-xs text-success-strong">
                    Coincide con el saldo del extracto a esa fecha.
                </p>
            ) : (
                <p className="mt-3 text-xs text-destructive-strong">
                    El extracto dice <Money value={delExtracto} /> al{' '}
                    {date(corte.cutoffOn)}: el saldo declarado no es el del
                    banco.
                </p>
            )}

            {conciliacion !== null && !cierra && (
                <p className="mt-3 text-xs text-destructive-strong">
                    Diferencia de <Money value={conciliacion.difference} /> al{' '}
                    {date(conciliacion.latestDate)}: falta un extracto entre el
                    corte y ese día, o el saldo declarado no era el del banco.
                </p>
            )}

            {conciliacion !== null && conciliacion.unverifiedCount > 0 && (
                <p className="mt-3 text-xs text-muted-foreground">
                    {conciliacion.unverifiedCount === 1
                        ? 'Un movimiento posterior todavía no se puede contrastar'
                        : `${conciliacion.unverifiedCount} movimientos posteriores todavía no se pueden contrastar`}
                    : el extracto no informa un saldo después.
                </p>
            )}

            {(corte.notes || corte.attachmentId) && (
                <p className="mt-3 text-xs text-muted-foreground">
                    {corte.notes}
                    {corte.notes && corte.attachmentId && ' · '}
                    {corte.attachmentId && (
                        <a
                            href={preview(corte.attachmentId).url}
                            target="_blank"
                            rel="noreferrer"
                            className="text-primary hover:underline"
                        >
                            Ver comprobante
                        </a>
                    )}
                </p>
            )}
        </article>
    );
}
