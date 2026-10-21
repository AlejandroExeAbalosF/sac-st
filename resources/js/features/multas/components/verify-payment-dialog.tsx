import { useForm } from '@inertiajs/react';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import CreditPicker from '@/features/multas/components/credit-picker';
import FormDialog from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { money } from '@/lib/format';
import { verify } from '@/routes/multas/pagos';

type Pago = App.Modules.Multas.Data.FinePaymentData;
type Credito = App.Modules.Multas.Data.FineBankCreditData;

/**
 * Verificar un pago informado contra el crédito del extracto que lo trajo.
 *
 * Un crédito por pago, por su importe entero (P14, provisorio). Al
 * verificarlo, el pago reduce el saldo y sale su recibo.
 */
export default function VerifyPaymentDialog({
    pago,
    creditos,
    firmantes,
    onClose,
}: {
    pago: Pago;
    creditos?: Credito[];
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        bankTransactionId: null as number | null,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });

    const enviar = () => {
        transform((datos) => ({
            bankTransactionId: datos.bankTransactionId,
            ...receiptPayload(datos.talonarioNumber, datos.signedById),
        }));
        post(verify(pago.id).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Verificar contra el extracto"
            description={`Pago informado: ${PAYMENT_MEDIUM[pago.medium].toLowerCase()} por ${money(pago.amount)}. Elegí el crédito de ese importe que lo trajo.`}
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Verificar y emitir recibo"
        >
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
                idPrefix="verificar"
                talonarioNumber={data.talonarioNumber}
                onTalonarioChange={(v) => setData('talonarioNumber', v)}
                signedById={data.signedById}
                onSignedByChange={(v) => setData('signedById', v)}
                firmantes={firmantes}
                errors={errors}
            />
        </FormDialog>
    );
}
