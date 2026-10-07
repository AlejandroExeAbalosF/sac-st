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
import { useDrawer } from '@/features/drawer/drawer-context';
import { date, isNonZero } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { chequesEnCustodia } from '@/routes/haberes';

type Cartera = App.Modules.Haberes.Data.ChequeInventoryData;
type Cheque = App.Modules.Haberes.Data.ChequeInCustodyData;

/**
 * Los cheques que hay en la caja, uno por uno, y para quién es cada uno.
 *
 * «Cheques en custodia» es un saldo: reservar un cheque para una cuota no
 * lo mueve, porque el papel sigue en la caja, solo cambia de dueño. Esto
 * abre ese saldo: cada cheque con su número, de dónde vino y a qué cuota
 * está asignado o reservado —o que todavía no tiene dueño—, más lo que la
 * apertura declaró como total sin detallar. Una cuota se abre en el panel
 * lateral.
 *
 * Es la cartera **de hoy**: el estado de un cheque es el de ahora, así que
 * mirando un día pasado se avisa en vez de mostrar algo que no fue.
 */
export default function ChequeInventoryDialog({
    abierto,
    onCerrar,
    moneda,
    fechaMirada,
    hoy,
}: {
    abierto: boolean;
    onCerrar: () => void;
    moneda: CurrencyCode;
    fechaMirada: string;
    hoy: string;
}) {
    const { openDrawer } = useDrawer();
    const [cartera, setCartera] = useState<Cartera | null>(null);
    const [falló, setFalló] = useState(false);

    // Se monta al abrir: cada apertura vuelve a preguntar.
    useEffect(() => {
        let vigente = true;

        fetch(chequesEnCustodia({ query: { currency: moneda } }).url, {
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
    }, [moneda]);

    const verCuota = (id: number) => {
        onCerrar();
        openDrawer({ kind: 'installment', id });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Cheques en custodia</DialogTitle>
                    <DialogDescription>
                        Cada cheque que está en la caja, de dónde vino y para
                        quién es. Reservar o asignar un cheque no cambia el
                        saldo: el papel sigue acá, lo que cambia es de quién es.
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
                        <Skeleton className="h-20 w-full" />
                        <Skeleton className="h-20 w-full" />
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
                                        onVerCuota={verCuota}
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
    received: 'Cobrado en el circuito',
    opening: 'De la apertura',
    legacy: 'Identificado al reservar',
};

/** Un cheque: el papel, de dónde vino y para quién es. */
function FilaDeCheque({
    cheque,
    onVerCuota,
}: {
    cheque: Cheque;
    onVerCuota: (id: number) => void;
}) {
    const delSistemaAnterior = cheque.origin !== 'received';

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
                {ORIGEN[cheque.origin]}
                {cheque.depositorName &&
                    ` · lo entregó ${cheque.depositorName}`}
                {' · '}en la caja desde el {date(cheque.receivedDate)}
                {cheque.issueDate && ` · emitido el ${date(cheque.issueDate)}`}
            </p>

            <ul className="grid gap-1">
                {cheque.assignments.map((asignacion) => (
                    <li key={asignacion.installmentId}>
                        <button
                            type="button"
                            onClick={() => onVerCuota(asignacion.installmentId)}
                            className="flex w-full items-center justify-between gap-3 rounded-md border px-3 py-1.5 text-left text-sm transition-colors hover:bg-muted"
                        >
                            <span className="min-w-0 truncate">
                                {asignacion.reserved
                                    ? 'Reservado para'
                                    : 'Para'}{' '}
                                Expte. {asignacion.expedienteNumber} ·{' '}
                                {asignacion.beneficiaryName} · cuota{' '}
                                {asignacion.installmentNumber}
                            </span>
                            <span className="flex shrink-0 items-center gap-1">
                                <Money value={asignacion.amount} />
                                <ChevronRight
                                    className="size-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </span>
                        </button>
                    </li>
                ))}

                {isNonZero(cheque.unassigned) && (
                    <li className="flex items-center justify-between gap-3 rounded-md border border-dashed px-3 py-1.5 text-sm text-muted-foreground">
                        <span>
                            {delSistemaAnterior
                                ? 'Del sistema anterior, sin reservar'
                                : 'Sin identificar: todavía no tiene dueño'}
                        </span>
                        <Money value={cheque.unassigned} />
                    </li>
                )}
            </ul>
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
