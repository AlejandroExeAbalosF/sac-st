import { usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';

type Opcion = { value: string; label: string };

/**
 * El motivo de un acto: se elige de la lista y el detalle es opcional, salvo
 * con «Otro» o cuando el acto lo exige siempre.
 *
 * La lista la manda el servidor en la prop `motivos`, por acto: es la misma
 * que valida el Form Request y la que acepta la base (`Reason`). Cada módulo
 * tipa sus actos y sus códigos.
 */
export default function ReasonFields<A extends string, C extends string>({
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
    detailRequired = false,
    maxLength = 500,
}: {
    act: A;
    code: C | '';
    detail: string;
    onCodeChange: (code: C) => void;
    onDetailChange: (detail: string) => void;
    codeError?: string;
    detailError?: string;
    idPrefix?: string;
    /** Cuándo hace falta, si no siempre: «si corregís un dato ya cargado». */
    hint?: string;
    detailPlaceholder?: string;
    /** Cuando el acto no tiene otro respaldo que el detalle, no es opcional. */
    detailRequired?: boolean;
    /** El largo de la columna del detalle. */
    maxLength?: number;
}) {
    const { motivos } = usePage<{ motivos?: Record<string, Opcion[]> }>().props;
    const opciones = motivos?.[act];

    if (opciones === undefined) {
        throw new Error(
            `La pantalla no recibió la lista de motivos de «${act}».`,
        );
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
                    onValueChange={(valor) => onCodeChange(valor as C)}
                >
                    <SelectTrigger
                        id={`${idPrefix}-codigo`}
                        className="mt-1"
                        aria-invalid={Boolean(codeError)}
                    >
                        <SelectValue placeholder="Elegí el motivo" />
                    </SelectTrigger>
                    <SelectContent>
                        {opciones.map((opcion) => (
                            <SelectItem key={opcion.value} value={opcion.value}>
                                {opcion.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError className="mt-1" message={codeError} />
            </div>
            <div>
                <Label htmlFor={`${idPrefix}-detalle`}>
                    Detalle
                    <span className="ml-1 font-normal text-muted-foreground">
                        {detailRequired
                            ? '(obligatorio)'
                            : otro
                              ? '(contá el motivo)'
                              : '(opcional)'}
                    </span>
                </Label>
                <Textarea
                    id={`${idPrefix}-detalle`}
                    className="mt-1"
                    rows={2}
                    maxLength={maxLength}
                    value={detail}
                    onChange={(e) => onDetailChange(e.target.value)}
                    placeholder={detailPlaceholder}
                    aria-invalid={Boolean(detailError)}
                />
                <InputError className="mt-1" message={detailError} />
            </div>
        </div>
    );
}
