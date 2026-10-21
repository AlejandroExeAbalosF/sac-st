import { useForm } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Textarea } from '@/components/ui/textarea';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { APPEAL_KIND, PAYMENT_MEDIUM } from '@/features/multas/labels';
import { money } from '@/lib/format';
import { resolve } from '@/routes/multas/pagos';

type Pago = App.Modules.Multas.Data.FinePaymentData;

/**
 * Resolver un recurso cuyo recibo externo no coincide con el 20 % (M07).
 *
 * No hay excepción al 20 %: o se aplica por el importe exigido —el recibo
 * externo estaba mal—, o se descarta para corregir el dato propio y registrar
 * otro intento. Queda quién resolvió y por qué.
 *
 * Llega acá por una diferencia de importe o porque la multa ya tenía otro
 * recurso (P05). Si la multa se pagó mientras esperaba, el efectivo no se
 * cobra: el servidor lo rechaza y queda el descarte.
 */
export default function ResolveAppealDialog({
    pago,
    firmantes,
    onClose,
}: {
    pago: Pago;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        decision: 'apply' as 'apply' | 'discard',
        reason: '',
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });
    const efectivo = pago.medium === 'cash';
    const aplica = data.decision === 'apply';

    const enviar = () => {
        transform((datos) => ({
            decision: datos.decision,
            reason: datos.reason,
            ...(aplica && efectivo
                ? receiptPayload(datos.talonarioNumber, datos.signedById)
                : {}),
        }));
        post(resolve(pago.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormDialog
            title="Resolver el recurso"
            description={
                <>
                    {pago.appealKind ? APPEAL_KIND[pago.appealKind] : 'Recurso'}{' '}
                    en {PAYMENT_MEDIUM[pago.medium].toLowerCase()}. El 20 % de{' '}
                    {money(pago.appealBaseAmount)} es{' '}
                    <strong>{money(pago.appealRequiredAmount)}</strong>; el
                    recibo externo {pago.externalReceiptReference} dice{' '}
                    <strong>{money(pago.externalReceiptAmount)}</strong>.
                    {pago.appealRepeated &&
                        ' Además, la multa ya tenía otro recurso cuando se registró.'}
                </>
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={aplica ? 'Aplicar por el 20 % exigido' : 'Descartar'}
            destructive={!aplica}
        >
            <RadioGroup
                value={data.decision}
                onValueChange={(v) =>
                    setData('decision', v as 'apply' | 'discard')
                }
                className="grid gap-2"
            >
                <label className="flex items-start gap-3 rounded-md border p-3 text-sm">
                    <RadioGroupItem value="apply" className="mt-0.5" />
                    <span>
                        <span className="font-medium">
                            Aplicar por {money(pago.appealRequiredAmount)}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            {efectivo
                                ? 'Se cobra en la caja y sale el recibo.'
                                : 'El depósito queda informado, a verificar contra el extracto.'}
                        </span>
                    </span>
                </label>
                <label className="flex items-start gap-3 rounded-md border p-3 text-sm">
                    <RadioGroupItem value="discard" className="mt-0.5" />
                    <span>
                        <span className="font-medium">Descartar</span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            El dato propio estaba mal o el respaldo no sirve: se
                            corrige y se registra otro intento.
                        </span>
                    </span>
                </label>
            </RadioGroup>
            <FieldError message={errors.decision} />

            <div>
                <Label htmlFor="resolver-motivo">Qué se revisó</Label>
                <Textarea
                    id="resolver-motivo"
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Multa, resolución, ajustes y redondeo."
                    aria-invalid={Boolean(errors.reason)}
                />
                <FieldError message={errors.reason} />
            </div>

            {aplica && efectivo && (
                <ReceiptFields
                    idPrefix="resolver"
                    talonarioNumber={data.talonarioNumber}
                    onTalonarioChange={(v) => setData('talonarioNumber', v)}
                    signedById={data.signedById}
                    onSignedByChange={(v) => setData('signedById', v)}
                    firmantes={firmantes}
                    errors={errors}
                />
            )}
        </FormDialog>
    );
}
