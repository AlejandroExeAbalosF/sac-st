import { Check, ChevronDown } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { coincideTipo } from '@/features/aranceles/lib/fee-type-search';
import { cn } from '@/lib/utils';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;

type Props = {
    id?: string;
    types: Tipo[];
    value: number | null;
    onChange: (tipo: Tipo) => void;
    placeholder?: string;
    invalid?: boolean;
    className?: string;
};

/**
 * El select con buscador de los tipos de arancel: por código, inciso o concepto.
 *
 * La lista viene entera en las props —son pocas decenas—, así que busca en el
 * navegador sin consultar al servidor. Quien usa el componente decide qué tipos
 * ofrece: un cobro nuevo le pasa solo los activos.
 */
export default function FeeTypeCombobox({
    id,
    types,
    value,
    onChange,
    placeholder = 'Buscá por código, inciso o concepto…',
    invalid = false,
    className,
}: Props) {
    const [abierto, setAbierto] = useState(false);
    const [consulta, setConsulta] = useState('');

    const seleccionado = types.find((tipo) => tipo.id === value) ?? null;
    const resultados = useMemo(
        () => types.filter((tipo) => coincideTipo(tipo, consulta)),
        [types, consulta],
    );

    return (
        <Popover
            open={abierto}
            onOpenChange={(siguiente) => {
                setAbierto(siguiente);

                if (!siguiente) {
                    setConsulta('');
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={abierto}
                    aria-invalid={invalid}
                    className={cn(
                        'w-full justify-between bg-card px-3 font-normal hover:bg-card',
                        !seleccionado && 'text-muted-foreground',
                        className,
                    )}
                >
                    <span className="truncate text-left">
                        {seleccionado
                            ? `${seleccionado.code} · ${seleccionado.name}`
                            : placeholder}
                    </span>
                    <ChevronDown
                        className="size-4 shrink-0 opacity-50"
                        aria-hidden="true"
                    />
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className="w-[min(36rem,calc(100vw-2rem))] p-0"
            >
                <Command shouldFilter={false}>
                    <CommandInput
                        value={consulta}
                        onValueChange={setConsulta}
                        placeholder={placeholder}
                    />
                    <CommandList>
                        {resultados.length === 0 && (
                            <p className="p-4 text-center text-sm text-muted-foreground">
                                Ningún arancel coincide con la búsqueda.
                            </p>
                        )}
                        <CommandGroup>
                            {resultados.map((tipo) => (
                                <CommandItem
                                    key={tipo.id}
                                    value={String(tipo.id)}
                                    onSelect={() => {
                                        onChange(tipo);
                                        setAbierto(false);
                                        setConsulta('');
                                    }}
                                >
                                    <span className="w-7 shrink-0 text-right text-muted-foreground tabular-nums">
                                        {tipo.code}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        {tipo.name}
                                    </span>
                                    {tipo.id === value && (
                                        <Check
                                            className="size-4 shrink-0"
                                            aria-hidden="true"
                                        />
                                    )}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
