import { router, useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    Ban,
    CircleAlert,
    Plus,
    ShieldAlert,
    Trash2,
    TriangleAlert,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cbu as formatearCbu, date } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    deactivate as darDeBajaCuenta,
    force as forzarCuenta,
    reject as rechazarCuenta,
    store as agregarCuenta,
    verify as verificarCuenta,
} from '@/routes/personas/cuentas';
import DocumentoTag from './documento-tag';
import type { EnQuePapel } from './documento-tag';

type Estado = App.Modules.Haberes.Data.InstallmentOrderStateData;
type Contexto = App.Modules.Haberes.Data.PaymentOrderContextData;
type Cuenta = App.Modules.Haberes.Data.VerifiableAccountData;

/*
 * Las piezas del formulario de la Orden.
 *
 * Viven aparte de la pantalla que las usa porque son bastante más largas
 * que ella: las cuentas del beneficiario con su verificación, el cuadro de
 * depósitos y los bloques rotulados por documento. Dejarlas en el mismo
 * archivo hacía que la forma de la pantalla —qué bloque va antes de cuál—
 * quedara enterrada bajo mil líneas de detalle.
 */

/**
 * Las cuentas del beneficiario, con lo que hay que decidir sobre cada una.
 *
 * Se listan todas —también las rechazadas— porque el rechazo es
 * información: explica por qué a esa persona se le pidió otro CBU.
 */
export function CuentasDelBeneficiario({
    contexto,
    cuentas,
    elegida,
    onElegir,
    puedeVerificar,
    puedeForzar = false,
}: {
    contexto: Contexto;
    cuentas: Cuenta[];
    elegida: number | null;
    onElegir: (id: number) => void;
    puedeVerificar: boolean;
    /** Sólo `super-admin`. Ni el administrador lo recibe. */
    puedeForzar?: boolean;
}) {
    const [agregando, setAgregando] = useState(false);
    const [rechazando, setRechazando] = useState<number | null>(null);
    const [dandoDeBaja, setDandoDeBaja] = useState<number | null>(null);
    const [forzando, setForzando] = useState<number | null>(null);

    const nueva = useForm({ cbu: '', bankName: '', accountNumber: '' });
    const rechazo = useForm({ reason: '' });
    const forzado = useForm({ reason: '' });

    return (
        <div className="space-y-2">
            {cuentas.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    El beneficiario no tiene ninguna cuenta cargada.
                </p>
            )}

            {cuentas.map((cuenta) => (
                <div
                    key={cuenta.id}
                    className={cn(
                        'rounded-lg border p-3 text-sm',
                        cuenta.verificationStatus === 'verified' &&
                            elegida === cuenta.id &&
                            'border-primary bg-primary/5',
                        cuenta.verificationStatus === 'rejected' &&
                            'border-dashed bg-muted/30',
                    )}
                >
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                        {cuenta.verificationStatus === 'verified' && (
                            <input
                                type="radio"
                                name="cuenta-beneficiario"
                                checked={elegida === cuenta.id}
                                onChange={() => onElegir(cuenta.id)}
                                aria-label={`Usar el CBU ${cuenta.cbu}`}
                            />
                        )}
                        <span className="font-mono text-xs">
                            {formatearCbu(cuenta.cbu)}
                        </span>
                        <EstadoDeCuenta cuenta={cuenta} />
                        {cuenta.bankName && (
                            <span className="text-xs text-muted-foreground">
                                {cuenta.bankName}
                            </span>
                        )}
                    </div>

                    {cuenta.rejectionReason && (
                        <p className="mt-1 text-xs text-muted-foreground italic">
                            {cuenta.rejectionReason}
                        </p>
                    )}

                    {/*
                     * La marca de forzado va arriba de todo lo demás y en
                     * rojo: una cuenta forzada llega a la Orden igual que
                     * cualquier otra, y si se pareciera a una cotejada de
                     * verdad el control entero sería decorativo.
                     */}
                    {cuenta.forcedReason && (
                        <p className="mt-1 flex items-start gap-1.5 rounded-md border border-destructive/40 bg-destructive/5 px-2 py-1 text-xs text-destructive">
                            <ShieldAlert
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                <strong>Verificación forzada.</strong> Este CBU
                                no se cotejó contra ninguna foja:{' '}
                                {cuenta.forcedBypass === 'virtual_wallet'
                                    ? 'se salteó el control de billetera virtual'
                                    : cuenta.forcedBypass === 'checksum'
                                      ? 'se salteó el dígito verificador del BCRA'
                                      : 'se saltearon el dígito verificador y el control de billetera'}
                                . {cuenta.forcedReason}
                            </span>
                        </p>
                    )}

                    {/*
                     * Los dos avisos son independientes y no dicen lo
                     * mismo. Un CVU está bien escrito y pasa el
                     * verificador: lo que lo inhabilita es quién lo emite.
                     * Mezclarlos mandaba a corregir un número que no tiene
                     * nada que corregir.
                     */}
                    {cuenta.isVirtualWallet && (
                        <p className="mt-1 flex items-start gap-1.5 text-xs text-warning-strong">
                            <Wallet
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Es un CVU de billetera virtual, no un CBU bancario
                            —lo dice la entidad {cuenta.entityCode}—. El
                            organismo no transfiere a billeteras: corresponde
                            rechazarlo y pedir un CBU de banco.
                        </p>
                    )}

                    {!cuenta.checksumValid && !cuenta.isVirtualWallet && (
                        <p className="mt-1 flex items-start gap-1.5 text-xs text-warning-strong">
                            <CircleAlert
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Estos 22 dígitos no pasan el verificador del BCRA,
                            que es el mismo para todos los bancos. O hay un
                            error de tipeo, o el número no corresponde a ninguna
                            cuenta: conviene cotejarlo contra la foja del
                            expediente.
                        </p>
                    )}

                    {puedeVerificar &&
                        cuenta.verificationStatus !== 'rejected' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {/*
                                 * Con un CVU no se ofrece verificar: no es
                                 * que falte mirarlo, es que no sirve. El
                                 * único camino es rechazarlo y pedir otro,
                                 * y un botón que va a fallar solo hace
                                 * perder el viaje.
                                 */}
                                {cuenta.verificationStatus !== 'verified' &&
                                    !cuenta.isVirtualWallet && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            className="min-h-11 text-xs sm:min-h-7"
                                            onClick={() =>
                                                router.post(
                                                    verificarCuenta(cuenta.id)
                                                        .url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <BadgeCheck className="size-3.5" />
                                            Verificar
                                        </Button>
                                    )}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="min-h-11 text-xs sm:min-h-7"
                                    onClick={() =>
                                        setRechazando(
                                            rechazando === cuenta.id
                                                ? null
                                                : cuenta.id,
                                        )
                                    }
                                >
                                    <Ban className="size-3.5" />
                                    Rechazar
                                </Button>
                                {/*
                                 * Dar de baja no es rechazar. El rechazo
                                 * explica por qué un número no sirve y se
                                 * sigue viendo; la baja es para el CBU que
                                 * nunca debió estar acá —mal tipeado, de
                                 * otra persona— y lo saca de la lista.
                                 */}
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="min-h-11 text-xs text-muted-foreground sm:min-h-7"
                                    onClick={() =>
                                        setDandoDeBaja(
                                            dandoDeBaja === cuenta.id
                                                ? null
                                                : cuenta.id,
                                        )
                                    }
                                >
                                    <Trash2 className="size-3.5" />
                                    Dar de baja
                                </Button>
                                {/*
                                 * Sólo `super-admin`, y sólo sobre un
                                 * número que de verdad no pasa: si pasa,
                                 * el Action lo manda al botón normal. Un
                                 * atajo con motivo obligatorio que sirva
                                 * también para el uso corriente termina
                                 * marcando todas las cuentas como
                                 * forzadas.
                                 */}
                                {puedeForzar &&
                                    cuenta.verificationStatus !== 'verified' &&
                                    (!cuenta.checksumValid ||
                                        cuenta.isVirtualWallet) && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            className="min-h-11 text-xs text-destructive sm:min-h-7"
                                            onClick={() =>
                                                setForzando(
                                                    forzando === cuenta.id
                                                        ? null
                                                        : cuenta.id,
                                                )
                                            }
                                        >
                                            <ShieldAlert className="size-3.5" />
                                            Forzar
                                        </Button>
                                    )}
                            </div>
                        )}

                    {forzando === cuenta.id && (
                        <div className="mt-2 space-y-2 rounded-md border border-destructive/40 bg-destructive/5 p-2">
                            <p className="text-xs text-destructive">
                                <strong>Esto no arregla el número.</strong>{' '}
                                {cuenta.isVirtualWallet
                                    ? 'Es un CVU real, así que la transferencia saldría: lo que se saltea es la decisión del organismo de no pagar a billeteras.'
                                    : 'Si no pasa el dígito del BCRA, ningún banco va a aceptar la transferencia. Sirve para probar el circuito, no para pagar.'}{' '}
                                La cuenta queda marcada para siempre.
                            </p>
                            <Textarea
                                rows={2}
                                value={forzado.data.reason}
                                onChange={(e) =>
                                    forzado.setData('reason', e.target.value)
                                }
                                placeholder="Prueba del circuito de emisión con datos inventados."
                            />
                            <InputError message={forzado.errors.reason} />
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                className="min-h-11 text-xs sm:min-h-7"
                                disabled={forzado.processing}
                                onClick={() =>
                                    forzado.post(forzarCuenta(cuenta.id).url, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            forzado.reset();
                                            setForzando(null);
                                        },
                                    })
                                }
                            >
                                Forzar de todos modos
                            </Button>
                        </div>
                    )}

                    {/*
                     * Con confirmación, y no de un clic: la cuenta dada de
                     * baja desaparece de este listado, así que un dedo
                     * resbalado no deja forma de volver desde la pantalla.
                     */}
                    {dandoDeBaja === cuenta.id && (
                        <div className="mt-2 space-y-2 rounded-md border border-dashed p-2">
                            <p className="text-xs text-muted-foreground">
                                La cuenta deja de ofrecerse y sale de esta
                                lista. Si lo que querés es dejar asentado por
                                qué el número no sirve, usá «Rechazar».
                            </p>
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                className="min-h-11 text-xs sm:min-h-7"
                                onClick={() =>
                                    router.post(
                                        darDeBajaCuenta(cuenta.id).url,
                                        {},
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setDandoDeBaja(null),
                                        },
                                    )
                                }
                            >
                                Confirmar la baja
                            </Button>
                        </div>
                    )}

                    {rechazando === cuenta.id && (
                        <div className="mt-2 space-y-2">
                            <Textarea
                                rows={2}
                                value={rechazo.data.reason}
                                onChange={(e) =>
                                    rechazo.setData('reason', e.target.value)
                                }
                                placeholder="Es un CVU de billetera virtual; el organismo no transfiere a billeteras."
                            />
                            <InputError message={rechazo.errors.reason} />
                            <Button
                                type="button"
                                size="sm"
                                variant="destructive"
                                className="min-h-11 text-xs sm:min-h-7"
                                disabled={rechazo.processing}
                                onClick={() =>
                                    rechazo.post(
                                        rechazarCuenta(cuenta.id).url,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                setRechazando(null),
                                        },
                                    )
                                }
                            >
                                Confirmar el rechazo
                            </Button>
                        </div>
                    )}

                    {cuenta.verifiedAt && (
                        <p className="mt-1 text-xs text-muted-foreground">
                            {cuenta.verificationStatus === 'verified'
                                ? 'Verificada'
                                : 'Rechazada'}{' '}
                            el {date(cuenta.verifiedAt)}
                        </p>
                    )}
                </div>
            ))}

            {puedeVerificar &&
                (agregando ? (
                    <div className="space-y-2 rounded-lg border border-dashed p-3">
                        <div className="grid gap-2 sm:grid-cols-2">
                            <div>
                                <Label
                                    htmlFor="cbu-nuevo"
                                    className="text-xs font-normal"
                                >
                                    CBU · 22 dígitos
                                </Label>
                                <Input
                                    id="cbu-nuevo"
                                    inputMode="numeric"
                                    value={nueva.data.cbu}
                                    onChange={(e) =>
                                        nueva.setData(
                                            'cbu',
                                            e.target.value.replace(/\D/g, ''),
                                        )
                                    }
                                    className="mt-1 font-mono"
                                />
                                <InputError message={nueva.errors.cbu} />
                            </div>
                            <div>
                                <Label
                                    htmlFor="banco-nuevo"
                                    className="text-xs font-normal"
                                >
                                    Banco
                                </Label>
                                <Input
                                    id="banco-nuevo"
                                    value={nueva.data.bankName}
                                    onChange={(e) =>
                                        nueva.setData(
                                            'bankName',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1"
                                />
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                size="sm"
                                className="min-h-11 text-xs sm:min-h-7"
                                disabled={nueva.processing}
                                onClick={() =>
                                    nueva.post(
                                        agregarCuenta(contexto.beneficiaryId)
                                            .url,
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                nueva.reset();
                                                setAgregando(false);
                                            },
                                        },
                                    )
                                }
                            >
                                Cargar el CBU
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="min-h-11 text-xs sm:min-h-7"
                                onClick={() => setAgregando(false)}
                            >
                                Cancelar
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="min-h-11 text-xs sm:min-h-7"
                        onClick={() => setAgregando(true)}
                    >
                        <Plus className="size-3.5" />
                        Cargar otro CBU
                    </Button>
                ))}
        </div>
    );
}

export function EstadoDeCuenta({ cuenta }: { cuenta: Cuenta }) {
    const estilo = {
        verified: 'bg-success-soft text-success-strong',
        unverified: 'bg-warning-soft text-warning-strong',
        rejected: 'bg-muted text-muted-foreground',
    }[cuenta.verificationStatus];

    const texto = {
        verified: 'Verificada',
        unverified: 'Sin verificar',
        rejected: 'Rechazada',
    }[cuenta.verificationStatus];

    return (
        <span className={cn('rounded px-1.5 py-0.5 text-[0.65rem]', estilo)}>
            {texto}
        </span>
    );
}

/** El cuadro que el formulario imprime, tal como va a quedar congelado. */
export function TablaDeDepositos({ estado }: { estado: Estado }) {
    if (estado.deposits.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                Ningún ingreso de esta cuota llegó a una cuenta del organismo.
            </p>
        );
    }

    return (
        <div className="space-y-2">
            {estado.organismAccountLabel && (
                <p className="text-xs text-muted-foreground">
                    El papel va a marcar{' '}
                    <strong className="font-medium text-foreground">
                        {estado.organismAccountLabel}
                    </strong>
                    .
                </p>
            )}

            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-xs">
                    <thead className="bg-muted/40 text-field-label">
                        <tr>
                            <th className="px-2 py-1.5 text-left font-normal">
                                Depósito u operación N°
                            </th>
                            <th className="px-2 py-1.5 text-left font-normal">
                                Fecha
                            </th>
                            <th className="px-2 py-1.5 text-left font-normal">
                                Cta. cte.
                            </th>
                            <th className="px-2 py-1.5 text-right font-normal">
                                Importe
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {estado.deposits.map((fila, i) => (
                            <tr key={i} className="border-t">
                                <td className="px-2 py-1.5 font-mono">
                                    {fila.operationNumber ?? '—'}
                                </td>
                                <td className="px-2 py-1.5">
                                    {fila.operationDate
                                        ? date(fila.operationDate)
                                        : '—'}
                                </td>
                                <td className="px-2 py-1.5 font-mono">
                                    {fila.bankAccountNumber ?? '—'}
                                </td>
                                <td className="px-2 py-1.5 text-right">
                                    <Money value={fila.amount} />
                                </td>
                            </tr>
                        ))}
                        <tr className="border-t bg-muted/20 font-semibold">
                            <td className="px-2 py-1.5" colSpan={3}>
                                Total
                            </td>
                            <td className="px-2 py-1.5 text-right">
                                <Money value={estado.depositsTotal} />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    );
}

/** Un bloque del modal, con lo que le falta señalado arriba. */
export function Seccion({
    id,
    titulo,
    papel,
    descripcion,
    faltan,
    children,
}: {
    id?: string;
    titulo: string;
    /** En qué documento se imprime lo que este bloque carga. */
    papel: EnQuePapel;
    descripcion?: string;
    faltan?: Estado['missing'];
    children: React.ReactNode;
}) {
    const obligatorios = (faltan ?? []).filter((campo) => campo.required);
    const sugeridos = (faltan ?? []).filter((campo) => !campo.required);

    return (
        <section
            id={id}
            className={cn(
                'scroll-mt-6 rounded-lg border p-4',
                obligatorios.length > 0 && 'border-warning bg-warning-soft/30',
            )}
        >
            <h3 className="flex flex-wrap items-center gap-2 text-xs font-semibold tracking-wide text-field-label uppercase">
                {titulo}
                <DocumentoTag papel={papel} />
            </h3>
            {descripcion && (
                <p className="mt-0.5 mb-3 text-xs text-muted-foreground">
                    {descripcion}
                </p>
            )}

            {obligatorios.length > 0 && (
                <ul className="mb-3 space-y-1">
                    {obligatorios.map((campo) => (
                        <li
                            key={campo.code}
                            className="flex items-start gap-1.5 text-xs text-warning-strong"
                        >
                            <TriangleAlert
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            <span>
                                <strong className="font-medium">
                                    Falta {campo.label.toLowerCase()}.
                                </strong>{' '}
                                {campo.reason}
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {sugeridos.length > 0 && (
                <p className="mb-3 text-xs text-muted-foreground">
                    Sin cargar:{' '}
                    {sugeridos
                        .map((campo) => campo.label.toLowerCase())
                        .join(', ')}
                    . El formulario los deja en blanco y no frena nada.
                </p>
            )}

            {children}
        </section>
    );
}

export function Campo({
    id,
    etiqueta,
    valor,
    onChange,
    error,
    placeholder,
    className,
}: {
    id: string;
    etiqueta: string;
    valor: string;
    onChange: (valor: string) => void;
    error?: string;
    placeholder?: string;
    className?: string;
}) {
    return (
        <div className={className}>
            <Label htmlFor={id} className="text-xs font-normal">
                {etiqueta}
            </Label>
            <Input
                id={id}
                value={valor}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className="mt-1"
            />
            <InputError message={error} />
        </div>
    );
}

export function Opcion({
    activa,
    onClick,
    etiqueta,
    ayuda,
    deshabilitada = false,
}: {
    activa: boolean;
    onClick: () => void;
    etiqueta: string;
    ayuda: string;
    deshabilitada?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={deshabilitada}
            aria-pressed={activa}
            className={cn(
                'min-h-11 flex-1 rounded-md border px-3 py-2 text-left transition-colors',
                activa
                    ? 'border-primary bg-primary/5'
                    : 'hover:bg-muted disabled:opacity-40 disabled:hover:bg-transparent',
            )}
        >
            <span className="block text-xs font-semibold">{etiqueta}</span>
            <span className="block text-xs text-muted-foreground">{ayuda}</span>
        </button>
    );
}

/**
 * Qué van a decir los dos papeles con la foja que se escribió.
 *
 * **Existe porque un solo campo alimenta dos renglones que no se parecen.**
 * La Orden imprime «Se observa en expte. CBU del trabajador a fs. 19» y la
 * nota pide transferir «a la CBU informada en fs. 19»: el mismo 19, dicho
 * de dos maneras distintas. Cuando eran dos campos de texto libre, el
 * operador veía lo que escribía; ahora que el sistema los redacta, no
 * mostrarlos sería pedirle que firme a ciegas.
 *
 * Sin foja no dice qué saldría impreso, porque no va a salir nada: la foja
 * es obligatoria y el formulario no deja emitir sin ella. Dice, en cambio,
 * qué renglones quedan esperándola, que es lo que explica por qué se
 * exige.
 */
export function FojaEnLosPapeles({ foja }: { foja: string }) {
    const limpia = foja.trim();

    return (
        <dl className="mt-3 space-y-2 rounded-lg border bg-muted/30 px-3 py-2 text-xs">
            <div>
                <dt className="text-field-label">En la Orden, campo OBS:</dt>
                <dd className="text-muted-foreground italic">
                    {limpia === ''
                        ? 'Espera la foja.'
                        : `Se observa en expte. CBU del trabajador a fs. n.º ${limpia}`}
                </dd>
            </div>
            <div>
                <dt className="text-field-label">En la nota de Pase:</dt>
                <dd className="text-muted-foreground italic">
                    {limpia === ''
                        ? 'Espera la foja para citarla.'
                        : `…a la CBU informada en fs. ${limpia} del Expte. de referencia.`}
                </dd>
            </div>
        </dl>
    );
}
