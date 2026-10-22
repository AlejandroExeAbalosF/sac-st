import { Link } from '@inertiajs/react';
import { ChevronRight, Info, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import Money from '@/components/money';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { date, isNonZero } from '@/lib/format';
import { show as fichaDeMulta } from '@/routes/multas';
import { cheques as chequesEnCustodia } from '@/routes/multas/caja';

type Cartera = App.Modules.Multas.Data.FineChequeInventoryData;
type Cheque = App.Modules.Multas.Data.FineChequeInCustodyData;

/**
 * Los cheques que hay en la caja de Multas, uno por uno, y qué multa paga
 * cada uno.
 *
 * Es la cartera **de hoy**, como la de Haberes: mirando un día pasado se
 * avisa en vez de mostrar algo que no fue. La de una fecha cerrada es la del
 * reverso de su planilla.
 */
export default function FineChequeInventoryDialog({
    onCerrar,
    fechaMirada,
    hoy,
}: {
    onCerrar: () => void;
    fechaMirada: string;
    hoy: string;
}) {
    const [cartera, setCartera] = useState<Cartera | null>(null);
    const [falló, setFalló] = useState(false);

    // Se monta al abrir: cada apertura vuelve a preguntar.
    useEffect(() => {
        let vigente = true;

        fetch(chequesEnCustodia().url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }

                return r.json();
            })
            .then((d: Cartera) => vigente && setCartera(d))
            .catch(() => vigente && setFalló(true));

        return () => {
            vigente = false;
        };
    }, []);

    return (
        <Dialog open onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Cheques en custodia</DialogTitle>
                    <DialogDescription>
                        Cada cheque que está en la caja de Multas, de dónde vino
                        y qué multa paga.
                    </DialogDescription>
                </DialogHeader>

                {fechaMirada !== hoy && (
                    <p className="flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                        <Info
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Es la cartera de hoy, no la del {date(fechaMirada)}: el
                        estado de cada cheque es el de ahora.
                    </p>
                )}

                {falló && (
                    <p className="flex items-start gap-2 rounded-md border border-current bg-destructive-soft p-3 text-sm text-destructive-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        No se pudo traer la cartera. Cerrá y volvé a intentarlo.
                    </p>
                )}

                {!falló && cartera === null && (
                    <div className="space-y-2">
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                    </div>
                )}

                {cartera && (
                    <>
                        {cartera.cheques.length === 0 ? (
                            <p className="rounded-lg border bg-card px-4 py-6 text-center text-sm text-muted-foreground">
                                No hay cheques cargados uno por uno en la caja.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {cartera.cheques.map((cheque) => (
                                    <FilaDeCheque
                                        key={cheque.id}
                                        cheque={cheque}
                                        abreMulta={cartera.canOpenFines}
                                    />
                                ))}
                            </ul>
                        )}

                        <dl className="grid gap-1.5 rounded-lg bg-muted/40 px-4 py-3 text-sm">
                            <Total
                                etiqueta={`Cheques detallados (${cartera.cheques.length})`}
                                valor={cartera.detailed}
                            />
                            {isNonZero(cartera.undetailed) && (
                                <Total
                                    etiqueta="Sin detallar: la apertura los declaró como total"
                                    valor={cartera.undetailed}
                                />
                            )}
                            <Total
                                etiqueta="Cheques en custodia"
                                valor={cartera.total}
                                fuerte
                            />
                        </dl>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

const ORIGEN: Record<Cheque['origin'], string> = {
    received: 'Cobrado en la caja',
    opening: 'De la apertura',
    legacy: 'Del sistema anterior',
    replacement: 'Reemplazo de un cheque rechazado',
};

/** Un cheque: el papel, de dónde vino y qué multa paga. */
function FilaDeCheque({
    cheque,
    abreMulta,
}: {
    cheque: Cheque;
    /** Sin `multas.ver`, la multa se nombra sin enlace: la ficha daría 403. */
    abreMulta: boolean;
}) {
    const multa = (
        <span className="min-w-0 truncate">
            Expte. {cheque.caseLabel ?? '—'}
            {cheque.caption && ` · ${cheque.caption}`}
        </span>
    );

    return (
        <li className="grid gap-2 px-4 py-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="text-sm font-medium">
                    <span className="font-mono tabular-nums">
                        N.º {cheque.number ?? 'sin número'}
                    </span>
                    {cheque.bank && (
                        <span className="text-muted-foreground">
                            {' '}
                            · {cheque.bank}
                        </span>
                    )}
                </p>
                <Money value={cheque.amount} />
            </div>

            <p className="text-xs text-muted-foreground">
                {ORIGEN[cheque.origin]} · en la caja desde el{' '}
                {date(cheque.receivedDate)}
                {cheque.issueDate && ` · emitido el ${date(cheque.issueDate)}`}
                {cheque.declaredDrawer &&
                    ` · librador declarado: ${cheque.declaredDrawer}`}
            </p>

            {cheque.fineId !== null && abreMulta ? (
                <Link
                    href={fichaDeMulta(cheque.fineId).url}
                    className="flex items-center justify-between gap-3 rounded-md border px-3 py-1.5 text-sm transition-colors hover:bg-muted"
                >
                    {multa}
                    <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            ) : cheque.fineId !== null ? (
                <p className="flex rounded-md border px-3 py-1.5 text-sm">
                    {multa}
                </p>
            ) : (
                <p className="rounded-md border border-dashed px-3 py-1.5 text-sm text-muted-foreground">
                    {cheque.declaredExpediente
                        ? `Declarado en la apertura para el expediente ${cheque.declaredExpediente}; todavía no paga ninguna multa`
                        : 'Todavía no paga ninguna multa'}
                </p>
            )}
        </li>
    );
}

function Total({
    etiqueta,
    valor,
    fuerte = false,
}: {
    etiqueta: string;
    valor: string;
    fuerte?: boolean;
}) {
    return (
        <div
            className={
                fuerte
                    ? 'flex justify-between gap-4 border-t pt-1.5 font-medium'
                    : 'flex justify-between gap-4 text-muted-foreground'
            }
        >
            <dt>{etiqueta}</dt>
            <dd>
                <Money value={valor} />
            </dd>
        </div>
    );
}
