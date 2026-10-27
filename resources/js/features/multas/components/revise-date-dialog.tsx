import { useForm } from '@inertiajs/react';
import PartialDateInput from '@/components/partial-date-input';
import ReasonFields from '@/components/reason-fields';
import { Label } from '@/components/ui/label';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { businessToday } from '@/lib/format';

/**
 * Completar o corregir la fecha de un movimiento de la obligación o de un
 * pago anterior: la de un importado que llegó sin ella, cuando aparece el
 * papel. El importe y lo que el registro aporta al saldo no cambian.
 *
 * Llenar una fecha desconocida o precisar el día dentro del mismo mes es
 * completar. Moverla o dejarla menos precisa es corregir, y pide motivo.
 */
export default function ReviseDateDialog({
    title,
    url,
    value,
    onClose,
}: {
    title: string;
    url: string;
    /** «2026-05-07», «2026-05» o vacío. */
    value: string;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        date: value,
        reasonCode: '' as App.Modules.Multas.Enums.FineReasonCode | '',
        reason: '',
        // Lo que mostraba el formulario al abrirse: no se pisa lo ajeno.
        expected: { date: value },
    });

    return (
        <FormDialog
            title={title}
            description="Solo la fecha: el importe y el efecto sobre el saldo no cambian."
            onClose={onClose}
            onSubmit={() =>
                post(url, { preserveScroll: true, onSuccess: onClose })
            }
            processing={processing}
            submitLabel="Guardar"
        >
            <div>
                <Label htmlFor="revision-fecha">
                    Fecha
                    <span className="ml-1 font-normal text-muted-foreground">
                        (día, solo el mes, o vacía)
                    </span>
                </Label>
                <PartialDateInput
                    id="revision-fecha"
                    className="mt-1 max-w-48"
                    value={data.date}
                    onChange={(fecha) => setData('date', fecha)}
                    max={businessToday()}
                    aria-invalid={Boolean(errors.date)}
                />
                <FieldError message={errors.date} />
            </div>

            <ReasonFields
                act="correction"
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                idPrefix="revision-fecha-motivo"
                hint="si corregís una fecha ya cargada"
            />
        </FormDialog>
    );
}
