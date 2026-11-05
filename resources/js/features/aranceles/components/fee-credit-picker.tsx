import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Input } from '@/components/ui/input';
import { apiGet } from '@/lib/api';
import { date, money } from '@/lib/format';
import { index as creditos } from '@/routes/aranceles/creditos';

export type Credito = App.Modules.Aranceles.Data.FeeBankCreditData;

/**
 * Elegir uno o más créditos del extracto. Se ofrece por lo que le queda libre:
 * cuánto se toma de cada uno lo decide el servidor, solo lo necesario.
 */
export default function FeeCreditPicker({
    seleccionados,
    onChange,
    excluir = [],
}: {
    seleccionados: Credito[];
    onChange: (creditos: Credito[]) => void;
    /** Los que ya se eligieron en otra parte de la pantalla. */
    excluir?: number[];
}) {
    const [buscado, setBuscado] = useState('');
    const [resultado, setResultado] = useState<Credito[] | null>(null);

    useEffect(() => {
        let vigente = true;
        const espera = setTimeout(() => {
            apiGet<Credito[]>(creditos({ query: { q: buscado } }).url)
                .then((d) => vigente && setResultado(d))
                .catch(() => vigente && setResultado([]));
        }, 250);

        return () => {
            vigente = false;
            clearTimeout(espera);
        };
    }, [buscado]);

    const elegido = (id: number) => seleccionados.some((c) => c.id === id);
    const alternar = (credito: Credito) =>
        onChange(
            elegido(credito.id)
                ? seleccionados.filter((c) => c.id !== credito.id)
                : [...seleccionados, credito],
        );

    const filas = (resultado ?? []).filter((c) => !excluir.includes(c.id));

    return (
        <div className="grid gap-2">
            <div className="relative">
                <Search
                    className="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    aria-label="Buscar créditos del extracto"
                    placeholder="Importe, operación o quien depositó"
                    className="pl-8"
                    value={buscado}
                    onChange={(e) => setBuscado(e.target.value)}
                />
            </div>
            {resultado !== null && filas.length === 0 ? (
                <p className="rounded-md border border-dashed p-4 text-center text-sm text-muted-foreground">
                    No hay créditos libres para mostrar.
                </p>
            ) : (
                <ul className="max-h-56 divide-y overflow-y-auto rounded-md border">
                    {filas.map((credito) => (
                        <li key={credito.id}>
                            <label className="flex cursor-pointer items-start gap-3 p-3 text-sm hover:bg-muted/40">
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={elegido(credito.id)}
                                    onChange={() => alternar(credito)}
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="font-medium tabular-nums">
                                        {money(credito.free)}
                                    </span>
                                    {credito.free !== credito.amount && (
                                        <span className="text-xs text-muted-foreground">
                                            {' '}
                                            libres de {money(credito.amount)}
                                        </span>
                                    )}
                                    <span className="block text-xs text-muted-foreground">
                                        {date(credito.date)}
                                        {credito.counterparty &&
                                            ` · ${credito.counterparty}`}
                                        {credito.operationId &&
                                            ` · operación ${credito.operationId}`}
                                    </span>
                                </span>
                            </label>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
