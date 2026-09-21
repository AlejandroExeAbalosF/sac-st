import { Head, Link, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    CopyCheck,
    FileSpreadsheet,
    FileUp,
    Link2,
    RotateCcw,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import AlertError from '@/components/alert-error';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { create, preview, show, store } from '@/routes/banco/extractos';

type Cuenta = App.Modules.Banking.Data.BankAccountData;
type Preview = App.Modules.Banking.Data.StatementPreviewData;
type Fila = App.Modules.Banking.Data.StatementPreviewRowData;
type Resultado = App.Modules.Banking.Data.StatementImportListItemData;

type Props = {
    accounts: Cuenta[];
    /* Presentes solo cuando el paso anterior fue analizar un archivo. */
    preview?: Preview;
    previewFilename?: string;
    previewAccountId?: number;
    /* Presente solo después de importar. */
    result?: Resultado;
};

const ETIQUETA_FILA = {
    valid: 'Interpretada',
    warning: 'Sin saldo',
    rejected: 'Rechazada',
} as const;

const TONO_FILA = {
    valid: 'done',
    warning: 'action',
    rejected: 'blocked',
} as const;

/**
 * Importación de un extracto, en tres pasos.
 *
 * Los pasos no son decoración. Mientras el formulario y la vista previa
 * convivían en la misma pantalla, se podía analizar un archivo contra una
 * cuenta, cambiar el selector, e importar contra otra: la vista previa que
 * autorizaba la operación ya no correspondía a lo que se estaba
 * importando. Cerrar el paso 1 lo vuelve imposible, y volver atrás
 * descarta la lectura, que es exactamente lo correcto porque dejó de ser
 * válida.
 */
export default function ExtractoCreate({
    accounts,
    preview: vistaPrevia,
    previewFilename,
    previewAccountId,
    result,
}: Props) {
    const [archivo, setArchivo] = useState<File | null>(null);
    const inputArchivo = useRef<HTMLInputElement>(null);

    const form = useForm<{ bankAccountId: string; file: File | null }>({
        bankAccountId:
            previewAccountId?.toString() ?? accounts[0]?.id.toString() ?? '',
        file: null,
    });

    const paso = result ? 3 : vistaPrevia ? 2 : 1;
    const cuenta = accounts.find(
        (c) => c.id.toString() === form.data.bankAccountId,
    );

    /*
     * `preserveUrl` mantiene la barra de direcciones en /nuevo durante
     * todo el asistente.
     *
     * Sin esto, la URL pasaba a ser la del POST —/previsualizar—, que solo
     * acepta POST: bastaba un F5 para recibir un «GET method is not
     * supported». Y aunque respondiera, no habría nada que reconstruir: el
     * archivo vive en la memoria del navegador, no en el servidor. Al
     * recargar corresponde volver al paso 1, y eso es justamente lo que
     * pasa cuando la URL nunca se movió de ahí.
     */
    const visita = { forceFormData: true, preserveUrl: true } as const;

    const analizar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(preview().url, visita);
    };

    if (accounts.length === 0) {
        return (
            <>
                <Head title="Importar extracto" />
                <div className="space-y-6 p-4 sm:p-6">
                    <PageHeader eyebrow="Banco" title="Importar extracto" />
                    <AlertError
                        title="No hay ninguna cuenta activa"
                        errors={[
                            'Un extracto se importa contra una cuenta del organismo. Registrá la cuenta antes de subir el archivo.',
                        ]}
                    />
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Importar extracto" />

            <div className="space-y-8 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco"
                    title="Importar extracto"
                    description="El archivo que se descarga de MacroOnline, en Excel o en CSV."
                />

                <Cadena
                    paso={paso}
                    resumenArchivo={
                        archivo && cuenta
                            ? `${cuenta.label} · ${formatoDe(archivo.name)}`
                            : (previewFilename ?? null)
                    }
                    resumenRevision={
                        vistaPrevia
                            ? `${vistaPrevia.newCount} nuevos · ${vistaPrevia.duplicateCount} repetidos`
                            : null
                    }
                />

                {paso === 1 && (
                    <PasoArchivo
                        accounts={accounts}
                        form={form}
                        archivo={archivo}
                        inputRef={inputArchivo}
                        onElegir={(file) => {
                            setArchivo(file);
                            form.setData('file', file);
                        }}
                        onSubmit={analizar}
                    />
                )}

                {paso === 2 && vistaPrevia && (
                    <PasoRevision
                        preview={vistaPrevia}
                        filename={previewFilename ?? 'Extracto'}
                        cuenta={cuenta}
                        archivoEnMemoria={archivo !== null}
                        importando={form.processing}
                        onImportar={() => form.post(store().url, visita)}
                    />
                )}

                {paso === 3 && result && <PasoResultado result={result} />}
            </div>
        </>
    );
}

/* ─────────────────────────────────────────────────────────────
 * La cadena
 *
 * Un extracto encadena: cada saldo depende del anterior, y que la
 * cadena cierre es el control más fuerte que el archivo permite. El
 * asistente usa esa misma figura para su propio avance —los eslabones
 * cerrados conservan su dato en lugar de un tilde, porque «14 nuevos ·
 * 0 repetidos» dice algo que un ✓ no dice—.
 * ───────────────────────────────────────────────────────────── */

function Cadena({
    paso,
    resumenArchivo,
    resumenRevision,
}: {
    paso: number;
    resumenArchivo: string | null;
    resumenRevision: string | null;
}) {
    const eslabones = [
        { n: 1, titulo: 'Archivo', dato: resumenArchivo },
        { n: 2, titulo: 'Revisión', dato: resumenRevision },
        { n: 3, titulo: 'Resultado', dato: null },
    ];

    return (
        <ol className="flex items-start">
            {eslabones.map((eslabon, i) => {
                const cerrado = paso > eslabon.n;
                const actual = paso === eslabon.n;

                return (
                    <li
                        key={eslabon.n}
                        className={cn(
                            'flex min-w-0 items-start gap-3',
                            i < eslabones.length - 1 && 'flex-1',
                        )}
                    >
                        <div className="flex shrink-0 flex-col items-center">
                            <span
                                className={cn(
                                    'flex size-7 items-center justify-center rounded-full border text-xs font-semibold transition-colors motion-reduce:transition-none',
                                    cerrado &&
                                        'border-transparent bg-primary text-primary-foreground',
                                    actual &&
                                        'border-primary text-primary ring-4 ring-primary/10',
                                    !cerrado &&
                                        !actual &&
                                        'border-border text-muted-foreground',
                                )}
                            >
                                {cerrado ? (
                                    <CheckCircle2 className="size-4" />
                                ) : (
                                    eslabon.n
                                )}
                            </span>
                        </div>

                        <div className="min-w-0 pt-0.5">
                            <p
                                className={cn(
                                    'text-sm leading-none font-medium',
                                    actual
                                        ? 'text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {eslabon.titulo}
                            </p>
                            {eslabon.dato && (
                                <p className="mt-1.5 truncate text-xs text-field-label">
                                    {eslabon.dato}
                                </p>
                            )}
                        </div>

                        {i < eslabones.length - 1 && (
                            <span
                                aria-hidden
                                className={cn(
                                    'mt-3.5 h-px min-w-6 flex-1 transition-colors motion-reduce:transition-none',
                                    cerrado ? 'bg-primary' : 'bg-border',
                                )}
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}

/* ───────────────────────────── Paso 1 ───────────────────────────── */

function PasoArchivo({
    accounts,
    form,
    archivo,
    inputRef,
    onElegir,
    onSubmit,
}: {
    accounts: Cuenta[];
    form: ReturnType<
        typeof useForm<{ bankAccountId: string; file: File | null }>
    >;
    archivo: File | null;
    inputRef: React.RefObject<HTMLInputElement | null>;
    onElegir: (file: File | null) => void;
    onSubmit: (event: React.FormEvent) => void;
}) {
    return (
        <form
            onSubmit={onSubmit}
            className="mx-auto w-full max-w-2xl animate-in space-y-5 rounded-lg border p-6 duration-300 ease-out fade-in-0 motion-reduce:animate-none"
        >
            <div className="grid gap-2">
                <Label htmlFor="bankAccountId">Cuenta</Label>
                <Select
                    value={form.data.bankAccountId}
                    onValueChange={(value) =>
                        form.setData('bankAccountId', value)
                    }
                >
                    <SelectTrigger id="bankAccountId" className="w-full">
                        <SelectValue placeholder="Elegí una cuenta" />
                    </SelectTrigger>
                    <SelectContent>
                        {accounts.map((cuenta) => (
                            <SelectItem
                                key={cuenta.id}
                                value={cuenta.id.toString()}
                            >
                                {cuenta.label} · {cuenta.currency}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={form.errors.bankAccountId} />
            </div>

            <div className="grid gap-2">
                <Label>Archivo</Label>

                <ZonaDeArchivo
                    archivo={archivo}
                    onElegir={onElegir}
                    inputRef={inputRef}
                />

                {archivo === null ? (
                    <p className="text-xs text-muted-foreground">
                        Conviene el Excel: declara la cuenta y la moneda en su
                        cabecera, y con eso el sistema verifica que el archivo
                        corresponda. El CSV no trae ese dato.
                    </p>
                ) : (
                    <AvisoDeFormato archivo={archivo} />
                )}

                <InputError message={form.errors.file} />
            </div>

            <Button
                type="submit"
                disabled={form.processing || archivo === null}
            >
                Analizar archivo
                <ArrowRight className="size-4" />
            </Button>
        </form>
    );
}

/**
 * Zona para soltar o elegir el archivo.
 *
 * El extracto llega a la carpeta de descargas y de ahí al sistema, así que
 * arrastrarlo es el gesto natural. El input sigue existiendo debajo
 * —oculto pero real— porque es lo que hace accesible el control y lo que
 * permite elegir con el teclado.
 */
function ZonaDeArchivo({
    archivo,
    onElegir,
    inputRef,
}: {
    archivo: File | null;
    onElegir: (file: File | null) => void;
    inputRef: React.RefObject<HTMLInputElement | null>;
}) {
    const [encima, setEncima] = useState(false);

    const abrirSelector = () => inputRef.current?.click();

    return (
        <div
            onDragOver={(e) => {
                e.preventDefault();
                setEncima(true);
            }}
            onDragLeave={() => setEncima(false)}
            onDrop={(e) => {
                e.preventDefault();
                setEncima(false);
                onElegir(e.dataTransfer.files?.[0] ?? null);
            }}
            className={cn(
                'rounded-lg border border-dashed transition-colors motion-reduce:transition-none',
                encima
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-primary/50',
            )}
        >
            <input
                id="file"
                ref={inputRef}
                type="file"
                accept=".csv,.txt,.xls,.xlsx,.xlsm"
                className="sr-only"
                tabIndex={-1}
                aria-hidden="true"
                onChange={(e) => onElegir(e.target.files?.[0] ?? null)}
            />

            {archivo === null ? (
                <button
                    type="button"
                    onClick={abrirSelector}
                    className="flex w-full cursor-pointer flex-col items-center gap-2 px-6 py-10 text-center"
                >
                    <FileUp
                        className={cn(
                            'size-7 transition-colors motion-reduce:transition-none',
                            encima ? 'text-primary' : 'text-muted-foreground',
                        )}
                    />
                    <span className="text-sm font-medium">
                        {encima
                            ? 'Soltá el archivo acá'
                            : 'Arrastrá el extracto o hacé clic para elegirlo'}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        Excel (.xls, .xlsx) o CSV · hasta 10 MB
                    </span>
                </button>
            ) : (
                <div className="flex items-center gap-3 p-4">
                    <FileSpreadsheet className="size-8 shrink-0 text-primary" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-medium">
                            {archivo.name}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {pesoLegible(archivo.size)}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={abrirSelector}
                    >
                        Cambiar
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Quitar el archivo"
                        onClick={() => onElegir(null)}
                    >
                        <X className="size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * Qué se pierde al usar CSV.
 *
 * No bloquea: un CSV es un extracto legítimo y la cadena de saldos —que es
 * el control más fuerte— funciona igual en los dos formatos. Lo que cambia
 * es que sin cabecera nadie puede verificar que el archivo sea de esta
 * cuenta, y eso el operador tiene que saberlo antes de confirmar.
 */
function AvisoDeFormato({ archivo }: { archivo: File }) {
    if (formatoDe(archivo.name) === 'Excel') {
        return (
            <p className="flex items-start gap-1.5 text-xs text-success-strong">
                <CheckCircle2 className="mt-0.5 size-3.5 shrink-0" />
                El Excel declara la cuenta y la moneda: el sistema va a
                verificar que el archivo corresponda a la cuenta elegida.
            </p>
        );
    }

    return (
        <p className="flex items-start gap-1.5 text-xs text-warning-strong">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
            El CSV no declara la cuenta ni la moneda, así que no se puede
            verificar que corresponda a la cuenta elegida. Si tenés el Excel,
            preferilo.
        </p>
    );
}

/* ───────────────────────────── Paso 2 ───────────────────────────── */

function PasoRevision({
    preview: p,
    filename,
    cuenta,
    archivoEnMemoria,
    importando,
    onImportar,
}: {
    preview: Preview;
    filename: string;
    cuenta?: Cuenta;
    archivoEnMemoria: boolean;
    importando: boolean;
    onImportar: () => void;
}) {
    return (
        <div className="animate-in space-y-5 duration-300 ease-out fade-in-0 slide-in-from-bottom-2 motion-reduce:animate-none">
            <div className="rounded-lg border">
                {/* Encabezado: qué archivo es y qué se puede hacer con él. */}
                <div className="flex flex-wrap items-center justify-between gap-4 border-b p-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <FileSpreadsheet className="size-5 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                            <p className="truncate font-medium">{filename}</p>
                            <p className="text-sm text-muted-foreground">
                                {p.periodFrom && p.periodTo
                                    ? `Del ${date(p.periodFrom)} al ${date(p.periodTo)}`
                                    : 'Sin período determinado'}
                                {cuenta ? ` · ${cuenta.label}` : ''}
                                {p.operatorInFile
                                    ? ` · descargado por ${p.operatorInFile}`
                                    : ''}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="ghost" asChild>
                            <Link href={create()}>
                                <RotateCcw className="size-4" />
                                Volver
                            </Link>
                        </Button>
                        {p.importable ? (
                            <Button
                                onClick={onImportar}
                                disabled={importando || !archivoEnMemoria}
                            >
                                Importar {p.newCount}{' '}
                                {p.newCount === 1
                                    ? 'movimiento'
                                    : 'movimientos'}
                                <ArrowRight className="size-4" />
                            </Button>
                        ) : (
                            <StatusBadge
                                label="No se puede importar"
                                tone="blocked"
                            />
                        )}
                    </div>
                </div>

                {!p.importable && (
                    <div className="border-b bg-destructive-soft/40 p-4">
                        <p className="mb-2 text-sm font-semibold text-destructive-strong">
                            Este archivo no se puede importar
                        </p>
                        <ul className="space-y-1 text-sm text-destructive-strong">
                            {p.problems.map((problema) => (
                                <li key={problema} className="flex gap-2">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    {problema}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {p.balanceChainOk === null && (
                    <div className="flex items-start gap-2 border-b bg-warning-soft/50 p-4 text-sm text-warning-strong">
                        <AlertTriangle
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <div>
                            <p className="font-semibold">
                                La cadena de saldos no se puede verificar
                                completa
                            </p>
                            <p className="mt-0.5">
                                Hay movimientos sin saldo posterior. Se importan
                                como registros independientes y quedan señalados
                                para revisión.
                            </p>
                        </div>
                    </div>
                )}

                {/*
                 * Ámbar y no rojo, y sin tocar el botón de importar: esto
                 * no dice que el archivo esté mal, dice que probablemente
                 * falte subir otro. El archivo que se está mirando se
                 * importa igual, y conviene que así sea.
                 */}
                {p.continuityWarning && (
                    <div className="flex items-start gap-2 border-b bg-warning-soft/50 p-4 text-sm text-warning-strong">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <div>
                            <p className="font-semibold">
                                Falta un período entre este extracto y el
                                anterior
                            </p>
                            <p className="mt-0.5">{p.continuityWarning}</p>
                        </div>
                    </div>
                )}

                {/*
                 * El saldo va primero y con más peso que el resto. Es lo
                 * que prueba que el extracto está completo: si la cadena
                 * cierra, no falta ningún movimiento. Como una métrica más
                 * entre ocho iguales, ese dato se pierde.
                 */}
                {p.balanceChainOk && p.openingBalance && p.closingBalance && (
                    <div className="flex flex-wrap items-center gap-x-6 gap-y-3 border-b bg-muted/30 p-5">
                        <Saldo
                            titulo="Saldo previo"
                            valor={p.openingBalance}
                            pie={
                                p.periodFrom
                                    ? `antes del ${date(p.periodFrom)}`
                                    : undefined
                            }
                        />
                        <div className="flex flex-1 flex-col items-center gap-1 px-2">
                            <span className="text-xs whitespace-nowrap text-field-label">
                                {p.rowsValid} movimientos
                            </span>
                            <span
                                aria-hidden
                                className="h-px w-full min-w-8 bg-success"
                            />
                            <span className="inline-flex items-center gap-1 text-xs whitespace-nowrap text-success-strong">
                                <CheckCircle2 className="size-3" />
                                la cadena cierra
                            </span>
                        </div>
                        <Saldo
                            titulo="Saldo en el banco"
                            valor={p.closingBalance}
                            pie={
                                p.periodTo
                                    ? `al ${date(p.periodTo)}`
                                    : undefined
                            }
                            destacado
                        />
                    </div>
                )}

                {/* El detalle completo de lo que se leyó del archivo. */}
                <dl className="grid grid-cols-2 gap-4 p-5 sm:grid-cols-4">
                    <Dato titulo="Movimientos nuevos" valor={p.newCount} />
                    <Dato
                        titulo="Ya registrados"
                        valor={p.duplicateCount}
                        pista="Aparecen porque las descargas se pisan. No se duplican."
                    />
                    <Dato titulo="Filas rechazadas" valor={p.rowsRejected} />
                    <Dato
                        titulo="Cadena de saldos"
                        valor={
                            p.balanceChainOk === null
                                ? 'No verificable'
                                : p.balanceChainOk
                                  ? 'Cierra'
                                  : 'No cierra'
                        }
                    />
                    <Dato
                        titulo="Saldo previo"
                        valor={money(p.openingBalance)}
                        pista="Deducido: el saldo que había antes del primer movimiento."
                    />
                    <Dato
                        titulo="Saldo en el banco"
                        valor={money(p.closingBalance)}
                        pista="El último que informa el archivo."
                    />
                    <Dato
                        titulo="Cuenta en el archivo"
                        valor={p.accountNumberInFile ?? 'No informada'}
                        pista={
                            p.accountNumberInFile
                                ? undefined
                                : 'El CSV no la declara.'
                        }
                    />
                    <Dato
                        titulo="Moneda"
                        valor={p.currencyInFile ?? 'No informada'}
                    />
                </dl>
            </div>

            <TablaDeFilas filas={p.rows} />
        </div>
    );
}

/**
 * Uno de los dos extremos de la cadena.
 *
 * El pie con la fecha no es un adorno: sin él, «saldo previo» y «saldo en
 * el banco» obligan a deducir a qué momento corresponde cada uno mirando
 * la tabla de abajo.
 */
function Saldo({
    titulo,
    valor,
    pie,
    destacado = false,
}: {
    titulo: string;
    valor: string;
    pie?: string;
    destacado?: boolean;
}) {
    return (
        <div>
            <p className="text-xs font-medium text-field-label">{titulo}</p>
            <p
                className={cn(
                    'mt-1 font-mono tabular-nums',
                    destacado
                        ? 'text-xl font-semibold'
                        : 'text-lg text-muted-foreground',
                )}
            >
                {money(valor)}
            </p>
            {pie && (
                <p className="mt-0.5 text-xs text-muted-foreground">{pie}</p>
            )}
        </div>
    );
}

/** Un dato del detalle: rótulo arriba, valor abajo. */
function Dato({
    titulo,
    valor,
    pista,
}: {
    titulo: string;
    valor: string | number;
    pista?: string;
}) {
    return (
        <div>
            <dt className="text-xs font-medium text-field-label">{titulo}</dt>
            <dd className="mt-0.5 tabular-nums">{valor}</dd>
            {pista && (
                <p className="mt-0.5 text-xs text-muted-foreground">{pista}</p>
            )}
        </div>
    );
}

function Metrica({
    valor,
    titulo,
    pista,
    tono = 'normal',
}: {
    valor: number;
    titulo: string;
    pista?: string;
    tono?: 'normal' | 'fuerte' | 'alerta';
}) {
    return (
        <div>
            <p className="flex items-baseline gap-1.5">
                <span
                    className={cn(
                        'text-2xl leading-none font-semibold tabular-nums',
                        tono === 'fuerte' && 'text-primary',
                        tono === 'alerta' && 'text-warning-strong',
                        tono === 'normal' && 'text-muted-foreground',
                    )}
                >
                    {valor}
                </span>
                <span className="text-sm text-muted-foreground">{titulo}</span>
            </p>
            {pista && (
                <p className="mt-0.5 text-xs text-muted-foreground">{pista}</p>
            )}
        </div>
    );
}

function TablaDeFilas({ filas }: { filas: Fila[] }) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="p-2 font-medium text-field-label">
                            Fecha
                        </th>
                        <th className="p-2 font-medium text-field-label">
                            Referencia
                        </th>
                        <th className="p-2 font-medium text-field-label">
                            Causal
                        </th>
                        <th className="p-2 font-medium text-field-label">
                            Concepto
                        </th>
                        <th className="p-2 text-right font-medium text-field-label">
                            Débito
                        </th>
                        <th className="p-2 text-right font-medium text-field-label">
                            Crédito
                        </th>
                        <th className="p-2 text-right font-medium text-field-label">
                            Saldo
                        </th>
                        <th className="p-2 font-medium text-field-label">
                            Estado
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {filas.map((fila) => (
                        <FilaPrevia key={fila.rowNumber} fila={fila} />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function FilaPrevia({ fila }: { fila: Fila }) {
    if (fila.status === 'rejected') {
        return (
            <tr className="border-t bg-destructive-soft/20">
                <td className="p-2 text-destructive-strong" colSpan={7}>
                    Fila {fila.rowNumber}: {fila.errorMessage}
                </td>
                <td className="p-2">
                    <StatusBadge label="Rechazada" tone="blocked" />
                </td>
            </tr>
        );
    }

    const esDebito = fila.direction === 'debit';

    /*
     * Solo se marca la repetida.
     *
     * Poner un distintivo en cada fila nueva sería marcar lo normal:
     * catorce etiquetas «Nuevo» no dicen nada. Lo que el operador
     * necesita ver de un vistazo es cuáles NO van a entrar, y por qué el
     * total de abajo no coincide con la cantidad de filas del archivo.
     */
    return (
        <tr className={cn('border-t', fila.repeated && 'bg-muted/40')}>
            <td className="p-2 whitespace-nowrap">
                <span className={cn(fila.repeated && 'text-muted-foreground')}>
                    {date(fila.transactionDate)}
                </span>
            </td>
            <td className="p-2 tabular-nums">{fila.operationId ?? '—'}</td>
            <td className="p-2 tabular-nums">{fila.causalCode ?? '—'}</td>
            <td className="p-2">
                <span className="block max-w-[28rem] truncate">
                    {fila.description ?? '—'}
                </span>
                {fila.counterpartyIdentifier && (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <Link2 className="size-3" />
                        CUIT {fila.counterpartyIdentifier}
                    </span>
                )}
            </td>
            <td className="p-2 text-right tabular-nums">
                {esDebito ? money(fila.amount) : ''}
            </td>
            <td className="p-2 text-right tabular-nums">
                {esDebito ? '' : money(fila.amount)}
            </td>
            <td className="p-2 text-right text-muted-foreground tabular-nums">
                {money(fila.balanceAfter)}
            </td>
            <td className="p-2">
                {fila.repeated ? (
                    <span className="inline-flex items-center gap-1 text-xs whitespace-nowrap text-muted-foreground">
                        <CopyCheck className="size-3.5 shrink-0" />
                        Ya registrado
                    </span>
                ) : (
                    <StatusBadge
                        label={ETIQUETA_FILA[fila.status]}
                        tone={TONO_FILA[fila.status]}
                    />
                )}
            </td>
        </tr>
    );
}

/* ───────────────────────────── Paso 3 ───────────────────────────── */

function PasoResultado({ result }: { result: Resultado }) {
    const entro = result.status === 'completed';

    return (
        <div className="mx-auto w-full max-w-2xl animate-in space-y-6 duration-300 ease-out fade-in-0 slide-in-from-bottom-2 motion-reduce:animate-none">
            <div
                className={cn(
                    'rounded-lg border p-6',
                    entro ? 'bg-success-soft/40' : 'bg-destructive-soft/40',
                )}
            >
                <div className="flex items-start gap-3">
                    {entro ? (
                        <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-success-strong" />
                    ) : (
                        <AlertTriangle className="mt-0.5 size-5 shrink-0 text-destructive-strong" />
                    )}
                    <div className="min-w-0">
                        <h2
                            className={cn(
                                'font-semibold',
                                entro
                                    ? 'text-success-strong'
                                    : 'text-destructive-strong',
                            )}
                        >
                            {entro
                                ? 'El extracto quedó importado'
                                : 'El extracto no se importó'}
                        </h2>
                        <p className="mt-1 text-sm break-words text-muted-foreground">
                            {result.originalFilename}
                            {result.periodFrom && result.periodTo
                                ? ` · del ${date(result.periodFrom)} al ${date(result.periodTo)}`
                                : ''}
                        </p>
                        {result.failureReason && (
                            <p className="mt-3 text-sm text-destructive-strong">
                                {result.failureReason}
                            </p>
                        )}
                    </div>
                </div>

                {entro && (
                    <div className="mt-5 flex flex-wrap gap-x-8 gap-y-3 border-t border-success/20 pt-4">
                        <Metrica
                            valor={result.rowsNew}
                            titulo="movimientos nuevos"
                            tono="fuerte"
                        />
                        <Metrica
                            valor={result.rowsDuplicate}
                            titulo="ya estaban"
                        />
                        {result.closingBalance && (
                            <div>
                                <p className="text-xs font-medium text-field-label">
                                    Saldo en el banco
                                </p>
                                <p className="mt-0.5 font-mono text-lg font-semibold tabular-nums">
                                    {money(result.closingBalance)}
                                </p>
                                {result.periodTo && (
                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                        al {date(result.periodTo)}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button asChild>
                    <Link href={show(result.id)}>
                        Ver el extracto
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
                <Button variant="outline" asChild>
                    <Link href={create()}>Importar otro</Link>
                </Button>
            </div>

            {entro && result.rowsNew > 0 && (
                <p className="text-sm text-muted-foreground">
                    Los movimientos entran sin identificar. Se los asigna a las
                    cuotas desde la pantalla de movimientos, cuando el circuito
                    de recepciones esté disponible.
                </p>
            )}
        </div>
    );
}

/* ───────────────────────────── Utilidades ───────────────────────────── */

function formatoDe(nombre: string): 'Excel' | 'CSV' {
    return /\.(csv|txt)$/i.test(nombre) ? 'CSV' : 'Excel';
}

function pesoLegible(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}
