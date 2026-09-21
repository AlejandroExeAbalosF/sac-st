import { router } from '@inertiajs/react';
import { Landmark, ListFilter, SearchX } from 'lucide-react';
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
    candidates as candidatos,
    confirm as acreditar,
} from '@/routes/traslados';

type Traslado = App.Modules.Haberes.Data.InstallmentTransferData;

type Respuesta = {
    candidates: Candidato[];
    todos: boolean;
    /** Si el extracto de la fecha del depósito ya se importó. */
    periodImported: boolean;
};

type Candidato = {
    id: number;
    date: string | null;
    amount: string;
    description: string | null;
    operationId: string | null;
    signals: Record<string, string>;
};

/**
 * El crédito del extracto que confirma nuestro propio depósito.
 *
 * **Propone, no vincula.** Confirmar es de una persona porque un crédito
 * mal atribuido cierra una ventana de tránsito que en realidad sigue
 * abierta, y deja un depósito perdido que nadie va a reclamar.
 *
 * Vincularlo tiene un segundo efecto que no se ve: ese movimiento queda
 * sin saldo libre, y deja de poder registrarse además como dinero que
 * entró. Sin eso, el mismo depósito se contaría dos veces.
 */
export default function CreditMatchDialog({
    traslado,
    abierto,
    onCerrar,
}: {
    traslado: Traslado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [datos, setDatos] = useState<Respuesta | null>(null);
    const [todos, setTodos] = useState(false);
    const [elegido, setElegido] = useState<number | null>(null);
    const [enviando, setEnviando] = useState(false);

    /*
     * Se monta al abrirse, asi que el estado nace limpio y el efecto solo
     * busca. Resetearlo aca adentro seria pedirle a React que renderice
     * dos veces para llegar al mismo lugar.
     */
    useEffect(() => {
        let vigente = true;
        const url = candidatos(traslado.id).url + (todos ? '?todos=1' : '');

        fetch(url, { headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((d: Respuesta) => vigente && setDatos(d))
            .catch(
                () =>
                    vigente &&
                    setDatos({
                        candidates: [],
                        todos,
                        periodImported: true,
                    }),
            );

        return () => {
            vigente = false;
        };
    }, [traslado.id, todos]);

    /*
     * Cargando es que lo que tenemos no corresponde al modo pedido. Se
     * deriva del dato en vez de vaciarlo al pedir el otro: un setState
     * sincrono dentro del efecto obliga a React a renderizar dos veces
     * para llegar al mismo lugar.
     */
    const cargando = datos === null || datos.todos !== todos;
    const lista = cargando ? null : datos.candidates;

    const confirmar = () => {
        if (elegido === null) {
            return;
        }

        setEnviando(true);

        router.post(
            acreditar(traslado.id).url,
            {
                bankTransactionId: elegido,
                idempotencyKey: `acreditacion:${traslado.id}:${elegido}`,
            },
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
                    <DialogTitle>Buscar la acreditación</DialogTitle>
                    <DialogDescription>
                        {todos
                            ? 'Todos los créditos de la cuenta que todavía pueden respaldar algo, ordenados por parecido al depósito.'
                            : `Créditos de ${money(traslado.amount)} en la misma cuenta, desde el día del depósito.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-4 space-y-2">
                    {lista === null && (
                        <p className="flex items-center gap-2 py-6 text-sm text-muted-foreground">
                            <Spinner className="size-4" />
                            Buscando en el extracto…
                        </p>
                    )}

                    {lista?.length === 0 && (
                        <div className="space-y-3 rounded-md border border-dashed p-4">
                            <p className="flex items-start gap-2 text-sm text-muted-foreground">
                                <SearchX className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {todos
                                        ? 'La cuenta no tiene ningún crédito disponible: o están todos imputados, o el extracto no llegó.'
                                        : 'Ningún crédito coincide con el importe y la fecha del depósito.'}
                                </span>
                            </p>

                            {/*
                             * El aviso se verifica, no se supone: sin esto
                             * la pantalla ofrecía una excusa genérica que
                             * el operador no podía comprobar sin salir a
                             * mirar.
                             */}
                            {datos?.periodImported === false && (
                                <p className="text-xs text-warning-strong">
                                    El extracto del {date(traslado.depositDate)}{' '}
                                    todavía no está importado, así que el
                                    crédito no puede estar todavía.
                                </p>
                            )}

                            {!todos && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setElegido(null);
                                        setTodos(true);
                                    }}
                                >
                                    <ListFilter className="size-4" />
                                    Ver todos los créditos de la cuenta
                                </Button>
                            )}
                        </div>
                    )}

                    {lista?.map((c) => (
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
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-sm font-medium">
                                    {c.date === null ? '—' : date(c.date)}
                                </span>
                                <span className="font-mono text-sm tabular-nums">
                                    {money(c.amount)}
                                </span>
                            </div>
                            {c.description !== null && (
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {c.description}
                                </p>
                            )}
                            <p className="mt-1.5 text-xs text-muted-foreground">
                                {Object.entries(c.signals)
                                    .map(([k, v]) => `${k}: ${v}`)
                                    .join(' · ')}
                            </p>
                        </button>
                    ))}
                    {!todos && lista !== null && lista.length > 0 && (
                        <button
                            type="button"
                            onClick={() => {
                                setElegido(null);
                                setTodos(true);
                            }}
                            className="w-full pt-1 text-left text-xs text-muted-foreground underline-offset-2 hover:underline"
                        >
                            Ninguno es. Ver todos los créditos de la cuenta.
                        </button>
                    )}

                    {todos && lista !== null && lista.length > 0 && (
                        <p className="pt-1 text-xs text-muted-foreground">
                            Los {lista.length} créditos disponibles más
                            parecidos, ordenados por diferencia de importe y
                            después por cercanía de fecha.
                        </p>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button variant="outline" onClick={onCerrar}>
                        Cancelar
                    </Button>
                    <Button
                        onClick={confirmar}
                        disabled={elegido === null || enviando}
                    >
                        <Landmark className="size-4" />
                        Confirmar la acreditación
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
