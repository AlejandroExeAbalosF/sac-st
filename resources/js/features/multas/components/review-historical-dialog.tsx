import { useForm } from '@inertiajs/react';
import Money from '@/components/money';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { HISTORICAL_KIND, partialDate } from '@/features/multas/labels';
import { review } from '@/routes/multas/antecedentes';

type Antecedente = App.Modules.Multas.Data.FineHistoricalMovementData;

/**
 * Validar o descartar un antecedente. Se hace una sola vez.
 */
export default function ReviewHistoricalDialog({
    antecedente,
    resultado,
    onClose,
}: {
    antecedente: Antecedente;
    resultado: 'validated' | 'rejected';
    onClose: () => void;
}) {
    const valida = resultado === 'validated';
    const { data, setData, post, processing, errors } = useForm({
        status: resultado,
        note: '',
    });

    return (
        <FormDialog
            title={
                valida
                    ? 'Validar el pago anterior'
                    : 'Descartar el pago anterior'
            }
            description={
                valida
                    ? 'Al validarlo, cuenta en el saldo de la multa. No se puede deshacer.'
                    : 'Queda a la vista como descartado, sin contar en el saldo.'
            }
            onClose={onClose}
            onSubmit={() =>
                post(review(antecedente.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel={valida ? 'Validar' : 'Descartar'}
            destructive={!valida}
        >
            <p className="rounded-md border bg-muted/40 p-3 text-sm">
                {HISTORICAL_KIND[antecedente.kind]} de{' '}
                <Money value={antecedente.amount} /> ·{' '}
                {partialDate(
                    antecedente.occurredOn,
                    antecedente.occurredPrecision,
                )}
                <br />
                <span className="text-muted-foreground">
                    {antecedente.evidence}
                </span>
            </p>

            <div>
                <Label htmlFor="revision-nota">
                    {valida ? 'Nota' : 'Motivo'}
                    {valida && (
                        <span className="ml-1 font-normal text-muted-foreground">
                            (opcional)
                        </span>
                    )}
                </Label>
                <Textarea
                    id="revision-nota"
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={data.note}
                    onChange={(e) => setData('note', e.target.value)}
                    placeholder={
                        valida
                            ? 'Contra qué se verificó.'
                            : 'Por qué no corresponde.'
                    }
                    aria-invalid={Boolean(errors.note)}
                />
                <FieldError message={errors.note} />
            </div>
        </FormDialog>
    );
}
