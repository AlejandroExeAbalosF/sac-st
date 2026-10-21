import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
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
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import CreditPicker from '@/features/multas/components/credit-picker';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { businessToday, money, parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/pagos-bancarios';
import { store as storeVerified } from '@/routes/multas/pagos-bancarios/verificados';

type Credito = App.Modules.Multas.Data.FineBankCreditData;
type Pago = App.Modules.Multas.Data.FinePaymentData;
type Medio = 'bank_transfer' | 'direct_deposit' | 'echeq';

const MEDIOS: Record<Medio, string> = {
    bank_transfer: 'Transferencia',
    direct_deposit: 'Depósito directo',
    echeq: 'Cheque electrónico',
};

/**
 * Un pago que llega por el banco (M02).
 *
 * Lo normal es **informarlo**: alguien presentó el comprobante y queda a la
 * espera del crédito, sin tocar el saldo. Quien concilia puede, además,
 * reconocerlo directamente del extracto: elige el crédito y el pago se aplica
 * en el acto, por el importe del crédito, con su recibo.
 */
export default function BankPaymentDialog({
    fineId,
    canVerify,
    creditos,
    firmantes,
    idempotencyKey,
    corrige,
    onClose,
}: {
    fineId: number;
    canVerify: boolean;
    creditos?: Credito[];
    firmantes: Firmante[];
    idempotencyKey: string;
    /** El pago informado que se corrige, si es una corrección. */
    corrige?: Pago;
    onClose: () => void;
}) {
    const [delExtracto, setDelExtracto] = useState(false);
    const hoy = businessToday();
    const { data, setData, post, transform, processing, errors } = useForm({
        medium: (corrige?.medium ?? 'bank_transfer') as Medio,
        amount: corrige ? money(corrige.amount, { symbol: false }) : '',
        presentedOn: hoy,
        presentationEvidence: '',
        declaredPaidOn: corrige?.declaredPaidOn ?? '',
        judicial: Boolean(corrige?.judicialReference),
        judicialReference: corrige?.judicialReference ?? '',
        bankTransactionId: null as number | null,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
        correctionReason: '',
    });
    const errores = errors as Record<string, string | undefined>;
    const atrasado = data.presentedOn !== '' && data.presentedOn < hoy;
    const transferencia = data.medium === 'bank_transfer';

    const enviar = () => {
        const judicial =
            transferencia && data.judicial
                ? data.judicialReference.trim()
                : null;

        if (delExtracto) {
            transform((datos) => ({
                medium: datos.medium,
                bankTransactionId: datos.bankTransactionId,
                judicialReference: judicial,
                ...receiptPayload(datos.talonarioNumber, datos.signedById),
                idempotencyKey,
            }));
            post(storeVerified(fineId).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        transform((datos) => ({
            medium: datos.medium,
            amount: parseAmount(datos.amount),
            presentedOn: datos.presentedOn === hoy ? null : datos.presentedOn,
            presentationEvidence: atrasado
                ? datos.presentationEvidence.trim()
                : null,
            declaredPaidOn: datos.declaredPaidOn || null,
            judicialReference: judicial,
            ...(corrige
                ? {
                      replacesPaymentId: corrige.id,
                      correctionReason: datos.correctionReason.trim(),
                  }
                : {}),
            idempotencyKey,
        }));
        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title={corrige ? 'Corregir el pago informado' : 'Pago por banco'}
            description={
                corrige
                    ? 'El informado se descarta con el motivo y queda este en su lugar, con la misma presentación.'
                    : delExtracto
                      ? 'Elegí el crédito del extracto que trajo el pago. Se aplica en el acto, por el importe del crédito, y sale el recibo.'
                      : 'El pago queda informado hasta verificarlo contra el extracto: todavía no reduce el saldo.'
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={
                corrige
                    ? 'Corregir'
                    : delExtracto
                      ? 'Verificar y emitir recibo'
                      : 'Informar'
            }
        >
            {canVerify && (
                <label className="flex items-start gap-3 rounded-md border p-3 text-sm">
                    <Checkbox
                        className="mt-0.5"
                        checked={delExtracto}
                        onCheckedChange={(v) => setDelExtracto(v === true)}
                    />
                    <span>
                        <span className="font-medium">
                            El crédito ya está en el extracto
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            Se identifica y se verifica en el mismo acto.
                        </span>
                    </span>
                </label>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="banco-medio">Medio</Label>
                    <Select
                        value={data.medium}
                        onValueChange={(v) => setData('medium', v as Medio)}
                    >
                        <SelectTrigger id="banco-medio" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(MEDIOS) as Medio[]).map((m) => (
                                <SelectItem key={m} value={m}>
                                    {MEDIOS[m]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.medium} />
                </div>
                {!delExtracto && (
                    <div>
                        <Label htmlFor="banco-importe">Importe</Label>
                        <div className="mt-1">
                            <AmountInput
                                id="banco-importe"
                                value={data.amount}
                                onChange={(v) => setData('amount', v)}
                                placeholder="0,00"
                                aria-invalid={Boolean(errors.amount)}
                            />
                        </div>
                        <FieldError message={errors.amount} />
                    </div>
                )}
            </div>

            {delExtracto ? (
                <>
                    <CreditPicker
                        prop="creditosDisponibles"
                        param="disponible"
                        creditos={creditos}
                        selected={data.bankTransactionId}
                        onSelect={(id) => setData('bankTransactionId', id)}
                        emptyText="No hay créditos posteriores a la apertura que coincidan y estén libres."
                        error={errors.bankTransactionId}
                    />
                    <ReceiptFields
                        idPrefix="banco"
                        talonarioNumber={data.talonarioNumber}
                        onTalonarioChange={(v) => setData('talonarioNumber', v)}
                        signedById={data.signedById}
                        onSignedByChange={(v) => setData('signedById', v)}
                        firmantes={firmantes}
                        errors={errors}
                    />
                </>
            ) : (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        {!corrige && (
                            <div>
                                <Label htmlFor="banco-presentado">
                                    Presentado el
                                </Label>
                                <Input
                                    id="banco-presentado"
                                    type="date"
                                    className="mt-1"
                                    max={hoy}
                                    value={data.presentedOn}
                                    onChange={(e) =>
                                        setData('presentedOn', e.target.value)
                                    }
                                    aria-invalid={Boolean(errors.presentedOn)}
                                />
                                <FieldError message={errors.presentedOn} />
                            </div>
                        )}
                        <div>
                            <Label htmlFor="banco-pagado">
                                Pagado el (según el comprobante)
                            </Label>
                            <Input
                                id="banco-pagado"
                                type="date"
                                className="mt-1"
                                max={hoy}
                                value={data.declaredPaidOn}
                                onChange={(e) =>
                                    setData('declaredPaidOn', e.target.value)
                                }
                                aria-invalid={Boolean(errors.declaredPaidOn)}
                            />
                            <FieldError message={errors.declaredPaidOn} />
                        </div>
                    </div>
                    {atrasado && (
                        <div>
                            <Label htmlFor="banco-respaldo">
                                Respaldo de la presentación
                            </Label>
                            <Input
                                id="banco-respaldo"
                                className="mt-1"
                                maxLength={300}
                                value={data.presentationEvidence}
                                onChange={(e) =>
                                    setData(
                                        'presentationEvidence',
                                        e.target.value,
                                    )
                                }
                                placeholder="Sello de Mesa de Entradas, nota de la empresa…"
                                aria-invalid={Boolean(
                                    errors.presentationEvidence,
                                )}
                            />
                            <FieldError message={errors.presentationEvidence} />
                        </div>
                    )}
                </>
            )}

            {transferencia && (
                <div className="grid gap-2">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="banco-judicial"
                            checked={data.judicial}
                            onCheckedChange={(v) =>
                                setData('judicial', v === true)
                            }
                        />
                        <Label htmlFor="banco-judicial">
                            Es una orden de pago judicial
                        </Label>
                    </div>
                    {data.judicial && (
                        <div>
                            <Label htmlFor="banco-judicial-ref">
                                Expediente judicial
                            </Label>
                            <Input
                                id="banco-judicial-ref"
                                className="mt-1 font-mono"
                                maxLength={120}
                                value={data.judicialReference}
                                onChange={(e) =>
                                    setData('judicialReference', e.target.value)
                                }
                                aria-invalid={Boolean(errors.judicialReference)}
                            />
                            <FieldError message={errors.judicialReference} />
                        </div>
                    )}
                </div>
            )}
            {corrige && (
                <div>
                    <Label htmlFor="banco-correccion">Qué se corrige</Label>
                    <Textarea
                        id="banco-correccion"
                        className="mt-1"
                        rows={2}
                        maxLength={400}
                        value={data.correctionReason}
                        onChange={(e) =>
                            setData('correctionReason', e.target.value)
                        }
                        placeholder="El importe estaba mal tipeado, no era una transferencia…"
                        aria-invalid={Boolean(errores.correctionReason)}
                    />
                    <FieldError
                        message={
                            errores.correctionReason ??
                            errores.replacesPaymentId ??
                            errores.reason
                        }
                    />
                </div>
            )}
        </FormDialog>
    );
}
