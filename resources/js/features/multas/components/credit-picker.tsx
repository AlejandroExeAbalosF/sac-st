import { router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { FieldError } from '@/features/multas/components/form-dialog';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';

type Credito = App.Modules.Multas.Data.FineBankCreditData;

/**
 * Buscar y elegir un crédito del extracto de la cuenta de Multas.
 *
 * Los créditos no viajan con la página: se piden con una recarga parcial de
 * la prop que corresponda —los viejos para un pago anterior, los
 * disponibles para verificar un pago— al buscar.
 */
export default function CreditPicker({
    prop,
    param,
    creditos,
    selected,
    onSelect,
    emptyText,
    error,
}: {
    /** La prop opcional de la página que trae los créditos. */
    prop: string;
    /** El parámetro de la búsqueda que esa prop lee. */
    param: string;
    creditos?: Credito[];
    selected: number | null;
    onSelect: (id: number) => void;
    emptyText: string;
    error?: string;
}) {
    const [termino, setTermino] = useState('');
    const [buscando, setBuscando] = useState(false);
    /*
     * Lo último que se encontró. La prop solo viaja en la recarga de la
     * búsqueda: si el envío del formulario vuelve con un error, la página
     * llega sin ella, y sin esto el operador tendría que buscar de nuevo.
     */
    const [encontrados, setEncontrados] = useState(creditos);

    if (creditos !== undefined && creditos !== encontrados) {
        setEncontrados(creditos);
    }

    const buscar = () => {
        setBuscando(true);
        router.reload({
            only: [prop],
            data: { [param]: termino },
            onFinish: () => setBuscando(false),
        });
    };

    return (
        <>
            <div>
                <Label htmlFor={`${prop}-busqueda`}>Buscar el crédito</Label>
                <div className="mt-1 flex gap-2">
                    <Input
                        id={`${prop}-busqueda`}
                        value={termino}
                        onChange={(e) => setTermino(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                buscar();
                            }
                        }}
                        placeholder="Importe, empresa, CUIT u operación"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={buscar}
                        disabled={buscando}
                    >
                        <Search className="size-4" aria-hidden="true" />
                        Buscar
                    </Button>
                </div>
            </div>

            {encontrados !== undefined && (
                <div className="max-h-60 overflow-y-auto rounded-md border">
                    {encontrados.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            {emptyText}
                        </p>
                    ) : (
                        <ul className="divide-y" role="radiogroup">
                            {encontrados.map((credito) => {
                                const elegido = selected === credito.id;

                                return (
                                    <li key={credito.id}>
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={elegido}
                                            onClick={() => onSelect(credito.id)}
                                            className={cn(
                                                'flex w-full flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-left text-sm hover:bg-muted/50',
                                                elegido &&
                                                    'bg-primary/10 hover:bg-primary/10',
                                            )}
                                        >
                                            <span className="font-medium">
                                                <Money value={credito.amount} />
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {date(credito.date)}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                                                {credito.counterparty ??
                                                    credito.description ??
                                                    ''}
                                                {credito.operationId &&
                                                    ` · Op. ${credito.operationId}`}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}
            <FieldError message={error} />
        </>
    );
}
