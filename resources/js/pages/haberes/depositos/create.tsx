import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, TriangleAlert } from 'lucide-react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
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
import { Textarea } from '@/components/ui/textarea';
import TicketPhotoPanel, {
    useTicketPhoto,
} from '@/features/haberes/components/ticket-photo-panel';
import { businessToday, money } from '@/lib/format';
import { show as haberShow } from '@/routes/haberes/haber';
import { ticket as registrar } from '@/routes/haberes/installments';

type Props = {
    cuota: {
        id: number;
        number: number;
        /** Lo que el expediente le reconoce: es lo que se deposita. */
        amount: string;
        concept: string | null;
        medium: string;
        mediumLabel: string;
        /** El tipo que le corresponde por su medio, ya resuelto. */
        depositKind: string;
    };
    haber: { number: number; expedienteId: number; beneficiaryName: string };
    expediente: { displayNumber: string; employerName: string | null };
    accounts: { id: number; label: string; currency: string }[];
};

/**
 * Carga del comprobante que llegó con el expediente.
 *
 * La foto va **al lado del formulario**, no debajo ni detrás de un clic.
 * El operador está copiando datos de un papel a mano y cambiar de ventana
 * en cada campo es donde se pierde el tiempo y donde se cuela el dígito
 * equivocado.
 *
 * Es la misma forma que «Depositar el efectivo en el banco», y por la
 * misma razón: **a quién apunta el depósito y por cuánto no se eligen**.
 * Se entra desde una cuota, así que el beneficiario, el importe y el tipo
 * los dice ella; lo que queda del formulario es lo que dice el papel.
 *
 * El comprobante no financia nada todavía: queda esperando a que el
 * crédito aparezca en el extracto. Eso es lo que dice §2.1.13 del DER, y
 * la pantalla lo aclara para que nadie espere un recibo acá.
 */
export default function DepositoCreate({
    cuota,
    haber,
    expediente,
    accounts,
}: Props) {
    const {
        foto,
        vistaPrevia,
        inputRef: inputFoto,
        elegir: elegirArchivo,
    } = useTicketPhoto();

    const form = useForm({
        bankAccountId: accounts[0]?.id.toString() ?? '',
        depositedAt: '',
        depositedTime: '',
        operationNumber: '',
        terminal: '',
        notes: '',
        photo: null as File | null,
    });

    /*
     * Si lo que se está por cargar no corresponde a esta cuota.
     *
     * Un comprobante bancario es el papel que trae el empleador cuando
     * deposita él. Una cuota que se cobra en efectivo o en cheque entra por
     * mostrador, y el depósito que la Secretaría hace después con ese
     * efectivo es otro acto, con su propia pantalla.
     *
     * Se avisa y se deja seguir: el medio previsto es una expectativa que
     * se edita, y el empleador puede haber depositado igual. Bloquearlo
     * dejaría al área sin poder registrar algo que pasó de verdad.
     */
    const porMostrador = cuota.medium === 'cash' || cuota.medium === 'cheque';

    /*
     * La cuenta es el único dato del papel que se elige de una lista: a
     * cuál de las cuentas de la Secretaría entró la plata. Se bloquea
     * cuando no hay nada que elegir, no por rol —con dos cuentas activas,
     * fijarla dejaría sin registrar el depósito que entró en la otra y el
     * cruce contra el extracto no encontraría nada—.
     */
    const cuentaUnica = accounts.length <= 1;

    // El comprobante es de algo que ya pasó: el calendario no ofrece el
    // futuro en vez de dejar que lo rechace el servidor.
    const hoy = businessToday();

    const elegirFoto = (file: File | null) => {
        elegirArchivo(file);
        form.setData('photo', file);
    };

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(registrar(cuota.id).url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Registrar comprobante de depósito" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Registrar comprobante de depósito"
                    description={
                        expediente.employerName
                            ? `Depósito informado por ${expediente.employerName} en el expediente ${expediente.displayNumber}.`
                            : `Depósito informado en el expediente ${expediente.displayNumber}.`
                    }
                />

                {porMostrador && (
                    <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning/40 bg-warning/5 p-4">
                        <div className="flex gap-2.5">
                            <TriangleAlert
                                className="mt-0.5 size-4 shrink-0 text-warning-strong"
                                aria-hidden="true"
                            />
                            <div className="space-y-1 text-sm">
                                <p className="font-medium">
                                    La cuota {cuota.number} de{' '}
                                    {haber.beneficiaryName} se cobra por
                                    mostrador.
                                </p>
                                <p className="text-muted-foreground">
                                    Un comprobante bancario es el papel que trae
                                    el empleador cuando deposita él. Si lo que
                                    pasó es que la Secretaría depositó el
                                    efectivo que tenía en caja, ese es otro
                                    acto: se registra con «Depositar en el
                                    banco», desde la cuota.
                                </p>
                                <p className="text-muted-foreground">
                                    Si el empleador depositó igual, seguí: el
                                    medio previsto es una expectativa, no un
                                    hecho.
                                </p>
                            </div>
                        </div>

                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={haberShow([
                                    haber.expedienteId,
                                    haber.number,
                                ])}
                            >
                                Ir al haber
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <form
                        onSubmit={enviar}
                        className="space-y-5 rounded-lg border bg-card p-5 shadow-raised"
                    >
                        {/*
                         * A quién apunta el depósito y por cuánto no son
                         * una elección: la pantalla se abrió desde esa
                         * cuota. Que el formulario pueda contradecir a la
                         * dirección no es libertad, es una forma de
                         * equivocarse.
                         */}
                        <dl className="grid gap-3 rounded-lg bg-muted/40 p-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-field-label">
                                    Importe depositado
                                </dt>
                                <dd className="font-mono text-lg font-semibold tabular-nums">
                                    {money(cuota.amount)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Beneficiario
                                </dt>
                                <dd className="text-sm">
                                    {haber.beneficiaryName}
                                    <span className="block text-xs text-muted-foreground">
                                        Cuota {cuota.number}
                                        {cuota.concept !== null &&
                                            ` · ${cuota.concept}`}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Tipo
                                </dt>
                                <dd className="text-sm">
                                    {cuota.depositKind}
                                    <span className="block text-xs text-muted-foreground">
                                        Medio: {cuota.mediumLabel}
                                    </span>
                                </dd>
                            </div>
                        </dl>

                        <p className="text-xs text-muted-foreground">
                            Salen de la cuota: el importe es el que el
                            expediente le reconoce y el tipo, el que corresponde
                            a su medio. Si el papel dice otra cosa —unos pesos
                            de diferencia, o «Transferencia»—, se corrige
                            después desde el comprobante, que es donde se
                            arregla todo lo que se transcribió.
                        </p>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="depositedAt">
                                    Fecha del depósito
                                </Label>
                                <Input
                                    id="depositedAt"
                                    type="date"
                                    max={hoy}
                                    autoFocus
                                    value={form.data.depositedAt}
                                    onChange={(e) =>
                                        form.setData(
                                            'depositedAt',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.depositedAt} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="depositedTime">
                                    Hora
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional
                                    </span>
                                </Label>
                                <Input
                                    id="depositedTime"
                                    type="time"
                                    value={form.data.depositedTime}
                                    onChange={(e) =>
                                        form.setData(
                                            'depositedTime',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.depositedTime}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="operationNumber">
                                    N.º de operación
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional
                                    </span>
                                </Label>
                                <Input
                                    id="operationNumber"
                                    inputMode="numeric"
                                    placeholder="1053565801"
                                    className="font-mono tabular-nums"
                                    value={form.data.operationNumber}
                                    onChange={(e) =>
                                        form.setData(
                                            'operationNumber',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.operationNumber}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="terminal">
                                    Terminal
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional
                                    </span>
                                </Label>
                                <Input
                                    id="terminal"
                                    value={form.data.terminal}
                                    onChange={(e) =>
                                        form.setData('terminal', e.target.value)
                                    }
                                    placeholder="T-014"
                                />
                                <InputError message={form.errors.terminal} />
                            </div>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            La fecha es lo que después encuentra el movimiento
                            en el extracto. El resto se guarda como evidencia de
                            lo que dice el papel.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="bankAccountId">
                                Cuenta destino
                            </Label>
                            <Select
                                value={form.data.bankAccountId}
                                onValueChange={(v) =>
                                    form.setData('bankAccountId', v)
                                }
                                disabled={cuentaUnica}
                            >
                                <SelectTrigger id="bankAccountId">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {accounts.map((cuenta) => (
                                        <SelectItem
                                            key={cuenta.id}
                                            value={cuenta.id.toString()}
                                        >
                                            {cuenta.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {cuentaUnica ? (
                                <p className="text-xs text-muted-foreground">
                                    Es la única cuenta activa.
                                </p>
                            ) : (
                                <InputError
                                    message={form.errors.bankAccountId}
                                />
                            )}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">
                                Observaciones
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    opcional
                                </span>
                            </Label>
                            <Textarea
                                id="notes"
                                rows={2}
                                maxLength={1000}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                            <InputError message={form.errors.notes} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Button type="submit" disabled={form.processing}>
                                Registrar y buscar en el banco
                                <ArrowRight className="size-4" />
                            </Button>
                            {foto === null && (
                                <span className="text-xs text-warning-strong">
                                    Falta el comprobante
                                </span>
                            )}
                        </div>
                    </form>

                    <TicketPhotoPanel
                        foto={foto}
                        vistaPrevia={vistaPrevia}
                        inputRef={inputFoto}
                        onElegir={elegirFoto}
                        error={form.errors.photo}
                    />
                </div>
            </div>
        </>
    );
}
