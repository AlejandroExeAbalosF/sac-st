import { useForm } from '@inertiajs/react';
import DateInput from '@/components/date-input';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { businessToday } from '@/lib/format';
import { store } from '@/routes/multas/pagos/comprobante';

type Pago = App.Modules.Multas.Data.FinePaymentData;

/**
 * El comprobante de un pago por banco: la fecha que dice y su archivo.
 *
 * Los dos son opcionales y se cargan cuando aparece el papel. La fecha se
 * cambia mientras el pago está informado; verificado, queda fija y solo se
 * puede sumar otro archivo. El archivo no se edita ni se borra.
 */
export default function PaymentProofDialog({
    pago,
    onClose,
}: {
    pago: Pago;
    onClose: () => void;
}) {
    const conFecha = pago.status === 'reported';
    const { data, setData, post, transform, processing, errors } = useForm<{
        declaredPaidOn: string;
        file: File | null;
        title: string;
    }>({ declaredPaidOn: pago.declaredPaidOn ?? '', file: null, title: '' });

    const fechaAlAbrir = pago.declaredPaidOn ?? '';

    const enviar = () => {
        /*
         * La fecha viaja solo si se cambió: quien abrió el diálogo con la
         * fecha vacía y solo sube la foto no tiene que borrar la que otra
         * persona cargó mientras tanto. El servidor distingue «no vino» de
         * «vino vacía».
         */
        const cambioLaFecha = conFecha && data.declaredPaidOn !== fechaAlAbrir;

        transform((datos) => ({
            ...(cambioLaFecha
                ? { declaredPaidOn: datos.declaredPaidOn || null }
                : {}),
            file: datos.file,
            title: datos.title,
        }));
        post(store(pago.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormDialog
            title="Comprobante del pago"
            description="La transferencia o la boleta del depósito realizado por el infractor."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Guardar"
        >
            {conFecha && (
                <div>
                    <Label htmlFor="comprobante-pagado">
                        Pagado el (según el comprobante)
                        <span className="ml-1 font-normal text-muted-foreground">
                            (opcional)
                        </span>
                    </Label>
                    <DateInput
                        id="comprobante-pagado"
                        className="mt-1"
                        max={businessToday()}
                        value={data.declaredPaidOn}
                        onChange={(e) =>
                            setData('declaredPaidOn', e.target.value)
                        }
                        aria-invalid={Boolean(errors.declaredPaidOn)}
                    />
                    <FieldError message={errors.declaredPaidOn} />
                </div>
            )}

            <PaperPhotoDropzone
                id="comprobante-archivo"
                label="Archivo"
                optional={conFecha}
                foto={data.file}
                onElegir={(archivo) => setData('file', archivo)}
                error={errors.file}
            />

            <div>
                <Label htmlFor="comprobante-descripcion">
                    Descripción
                    <span className="ml-1 font-normal text-muted-foreground">
                        (opcional)
                    </span>
                </Label>
                <Input
                    id="comprobante-descripcion"
                    className="mt-1"
                    maxLength={200}
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    aria-invalid={Boolean(errors.title)}
                />
                <FieldError message={errors.title} />
            </div>
        </FormDialog>
    );
}
