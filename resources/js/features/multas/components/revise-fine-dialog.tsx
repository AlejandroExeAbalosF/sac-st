import { useForm } from '@inertiajs/react';
import PartialDateInput from '@/components/partial-date-input';
import PersonPicker from '@/components/person-picker';
import type { PersonOption } from '@/components/person-picker';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReasonFields from '@/features/multas/components/reason-fields';
import { businessToday } from '@/lib/format';
import { store } from '@/routes/multas/revisiones';

type Multa = App.Modules.Multas.Data.FineDetailData;

/**
 * Completar o corregir carátula, persona y fecha de registro.
 *
 * Llenar lo que faltaba no pide motivo. Cambiar lo que ya tenía valor es
 * una corrección: pide motivo y el permiso de ajustar, y la carátula del
 * papel queda guardada como estaba.
 */
export default function ReviseFineDialog({
    multa,
    onClose,
}: {
    multa: Multa;
    onClose: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm({
        caption: multa.caption,
        personId: multa.personId,
        registeredOn: multa.registeredOn,
        reasonCode: '' as App.Modules.Multas.Enums.FineReasonCode | '',
        reason: '',
        /*
         * Lo que mostraba el formulario al abrirse. El servidor compara
         * contra esto: no pisa lo que otro operador cambió mientras tanto.
         */
        expected: {
            caption: multa.caption,
            personId: multa.personId,
            registeredOn: multa.registeredOn,
        },
    });

    // La persona vinculada va como opción para que el buscador abra mostrándola.
    const opciones: PersonOption[] =
        multa.personId !== null && multa.personName !== null
            ? [
                  {
                      id: multa.personId,
                      name: multa.personName,
                      document: multa.personDocument,
                      type:
                          multa.personType === 'company'
                              ? 'company'
                              : 'individual',
                  },
              ]
            : [];

    return (
        <FormDialog
            title="Corregir datos de la multa"
            description="El texto original de la carátula se conserva. Si cambiás un dato que ya tenía valor, decí por qué."
            onClose={onClose}
            onSubmit={() =>
                post(store(multa.id).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Guardar"
        >
            <div>
                <Label htmlFor="revision-caratula">Carátula</Label>
                <Input
                    id="revision-caratula"
                    className="mt-1"
                    value={data.caption}
                    onChange={(e) => setData('caption', e.target.value)}
                    maxLength={300}
                    aria-invalid={Boolean(errors.caption)}
                />
                <FieldError message={errors.caption} />
            </div>

            <div>
                <Label htmlFor="revision-persona">
                    Persona
                    <span className="ml-1 font-normal text-muted-foreground">
                        (opcional)
                    </span>
                </Label>
                <div className="mt-1">
                    <PersonPicker
                        id="revision-persona"
                        value={data.personId}
                        onChange={(id) => setData('personId', id)}
                        options={opciones}
                        role={null}
                        invalid={Boolean(errors.personId)}
                        placeholder="Buscar en Personas…"
                    />
                </div>
                <FieldError message={errors.personId} />
            </div>

            <div>
                <Label htmlFor="revision-registro">Fecha de registro</Label>
                <PartialDateInput
                    id="revision-registro"
                    className="mt-1 max-w-48"
                    value={data.registeredOn}
                    onChange={(fecha) => setData('registeredOn', fecha)}
                    max={businessToday()}
                    aria-invalid={Boolean(errors.registeredOn)}
                />
                <FieldError message={errors.registeredOn} />
            </div>

            <ReasonFields
                act="correction"
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                idPrefix="revision-motivo"
                hint="si corregís un dato ya cargado"
            />
        </FormDialog>
    );
}
