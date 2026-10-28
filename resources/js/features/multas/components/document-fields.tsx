import PartialDateInput from '@/components/partial-date-input';
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
import LoadedDocumentWarning from '@/features/multas/components/loaded-document-warning';
import NumberReadingHint from '@/features/multas/components/number-reading-hint';
import { documentYearWarning } from '@/features/multas/document-year';
import { ACT_TYPE, DOCUMENT_KIND } from '@/features/multas/labels';
import { findLoadedDocument } from '@/features/multas/loaded-document';
import { readDocumentNumber } from '@/features/multas/number-reading';
import { businessToday } from '@/lib/format';

type Kind = App.Modules.Multas.Enums.FineDocumentKind;
type Documento = App.Modules.Multas.Data.FineDocumentData;
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
    kinds = Object.keys(DOCUMENT_KIND) as Kind[],
    dateRequired = false,
    loaded = [],
    onUseLoaded,
}: {
    value: NewDocumentForm;
    onChange: (value: NewDocumentForm) => void;
    errors: Record<string, string | undefined>;
    idPrefix: string;
    /** Los tipos que se ofrecen; por defecto, todos. */
    kinds?: Kind[];
    /** Si la fecha es obligatoria: la de una resolución de origen lo es. */
    dateRequired?: boolean;
    /** Los documentos del expediente: si el número ya está, se avisa. */
    loaded?: Documento[];
    /** Usar el ya cargado en vez de cargar otro; `null` si ese no se puede usar acá. */
    onUseLoaded?: (documento: Documento) => (() => void) | null;
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
                        {kinds.map((kind) => (
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
                <NumberReadingHint
                    reading={readDocumentNumber(
                        value.text,
                        value.kind,
                        value.issuedOn,
                        value.actType,
                    )}
                    text={value.text}
                />
                <AvisoDeCargado
                    documento={findLoadedDocument(
                        loaded,
                        value.kind,
                        value.text,
                        value.actType,
                    )}
                    onUseLoaded={onUseLoaded}
                />
                {/* Aviso, no error: el año del número no coincide con la fecha. */}
                {value.kind !== 'other' &&
                    documentYearWarning(value.text, value.issuedOn) && (
                        <p className="mt-1 text-xs text-warning-strong">
                            {documentYearWarning(value.text, value.issuedOn)}
                        </p>
                    )}
            </div>

            <div>
                <Label htmlFor={`${idPrefix}-fecha`}>
                    Fecha
                    {!dateRequired && (
                        <span className="ml-1 font-normal text-muted-foreground">
                            (opcional)
                        </span>
                    )}
                </Label>
                <PartialDateInput
                    id={`${idPrefix}-fecha`}
                    className="mt-1"
                    value={value.issuedOn}
                    onChange={(issuedOn) => onChange({ ...value, issuedOn })}
                    max={businessToday()}
                    aria-invalid={Boolean(errors['document.issuedOn'])}
                />
                <FieldError message={errors['document.issuedOn']} />
            </div>
        </div>
    );
}

function AvisoDeCargado({
    documento,
    onUseLoaded,
}: {
    documento: Documento | null;
    onUseLoaded?: (documento: Documento) => (() => void) | null;
}) {
    return (
        <LoadedDocumentWarning
            documento={documento}
            onUse={
                documento === null
                    ? undefined
                    : (onUseLoaded?.(documento) ?? undefined)
            }
        />
    );
}
