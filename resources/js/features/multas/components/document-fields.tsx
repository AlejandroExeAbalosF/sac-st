import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { FieldError } from '@/features/multas/components/form-dialog';
import { ACT_TYPE, DOCUMENT_KIND } from '@/features/multas/labels';

type Kind = App.Modules.Multas.Enums.FineDocumentKind;
type ActType = App.Modules.Multas.Enums.FineActType;

export type NewDocumentForm = {
    kind: Kind;
    actType: ActType | null;
    text: string;
    issuedOn: string;
};

export const EMPTY_DOCUMENT: NewDocumentForm = {
    kind: 'resolution',
    actType: null,
    text: '',
    issuedOn: '',
};

const EJEMPLO: Record<Kind, string> = {
    resolution: 'N°0649/26',
    act: 'N°1025288/25',
    other: 'Como figura en el papel',
};

/**
 * Los campos de un documento nuevo: tipo, tipo de acta, texto y fecha.
 *
 * La fecha acepta el día o solo el mes («2025-07»), porque hay papeles que
 * no dicen más. El texto se guarda como se escribe y el sistema lee de él
 * número y año.
 */
export default function DocumentFields({
    value,
    onChange,
    errors,
    idPrefix,
}: {
    value: NewDocumentForm;
    onChange: (value: NewDocumentForm) => void;
    errors: Record<string, string | undefined>;
    idPrefix: string;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div>
                <Label htmlFor={`${idPrefix}-tipo`}>Tipo de documento</Label>
                <Select
                    value={value.kind}
                    onValueChange={(kind) =>
                        onChange({
                            ...value,
                            kind: kind as Kind,
                            actType:
                                kind === 'act'
                                    ? (value.actType ?? 'HYS')
                                    : null,
                        })
                    }
                >
                    <SelectTrigger id={`${idPrefix}-tipo`} className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {(Object.keys(DOCUMENT_KIND) as Kind[]).map((kind) => (
                            <SelectItem key={kind} value={kind}>
                                {DOCUMENT_KIND[kind]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {value.kind === 'act' && (
                <div>
                    <Label htmlFor={`${idPrefix}-acta`}>Tipo de acta</Label>
                    <Select
                        value={value.actType ?? 'HYS'}
                        onValueChange={(actType) =>
                            onChange({ ...value, actType: actType as ActType })
                        }
                    >
                        <SelectTrigger id={`${idPrefix}-acta`} className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(ACT_TYPE) as ActType[]).map(
                                (tipo) => (
                                    <SelectItem key={tipo} value={tipo}>
                                        {tipo} · {ACT_TYPE[tipo]}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </div>
            )}

            <div className="sm:col-span-2">
                <Label htmlFor={`${idPrefix}-texto`}>Documento</Label>
                <Input
                    id={`${idPrefix}-texto`}
                    className="mt-1 font-mono"
                    value={value.text}
                    onChange={(e) =>
                        onChange({ ...value, text: e.target.value })
                    }
                    placeholder={EJEMPLO[value.kind]}
                    maxLength={120}
                    aria-invalid={Boolean(errors['document.text'])}
                />
                <FieldError
                    message={errors['document.text'] ?? errors['document.kind']}
                />
            </div>

            <div>
                <Label htmlFor={`${idPrefix}-fecha`}>
                    Fecha
                    <span className="ml-1 font-normal text-muted-foreground">
                        (opcional)
                    </span>
                </Label>
                <Input
                    id={`${idPrefix}-fecha`}
                    className="mt-1"
                    value={value.issuedOn}
                    onChange={(e) =>
                        onChange({ ...value, issuedOn: e.target.value })
                    }
                    placeholder="2026-05-07 o 2026-05"
                    aria-invalid={Boolean(errors['document.issuedOn'])}
                />
                <FieldError message={errors['document.issuedOn']} />
            </div>
        </div>
    );
}
