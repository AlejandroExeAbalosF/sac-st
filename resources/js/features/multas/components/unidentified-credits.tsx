import { Link, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date, dateTime, money } from '@/lib/format';
import {
    index as cajaDeMultas,
    multas as buscarMultas,
} from '@/routes/multas/caja';
import { verify } from '@/routes/multas/pagos';
import { store as verificados } from '@/routes/multas/pagos-bancarios/verificados';

type CreditoSinIdentificar = App.Modules.Multas.Data.FineUnidentifiedCreditData;
type Informado = App.Modules.Multas.Data.FinePendingPaymentData;
type Medio = App.Modules.Multas.Enums.FinePaymentMedium;
type MultaHallada = {
    id: number;
    caseLabel: string;
    caption: string;
    debt: string;
};

const MEDIOS_POR_BANCO: Medio[] = ['bank_transfer', 'direct_deposit', 'echeq'];

/**
 * Los créditos de la cuenta compartida que nadie identificó (M08).
 *
 * Son de la cuenta, no una deuda de Multas: pueden ser de Aranceles. Lo que no
 * es de Multas queda acá, sin asignarle una caja por descarte. Identificar uno
 * no crea otro movimiento bancario: registra por primera vez en el libro de
 * Multas el crédito que ya estaba en el extracto, con su aplicación y su recibo.
 */
export default function UnidentifiedCredits({
    creditos,
    pagina,
    ultimaPagina,
    total,
    cantidad,
    puedeIdentificar,
    firmantes,
}: {
    creditos: CreditoSinIdentificar[];
    /** La página de la cola que se muestra: van de a cincuenta, del más viejo. */
    pagina: number;
    ultimaPagina: number;
    total: string;
    cantidad: number;
    puedeIdentificar: boolean;
    firmantes: Firmante[];
}) {
    const [identificando, setIdentificando] =
        useState<CreditoSinIdentificar | null>(null);

    return (
        <section className="rounded-lg border bg-card shadow-raised">
            <header className="border-b px-5 py-4">
                <h2 className="text-sm font-semibold">
                    Créditos sin identificar
                </h2>
                <p className="mt-1 text-xs text-muted-foreground">
                    Lo que entró a la cuenta compartida con Aranceles después de
                    su corte y nadie reconoció todavía. No es una deuda de
                    Multas: lo que no sea de Multas queda acá.
                </p>
            </header>

            {creditos.length === 0 ? (
                <p className="px-5 py-6 text-center text-sm text-muted-foreground">
                    No hay créditos sin identificar.
                </p>
            ) : (
                <ul className="divide-y">
                    {creditos.map((fila) => (
                        <li
                            key={fila.credit.id}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3 text-sm"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-medium">
                                    {date(fila.credit.date)} ·{' '}
                                    <Money value={fila.credit.amount} />
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {[
                                        fila.credit.counterparty,
                                        fila.credit.operationId &&
                                            `operación ${fila.credit.operationId}`,
                                        fila.credit.description,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                                {fila.reported.length > 0 && (
                                    <p className="text-xs text-info-strong">
                                        {fila.reported.length === 1
                                            ? 'Hay un pago informado de este importe.'
                                            : `Hay ${fila.reported.length} pagos informados de este importe.`}
                                    </p>
                                )}
                            </div>
                            {puedeIdentificar && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setIdentificando(fila)}
                                >
                                    Identificar
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {/*
             * Por páginas y no los primeros cincuenta: los de Aranceles se
             * quedan en la cola, y sin recorrerla los de atrás no se alcanzan.
             */}
            {ultimaPagina > 1 && (
                <nav
                    aria-label="Páginas de la cola"
                    className="flex flex-wrap items-center justify-between gap-2 border-t px-5 py-3 text-xs text-muted-foreground"
                >
                    <span>
                        Página {pagina} de {ultimaPagina} · {cantidad} créditos
                        por <Money value={total} />
                    </span>
                    <span className="flex gap-2">
                        <PaginaDeLaCola
                            destino={pagina - 1}
                            habilitado={pagina > 1}
                        >
                            Anteriores
                        </PaginaDeLaCola>
                        <PaginaDeLaCola
                            destino={pagina + 1}
                            habilitado={pagina < ultimaPagina}
                        >
                            Siguientes
                        </PaginaDeLaCola>
                    </span>
                </nav>
            )}

            {identificando && (
                <IdentifyCreditDialog
                    fila={identificando}
                    firmantes={firmantes}
                    onClose={() => setIdentificando(null)}
                />
            )}
        </section>
    );
}

/** Otra página de la cola, sin perder el lugar en la pantalla. */
function PaginaDeLaCola({
    destino,
    habilitado,
    children,
}: {
    destino: number;
    habilitado: boolean;
    children: React.ReactNode;
}) {
    if (!habilitado) {
        return (
            <Button size="sm" variant="outline" disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button size="sm" variant="outline" asChild>
            <Link
                href={cajaDeMultas({ query: { creditos: destino } }).url}
                only={['creditos']}
                preserveScroll
                preserveState
            >
                {children}
            </Link>
        </Button>
    );
}

const NUEVO = 'nuevo';

/**
 * Identificar un crédito por uno de dos caminos.
 *
 * Si alguien ya lo informó, se verifica ese pago: conserva su presentación
 * —que decide el término— y su respaldo. Crear otro pago el día que se
 * identifica podría dejarlo fuera de término y dejar el informado colgado.
 * Solo si nadie lo informó se busca la multa y se registra el pago.
 */
function IdentifyCreditDialog({
    fila,
    firmantes,
    onClose,
}: {
    fila: CreditoSinIdentificar;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const credito = fila.credit;
    const [clave] = useState(
        () => `identificar:${credito.id}:${crypto.randomUUID()}`,
    );
    const [camino, setCamino] = useState<string>(
        fila.reported[0] ? String(fila.reported[0].payment.id) : NUEVO,
    );
    const [multa, setMulta] = useState<MultaHallada | null>(null);
    const [faltaMulta, setFaltaMulta] = useState(false);
    const { data, setData, post, transform, processing, errors } = useForm({
        medium: 'bank_transfer' as Medio,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });
    const errores = errors as Record<string, string | undefined>;

    const enviar = () => {
        const recibo = receiptPayload(data.talonarioNumber, data.signedById);

        if (camino !== NUEVO) {
            transform(() => ({ bankTransactionId: credito.id, ...recibo }));
            post(verify(Number(camino)).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        if (multa === null) {
            setFaltaMulta(true);

            return;
        }

        transform((datos) => ({
            medium: datos.medium,
            bankTransactionId: credito.id,
            idempotencyKey: clave,
            ...recibo,
        }));
        post(verificados(multa.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <FormDialog
            title="Identificar el crédito"
            description={
                <>
                    Crédito del {date(credito.date)} por {money(credito.amount)}
                    . Entra al libro de Multas con su recibo; el movimiento del
                    banco es el mismo.
                </>
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Identificar y emitir recibo"
        >
            <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">
                    ¿Alguien lo informó?
                </legend>
                {fila.reported.map((informado) => (
                    <Opcion
                        key={informado.payment.id}
                        valor={String(informado.payment.id)}
                        elegido={camino}
                        onElegir={setCamino}
                    >
                        <DescripcionDeInformado informado={informado} />
                    </Opcion>
                ))}
                <Opcion valor={NUEVO} elegido={camino} onElegir={setCamino}>
                    Nadie lo informó: buscar la multa
                </Opcion>
            </fieldset>

            {camino === NUEVO && (
                <>
                    <BuscadorDeMulta
                        elegida={multa}
                        onElegir={(elegida) => {
                            setMulta(elegida);
                            setFaltaMulta(false);
                        }}
                    />
                    <FieldError
                        message={
                            faltaMulta
                                ? 'Elegí la multa a la que corresponde el crédito.'
                                : errores.fine
                        }
                    />
                    <div>
                        <Label htmlFor="identificar-medio">Medio</Label>
                        <Select
                            value={data.medium}
                            onValueChange={(v) => setData('medium', v as Medio)}
                        >
                            <SelectTrigger
                                id="identificar-medio"
                                className="mt-1"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MEDIOS_POR_BANCO.map((medio) => (
                                    <SelectItem key={medio} value={medio}>
                                        {PAYMENT_MEDIUM[medio]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <FieldError message={errores.medium} />
                    </div>
                </>
            )}

            <ReceiptFields
                idPrefix="identificar"
                talonarioNumber={data.talonarioNumber}
                onTalonarioChange={(v) => setData('talonarioNumber', v)}
                signedById={data.signedById}
                onSignedByChange={(v) => setData('signedById', v)}
                firmantes={firmantes}
                errors={errors}
            />
            <FieldError message={errores.bankTransactionId} />
            <FieldError message={errores.payment} />
        </FormDialog>
    );
}

function Opcion({
    valor,
    elegido,
    onElegir,
    children,
}: {
    valor: string;
    elegido: string;
    onElegir: (valor: string) => void;
    children: React.ReactNode;
}) {
    return (
        <label className="flex cursor-pointer items-start gap-2 rounded-md border px-3 py-2 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5">
            <input
                type="radio"
                name="identificar-camino"
                className="mt-1"
                checked={elegido === valor}
                onChange={() => onElegir(valor)}
            />
            <span className="min-w-0">{children}</span>
        </label>
    );
}

function DescripcionDeInformado({ informado }: { informado: Informado }) {
    const pago = informado.payment;

    return (
        <>
            <span className="font-medium">
                Expte. {informado.caseLabel} · {informado.fineCaption}
            </span>
            <span className="block text-xs text-muted-foreground">
                Informado como {PAYMENT_MEDIUM[pago.medium].toLowerCase()}
                {pago.presentedAt &&
                    `, presentado el ${
                        pago.presentedPrecision === 'instant'
                            ? dateTime(pago.presentedAt)
                            : date(pago.presentedAt)
                    }`}
                {pago.createdByName && ` por ${pago.createdByName}`}. Se
                verifica conservando su presentación.
            </span>
        </>
    );
}

/** La multa del crédito que nadie informó: expediente, resolución, acta o carátula. */
function BuscadorDeMulta({
    elegida,
    onElegir,
}: {
    elegida: MultaHallada | null;
    onElegir: (multa: MultaHallada | null) => void;
}) {
    const [termino, setTermino] = useState('');
    const [halladas, setHalladas] = useState<MultaHallada[]>([]);

    useEffect(() => {
        if (termino.trim().length < 2) {
            return;
        }

        let vigente = true;
        const espera = setTimeout(() => {
            fetch(buscarMultas({ query: { q: termino } }).url, {
                headers: { Accept: 'application/json' },
            })
                .then((r) => (r.ok ? r.json() : []))
                .then((d: MultaHallada[]) => vigente && setHalladas(d))
                .catch(() => vigente && setHalladas([]));
        }, 250);

        return () => {
            vigente = false;
            clearTimeout(espera);
        };
    }, [termino]);

    return (
        <div className="grid gap-2">
            <Label htmlFor="identificar-buscar">La multa</Label>
            {elegida ? (
                <div className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm">
                    <span className="min-w-0 truncate">
                        Expte. {elegida.caseLabel} · {elegida.caption}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => onElegir(null)}
                    >
                        Cambiar
                    </Button>
                </div>
            ) : (
                <>
                    <div className="relative">
                        <Search
                            className="absolute top-2.5 left-2.5 size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            id="identificar-buscar"
                            className="pl-8"
                            placeholder="Expediente, resolución, acta o carátula"
                            value={termino}
                            onChange={(e) => setTermino(e.target.value)}
                        />
                    </div>
                    {termino.trim().length >= 2 && (
                        <ul className="max-h-48 divide-y overflow-y-auto rounded-md border">
                            {halladas.length === 0 ? (
                                <li className="px-3 py-2 text-xs text-muted-foreground">
                                    Ninguna multa coincide.
                                </li>
                            ) : (
                                halladas.map((hallada) => (
                                    <li key={hallada.id}>
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted"
                                            onClick={() => onElegir(hallada)}
                                        >
                                            <span className="min-w-0 truncate">
                                                Expte. {hallada.caseLabel} ·{' '}
                                                {hallada.caption}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                debe{' '}
                                                <Money value={hallada.debt} />
                                            </span>
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>
                    )}
                </>
            )}
        </div>
    );
}
