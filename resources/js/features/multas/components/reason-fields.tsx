import { usePage } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { FieldError } from '@/features/multas/components/form-dialog';

type Acto = App.Modules.Multas.Enums.FineReasonAct;
type Codigo = App.Modules.Multas.Enums.FineReasonCode;
type Opcion = { value: Codigo; label: string };

/**
 * El motivo de un acto: se elige de la lista y el detalle es opcional, salvo
 * con «Otro».
 *
 * La lista la manda el servidor (`motivos`, por acto): es la misma que valida
 * el Form Request y la que acepta la base.
 */
export default function ReasonFields({
    act,
    code,
    detail,
    onCodeChange,
    onDetailChange,
    codeError,
    detailError,
    idPrefix = 'motivo',
    hint,
    detailPlaceholder,
}: {
    act: Acto;
    code: Codigo | '';
    detail: string;
    onCodeChange: (code: Codigo) => void;
    onDetailChange: (detail: string) => void;
    codeError?: string;
    detailError?: string;
    idPrefix?: string;
    /** Cuándo hace falta, si no siempre: «si corregís un dato ya cargado». */
    hint?: string;
    detailPlaceholder?: string;
}) {
    const { motivos } = usePage<{ motivos?: Record<Acto, Opcion[]> }>().props;

    if (motivos === undefined) {
        throw new Error('La pantalla no recibió las listas de motivos.');
    }

    const otro = code === 'other';

    return (
        <div className="grid gap-3">
            <div>
                <Label htmlFor={`${idPrefix}-codigo`}>
                    Motivo
                    {hint !== undefined && (
                        <span className="ml-1 font-normal text-muted-foreground">
                            ({hint})
                        </span>
                    )}
                </Label>
                <Select
                    value={code === '' ? undefined : code}
                    onValueChange={(valor) => onCodeChange(valor as Codigo)}
                >
                    <SelectTrigger
                        id={`${idPrefix}-codigo`}
                        className="mt-1"
                        aria-invalid={Boolean(codeError)}
                    >
                        <SelectValue placeholder="Elegí el motivo" />
                    </SelectTrigger>
                    <SelectContent>
                        {motivos[act].map((opcion) => (
                            <SelectItem key={opcion.value} value={opcion.value}>
                                {opcion.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <FieldError message={codeError} />
            </div>
            <div>
                <Label htmlFor={`${idPrefix}-detalle`}>
                    Detalle
                    <span className="ml-1 font-normal text-muted-foreground">
                        {otro ? '(contá el motivo)' : '(opcional)'}
                    </span>
                </Label>
                <Textarea
                    id={`${idPrefix}-detalle`}
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={detail}
                    onChange={(e) => onDetailChange(e.target.value)}
                    placeholder={detailPlaceholder}
                    aria-invalid={Boolean(detailError)}
                />
                <FieldError message={detailError} />
            </div>
        </div>
    );
}
