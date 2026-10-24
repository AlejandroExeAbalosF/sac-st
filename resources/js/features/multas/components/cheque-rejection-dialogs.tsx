import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import DateInput from '@/components/date-input';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
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
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { CHEQUE_DISPOSITION } from '@/features/multas/labels';
import { businessToday, money } from '@/lib/format';
import { store as rechazar } from '@/routes/multas/pagos/rechazo';
import { completar, reemplazo as reemplazar } from '@/routes/multas/rechazos';

type Disposicion = App.Modules.Banking.Enums.ChequeDisposition;
const NINGUNA = 'ninguna';

/**
 * Registrar el rechazo del cheque vigente de un cobro (M03).
 *
 * El pago y su recibo quedan como están. El respaldo —el aviso del banco, la
 * constancia— es obligatorio. La revisión bancaria queda pendiente; registrar
 * el aviso no imputa ni revierte movimientos del extracto.
 */
export function RejectChequeDialog({
    paymentId,
    chequeNumber,
    amount,
    onClose,
}: {
    paymentId: number;
    chequeNumber: string | null;
    amount: string;
    onClose: () => void;
}) {
    const [clave] = useState(
        () => `rechazo-cheque:${paymentId}:${crypto.randomUUID()}`,
    );
    const { data, setData, post, processing, errors } = useForm<{
        rejectedOn: string;
        reason: string;
        evidence: File | null;
        idempotencyKey: string;
    }>({
        rejectedOn: businessToday(),
        reason: '',
        evidence: null,
        idempotencyKey: clave,
    });
    const errores = errors as Record<string, string | undefined>;

    return (
        <FormDialog
            title="Registrar el rechazo del cheque"
            description={
                <>
                    Cheque {chequeNumber ? `n.º ${chequeNumber}` : ''} por{' '}
                    {money(amount)}. El pago y su recibo se conservan: la multa
                    muestra aparte lo pendiente de regularizar.
                </>
            }
            onClose={onClose}
            onSubmit={() =>
                post(rechazar(paymentId).url, {
                    forceFormData: true,
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Registrar el rechazo"
            destructive
        >
            <div>
                <Label htmlFor="rechazo-fecha">Rechazado el</Label>
                <DateInput
                    id="rechazo-fecha"
                    className="mt-1"
                    max={businessToday()}
                    value={data.rejectedOn}
                    onChange={(e) => setData('rejectedOn', e.target.value)}
                />
                <FieldError message={errors.rejectedOn} />
            </div>
            <div>
                <Label htmlFor="rechazo-motivo">Motivo</Label>
                <Textarea
                    id="rechazo-motivo"
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={data.reason}
                    onChange={(e) => setData('reason', e.target.value)}
                    placeholder="Sin fondos suficientes"
                />
                <FieldError message={errors.reason} />
            </div>
            <PaperPhotoDropzone
                id="rechazo-respaldo"
                label="Respaldo del rechazo"
                foto={data.evidence}
                onElegir={(archivo) => setData('evidence', archivo)}
                error={errores.evidence}
            />
            <FieldError message={errores.chequeId} />
        </FormDialog>
    );
}

/**
 * El cheque que reemplaza al rechazado: otro cheque del mismo importe, que
 * vuelve a custodia. Sin recibo nuevo ni nueva recaudación (M03).
 */
export function ReplaceChequeDialog({
    rejectionId,
    amount,
    onClose,
}: {
    rejectionId: number;
    amount: string;
    onClose: () => void;
}) {
    const [clave] = useState(
        () => `reemplazo-cheque:${rejectionId}:${crypto.randomUUID()}`,
    );
    const { data, setData, post, processing, errors } = useForm({
        chequeNumber: '',
        chequeBank: '',
        chequeIssueDate: '',
        idempotencyKey: clave,
    });

    return (
        <FormDialog
            title="Recibir el cheque de reemplazo"
            description={
                <>
                    Otro cheque por {money(amount)}, el mismo importe. Vuelve a
                    custodia para depositarlo; el pago y su recibo no cambian.
                </>
            }
            onClose={onClose}
            onSubmit={() =>
                post(reemplazar(rejectionId).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Recibir el reemplazo"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="reemplazo-numero">Cheque n.º</Label>
                    <Input
                        id="reemplazo-numero"
                        className="mt-1 font-mono"
                        maxLength={50}
                        value={data.chequeNumber}
                        onChange={(e) =>
                            setData('chequeNumber', e.target.value)
                        }
                    />
                    <FieldError message={errors.chequeNumber} />
                </div>
                <div>
                    <Label htmlFor="reemplazo-banco">Banco</Label>
                    <Input
                        id="reemplazo-banco"
                        className="mt-1"
                        maxLength={120}
                        value={data.chequeBank}
                        onChange={(e) => setData('chequeBank', e.target.value)}
                    />
                    <FieldError message={errors.chequeBank} />
                </div>
            </div>
            <div>
                <Label htmlFor="reemplazo-emision">
                    Fecha de emisión
                    <span className="ml-1 font-normal text-muted-foreground">
                        (opcional)
                    </span>
                </Label>
                <DateInput
                    id="reemplazo-emision"
                    className="mt-1"
                    value={data.chequeIssueDate}
                    onChange={(e) => setData('chequeIssueDate', e.target.value)}
                />
                <FieldError message={errors.chequeIssueDate} />
            </div>
        </FormDialog>
    );
}

/**
 * Lo que se completa del rechazo después: el aviso a la empresa y qué se
 * hizo con el papel, con su constancia. Cada uno una vez.
 */
export function CompleteRejectionDialog({
    rejectionId,
    noticeRecorded,
    dispositionRecorded,
    onClose,
}: {
    rejectionId: number;
    noticeRecorded: boolean;
    dispositionRecorded: boolean;
    onClose: () => void;
}) {
    const { data, setData, transform, post, processing, errors } = useForm<{
        companyNotifiedOn: string;
        notice: File | null;
        disposition: Disposicion | typeof NINGUNA;
        dispositionProof: File | null;
    }>({
        companyNotifiedOn: '',
        notice: null,
        disposition: NINGUNA,
        dispositionProof: null,
    });

    const enviar = () => {
        transform((d) => ({
            companyNotifiedOn: d.companyNotifiedOn || null,
            notice: d.notice,
            disposition: d.disposition === NINGUNA ? null : d.disposition,
            dispositionProof: d.dispositionProof,
        }));
        post(completar(rejectionId).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormDialog
            title="Completar el rechazo"
            description="El aviso a la empresa y qué se hizo con el cheque. Se registran una vez, con quién y cuándo."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Guardar"
        >
            {!noticeRecorded && (
                <div className="grid gap-3">
                    <div>
                        <Label htmlFor="rechazo-aviso">
                            Se avisó a la empresa el
                        </Label>
                        <DateInput
                            id="rechazo-aviso"
                            className="mt-1"
                            max={businessToday()}
                            value={data.companyNotifiedOn}
                            onChange={(e) =>
                                setData('companyNotifiedOn', e.target.value)
                            }
                        />
                        <FieldError message={errors.companyNotifiedOn} />
                    </div>
                    <PaperPhotoDropzone
                        id="rechazo-aviso-constancia"
                        label="Constancia del aviso"
                        optional
                        foto={data.notice}
                        onElegir={(archivo) => setData('notice', archivo)}
                        error={errors.notice}
                    />
                </div>
            )}
            {!dispositionRecorded && (
                <div className="grid gap-3">
                    <div>
                        <Label htmlFor="rechazo-disposicion">
                            Qué se hizo con el cheque
                        </Label>
                        <Select
                            value={data.disposition}
                            onValueChange={(v) =>
                                setData(
                                    'disposition',
                                    v as Disposicion | typeof NINGUNA,
                                )
                            }
                        >
                            <SelectTrigger
                                id="rechazo-disposicion"
                                className="mt-1"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NINGUNA}>
                                    Todavía nada
                                </SelectItem>
                                {(
                                    Object.keys(
                                        CHEQUE_DISPOSITION,
                                    ) as Disposicion[]
                                ).map((d) => (
                                    <SelectItem key={d} value={d}>
                                        {CHEQUE_DISPOSITION[d]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <FieldError message={errors.disposition} />
                    </div>
                    {data.disposition !== NINGUNA && (
                        <PaperPhotoDropzone
                            id="rechazo-disposicion-constancia"
                            label="Constancia"
                            optional={false}
                            foto={data.dispositionProof}
                            onElegir={(archivo) =>
                                setData('dispositionProof', archivo)
                            }
                            error={errors.dispositionProof}
                        />
                    )}
                </div>
            )}
        </FormDialog>
    );
}
