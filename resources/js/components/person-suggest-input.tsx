import { CircleUserRound } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { apiGet, isAbort } from '@/lib/api';
import { dni } from '@/lib/format';
import { cn } from '@/lib/utils';

type Sugerencia = {
    id: number;
    name: string;
    document: string | null;
};

type Props = {
    id: string;
    /** El texto tal cual queda guardado. */
    value: string;
    onChange: (value: string) => void;
    /**
     * El titular del organismo elegido, si tiene. Es el candidato más
     * probable, así que se ofrece primero y sin necesidad de tipear.
     */
    preferido?: string | null;
    placeholder?: string;
    maxLength?: number;
    invalid?: boolean;
};

const DELAY_MS = 250;
const MINIMO = 2;

/**
 * Campo de texto que sugiere personas del maestro.
 *
 * **Guarda texto, no una ficha.** El representante es lo que dice *ese*
 * papel: si mañana esa persona corrige su apellido, el expediente tiene
 * que seguir diciendo lo que decía el papel, igual que hacen los
 * comprobantes con los nombres que congelan al emitirse.
 *
 * Lo que sí evita es que la misma persona quede escrita de tres formas
 * distintas en tres expedientes, que es lo único que el tipeo libre
 * costaba de verdad.
 */
export default function PersonSuggestInput({
    id,
    value,
    onChange,
    preferido,
    placeholder,
    maxLength = 160,
    invalid = false,
}: Props) {
    const listId = useId();
    const contenedor = useRef<HTMLDivElement>(null);
    const [abierto, setAbierto] = useState(false);
    const [encontradas, setEncontradas] = useState<Sugerencia[]>([]);
    const [activo, setActivo] = useState(-1);

    useEffect(() => {
        if (!abierto || value.trim().length < MINIMO) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const payload = await apiGet<{ data: Sugerencia[] }>(
                    `/people?type=individual&q=${encodeURIComponent(value.trim())}`,
                    controller.signal,
                );

                setEncontradas(payload.data);
                setActivo(-1);
            } catch (error) {
                if (!isAbort(error)) {
                    // Sugerir es una ayuda, no un requisito: si la consulta
                    // falla, el campo sigue siendo de texto libre.
                    setEncontradas([]);
                }
            }
        }, DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [abierto, value]);

    /*
     * Por debajo del mínimo no hay nada que sugerir, y lo que se encontró
     * recién tampoco corresponde ya: borrar «Juan» hasta «J» dejaba visibles
     * las coincidencias de «Juan». Se descarta al leerlo, no borrando el
     * estado: así vale igual cuando el valor lo cambia el formulario —un
     * alta que se limpia sola— y no cuando solamente se tipeó.
     */
    const sugerencias = value.trim().length < MINIMO ? [] : encontradas;

    /*
     * El titular encabeza la lista mientras lo escrito no lo contradiga, y
     * no se repite si el buscador ya lo trajo.
     */
    const titularAplica =
        Boolean(preferido) &&
        (value.trim() === '' ||
            preferido!.toLowerCase().includes(value.trim().toLowerCase())) &&
        !sugerencias.some((persona) => persona.name === preferido);

    const opciones: Array<Sugerencia & { esTitular?: boolean }> = [
        ...(titularAplica
            ? [{ id: -1, name: preferido!, document: null, esTitular: true }]
            : []),
        ...sugerencias,
    ];

    const mostrar = abierto && opciones.length > 0;

    /* Descartada una sugerencia, el resaltado pudo quedar fuera de la lista. */
    const resaltado = activo < opciones.length ? activo : -1;

    const elegir = (nombre: string) => {
        onChange(nombre);
        setAbierto(false);
        setActivo(-1);
    };

    const alTeclear = (event: React.KeyboardEvent) => {
        if (!mostrar) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const paso = event.key === 'ArrowDown' ? 1 : -1;
            setActivo((actual) => {
                const siguiente = actual + paso;

                return siguiente < 0
                    ? opciones.length - 1
                    : siguiente % opciones.length;
            });

            return;
        }

        if (event.key === 'Enter' && resaltado >= 0) {
            event.preventDefault();
            elegir(opciones[resaltado].name);

            return;
        }

        if (event.key === 'Escape') {
            setAbierto(false);
        }
    };

    return (
        <div className="relative" ref={contenedor}>
            <Input
                id={id}
                value={value}
                onChange={(event) => {
                    onChange(event.target.value);
                    setAbierto(true);
                }}
                onFocus={() => setAbierto(true)}
                onBlur={(event) => {
                    // Sin esto, el clic sobre una sugerencia llega después
                    // del blur y la lista ya no está para recibirlo.
                    if (!contenedor.current?.contains(event.relatedTarget)) {
                        setAbierto(false);
                    }
                }}
                onKeyDown={alTeclear}
                className="mt-1"
                autoComplete="off"
                role="combobox"
                aria-expanded={mostrar}
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={
                    resaltado >= 0 ? `${listId}-${resaltado}` : undefined
                }
                maxLength={maxLength}
                placeholder={placeholder}
                aria-invalid={invalid}
            />

            {mostrar && (
                <ul
                    id={listId}
                    role="listbox"
                    className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border bg-popover p-1 shadow-raised"
                >
                    {opciones.map((persona, indice) => (
                        <li key={`${persona.id}-${persona.name}`}>
                            <button
                                id={`${listId}-${indice}`}
                                type="button"
                                role="option"
                                aria-selected={indice === resaltado}
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => elegir(persona.name)}
                                onMouseEnter={() => setActivo(indice)}
                                className={cn(
                                    'flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm transition-colors duration-100',
                                    indice === resaltado && 'bg-accent',
                                )}
                            >
                                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                                    <CircleUserRound
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate">
                                        {persona.name}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {persona.esTitular
                                            ? 'Titular del organismo'
                                            : persona.document
                                              ? `DNI ${dni(persona.document)}`
                                              : 'Sin documento'}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
