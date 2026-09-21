import { router } from '@inertiajs/react';
import { Landmark, SearchX } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    debits as candidatos,
    linkDebit as vincular,
} from '@/routes/haberes/installments/disbursement';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

type Respuesta = { candidates: Candidato[] };

type Candidato = {
    id: number;
    date: string | null;
    amount: string;
    description: string | null;
    operationId: string | null;
    signals: Record<string, string>;
};

/**
 * El débito del extracto con que el organismo pagó — §2.3.2.
 *
 * **Propone, no vincula.** Confirmar es de una persona porque un débito
 * mal atribuido daría por pagada una cuota con la transferencia de otra, y
 * dejaría a un trabajador esperando un dinero que el sistema cree
 * entregado.
 *
 * Reconocerlo tiene un segundo efecto que no se ve: al validar, ese
 * movimiento queda sin saldo libre y deja de ofrecerse para otra Orden.
 * Sin eso, la misma transferencia podría pagar dos cuotas.
 */
export default function TransferDebitDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    estado: Estado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [datos, setDatos] = useState<Respuesta | null>(null);
    const [elegido, setElegido] = useState<number | null>(null);
    const [enviando, setEnviando] = useState(false);

    /*
     * Se monta al abrirse, así que el estado nace limpio y el efecto solo
     * busca. Vaciarlo acá adentro sería pedirle a React que renderice dos
     * veces para llegar al mismo lugar.
     */
    useEffect(() => {
        let vigente = true;

        fetch(candidatos(cuota.id).url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then((r: Respuesta) => {
                if (vigente) {
                    setDatos(r);
                }
            })
            .catch(() => {
                if (vigente) {
                    setDatos({ candidates: [] });
                }
            });

        return () => {
            vigente = false;
        };
    }, [cuota.id]);

    const confirmar = () => {
        if (elegido === null) {
            return;
        }

        setEnviando(true);

        router.post(
            vincular(cuota.id).url,
            { bankTransactionId: elegido },
            {
                preserveScroll: true,
                onFinish: () => setEnviando(false),
                onSuccess: () => onCerrar(),
            },
        );
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>El débito en el extracto</DialogTitle>
                    <DialogDescription>
                        Movimientos de la cuenta del organismo por{' '}
                        {money(estado.amount)} exactos, posteriores a la Orden{' '}
                        {estado.orderNumber ?? ''} y todavía sin imputar.
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-2 max-h-[50vh] space-y-2 overflow-y-auto">
                    {datos === null && (
                        <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
                            <Spinner className="size-4" />
                            Buscando en el extracto…
                        </div>
                    )}

                    {datos !== null && datos.candidates.length === 0 && (
                        <div className="space-y-2 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                            <p className="flex items-center gap-2 font-medium text-foreground">
                                <SearchX
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                No hay ningún débito que coincida
                            </p>
                            {/*
                             * Casi nunca significa que la transferencia no
                             * exista: lo más común es que el extracto de
                             * ese período todavía no se haya importado.
                             */}
                            <p>
                                Lo más probable es que el extracto del período
                                todavía no esté importado. También puede ser que
                                el organismo no haya transferido aún, o que el
                                importe difiera del de la Orden.
                            </p>
                        </div>
                    )}

                    {datos?.candidates.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            onClick={() => setElegido(c.id)}
                            className={cn(
                                'w-full rounded-lg border p-3 text-left transition-colors',
                                elegido === c.id
                                    ? 'border-primary bg-primary/5'
                                    : 'hover:bg-muted/50',
                            )}
                        >
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <span className="flex items-center gap-1.5 text-sm font-medium">
                                    <Landmark
                                        className="size-3.5 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {c.date === null
                                        ? 'Sin fecha'
                                        : date(c.date)}
                                </span>
                                <span className="font-mono text-sm font-semibold tabular-nums">
                                    {money(c.amount)}
                                </span>
                            </div>

                            {c.description !== null && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {c.description}
                                </p>
                            )}

                            {c.operationId !== null && (
                                <p className="mt-0.5 font-mono text-xs text-muted-foreground">
                                    Op. {c.operationId}
                                </p>
                            )}

                            {/*
                             * Las señales en palabras y no un puntaje: un
                             * «85 %» obliga a confiar, esto deja decidir.
                             */}
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {Object.entries(c.signals).map(([k, v]) => (
                                    <span
                                        key={k}
                                        className="rounded bg-muted px-1.5 py-0.5 text-[0.7rem] text-muted-foreground"
                                    >
                                        {k}: {v}
                                    </span>
                                ))}
                            </div>
                        </button>
                    ))}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onCerrar}>
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={confirmar}
                        disabled={elegido === null || enviando}
                    >
                        <Landmark className="size-4" />
                        Es este débito
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
