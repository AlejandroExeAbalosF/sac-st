import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import ReasonFields from '@/components/reason-fields';
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
import FeeCreditPicker from '@/features/aranceles/components/fee-credit-picker';
import type { Credito } from '@/features/aranceles/components/fee-credit-picker';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import { compareAmounts, money, sumAmounts } from '@/lib/format';
import { ajustar, identificar } from '@/routes/aranceles/pendientes';

type Cobro = App.Modules.Aranceles.Data.FeeChargeListItemData;
type Acto = App.Modules.Aranceles.Enums.FeeReasonAct;
type Codigo = App.Modules.Aranceles.Enums.FeeReasonCode;

const firmanteDe = (valor: string) =>
    valor === SIN_FIRMANTE ? null : Number(valor);

export function FeeIdentifyDialog({
    cobro,
    firmantes,
    onClose,
}: {
    cobro: Cobro;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const [clave] = useState(
        () => `arancel-credito:${cobro.id}:${crypto.randomUUID()}`,
    );
    const [elegidos, setElegidos] = useState<Credito[]>([]);
    const form = useForm({ signedById: SIN_FIRMANTE });
    const errores = form.errors as Record<string, string | undefined>;
    const elegido = sumAmounts(elegidos.map((c) => c.free));
    const completa = compareAmounts(elegido, cobro.missing) >= 0;

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            credits: elegidos.map((c) => c.id),
                            signedById: firmanteDe(d.signedById),
                            idempotencyKey: clave,
                        }));
                        form.post(identificar(cobro.id).url, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Identificar el pago de {cobro.payerName}
                        </DialogTitle>
                        <DialogDescription>
                            Faltan {money(cobro.missing)}. De cada crédito se
                            toma solo lo necesario: lo que sobra queda libre.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <FeeCreditPicker
                            seleccionados={elegidos}
                            onChange={setElegidos}
                        />
                        <InputError message={errores['credits']} />
                        {elegidos.length > 0 && (
                            <p className="text-sm">
                                {completa
                                    ? 'Completa el cobro: se emite el recibo.'
                                    : `Queda un ingreso parcial: los créditos elegidos suman ${money(elegido)}.`}
                            </p>
                        )}
                        {completa && (
                            <SignerSelect
                                id="identificar-firma"
                                firmantes={firmantes}
                                value={form.data.signedById}
                                onChange={(v) => form.setData('signedById', v)}
                            />
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || elegidos.length === 0}
                        >
                            {completa
                                ? 'Identificar y emitir recibo'
                                : 'Registrar ingreso parcial'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function FeeAdjustDialog({
    cobro,
    firmantes,
    onClose,
}: {
    cobro: Cobro;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const form = useForm({
        importes: Object.fromEntries(
            cobro.lines.map((l) => [l.itemId, l.amount]),
        ) as Record<number, string>,
        reasonCode: '' as Codigo | '',
        reason: '',
        signedById: SIN_FIRMANTE,
    });
    const errores = form.errors as Record<string, string | undefined>;
    const total = sumAmounts(
        Object.values(form.data.importes).map((i) => i || '0'),
    );
    const completa =
        compareAmounts(cobro.funded, '0') > 0 &&
        compareAmounts(total, cobro.funded) === 0;
    // Solo lo editado, con lo que se tenía a la vista: así no se pisa el ajuste de otro.
    const editadas = cobro.lines
        .filter(
            (l) =>
                compareAmounts(
                    form.data.importes[l.itemId] || '0',
                    l.amount,
                ) !== 0,
        )
        .map((l) => ({
            itemId: l.itemId,
            amount: form.data.importes[l.itemId],
            previousAmount: l.amount,
        }));

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            lines: editadas,
                            reasonCode: d.reasonCode,
                            reason: d.reason,
                            signedById: firmanteDe(d.signedById),
                        }));
                        form.post(ajustar(cobro.id).url, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Ajustar el importe</DialogTitle>
                        <DialogDescription>
                            Para aceptar más o menos que lo calculado. El
                            importe original se conserva, y el motivo queda
                            registrado.
                            {compareAmounts(cobro.funded, '0') > 0 &&
                                ` Ya entraron ${money(cobro.funded)}.`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        {cobro.lines.map((linea) => (
                            <div key={linea.itemId} className="grid gap-1.5">
                                <Label htmlFor={`ajuste-${linea.itemId}`}>
                                    {linea.code} · {linea.name}
                                </Label>
                                <AmountInput
                                    id={`ajuste-${linea.itemId}`}
                                    value={form.data.importes[linea.itemId]}
                                    onChange={(valor) =>
                                        form.setData('importes', {
                                            ...form.data.importes,
                                            [linea.itemId]: valor,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        <InputError message={errores['lines']} />
                        <p className="text-sm font-medium tabular-nums">
                            Total: {money(total)}
                            {completa && ' · Se emite el recibo.'}
                        </p>
                        <ReasonFields<Acto, Codigo>
                            act="amount_change"
                            idPrefix="ajuste"
                            code={form.data.reasonCode}
                            detail={form.data.reason}
                            maxLength={300}
                            onCodeChange={(c) => form.setData('reasonCode', c)}
                            onDetailChange={(d) => form.setData('reason', d)}
                            codeError={errores['reasonCode']}
                            detailError={errores['reason']}
                        />
                        {completa && (
                            <SignerSelect
                                id="ajuste-firma"
                                firmantes={firmantes}
                                value={form.data.signedById}
                                onChange={(v) => form.setData('signedById', v)}
                            />
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || editadas.length === 0}
                        >
                            Guardar ajuste
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
