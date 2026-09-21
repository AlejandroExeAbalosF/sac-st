import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    FileSpreadsheet,
    Lock,
    RotateCcw,
    Scale,
    UserCheck,
} from 'lucide-react';
import type { ReactNode } from 'react';
import Money, { useMoneda } from '@/components/money';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { conMoneda } from '@/features/caja/moneda';
import { index as arqueos } from '@/routes/caja/arqueos';
import { index as cierres, sheet } from '@/routes/caja/cierres';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;
type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;

/**
 * Las dos tarjetas del día: el arqueo y el cierre.
 *
 * Viven acá y no en `pages/caja/index` porque las lee también el
 * calendario, que muestra el día elegido en la grilla del mes con estas
 * mismas cajas. Son de solo lectura —cuentan qué pasó ese día— y las
 * acciones que ofrecen llevan a las pantallas de arqueos y cierres, que es
 * donde se opera.
 */

export function TarjetaArqueo({
    arqueo,
    puedeArquear = false,
    fecha,
    accion,
}: {
    arqueo: Arqueo | null;
    /** Solo se usa cuando no hay `accion`: dibuja el enlace a Arqueos. */
    puedeArquear?: boolean;
    fecha: string;
    /**
     * El paso que toca, cuando quien la usa maneja el flujo. Sin esto la
     * tarjeta cae al enlace de siempre, que es lo que hace el calendario.
     */
    accion?: ReactNode;
}) {
    // El enlace conserva el libro que la pantalla está mirando.
    const enlaceArqueo = conMoneda(
        arqueos({ query: { fecha } }).url,
        useMoneda(),
    );

    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Scale className="size-4 text-muted-foreground" />
                    Arqueo
                </h2>
                {arqueo && (
                    <StatusBadge
                        label={arqueo.statusLabel}
                        tone={
                            arqueo.status === 'draft'
                                ? 'action'
                                : arqueo.status === 'closed'
                                  ? 'done'
                                  : 'progress'
                        }
                    />
                )}
            </div>

            {arqueo === null ? (
                <>
                    <p className="mt-3 text-sm text-muted-foreground">
                        Todavía no se contó el cajón este día.
                    </p>
                    {accion ??
                        (puedeArquear && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-3 w-full"
                                asChild
                            >
                                <Link href={enlaceArqueo}>
                                    Contar
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        ))}
                </>
            ) : (
                <>
                    <dl className="mt-3 space-y-1.5 text-sm">
                        <Renglon
                            termino="Contado"
                            valor={arqueo.countedAmount}
                        />
                        {!arqueo.fullyCounted && (
                            <Renglon
                                termino="No recontado"
                                valor={arqueo.uncountedAmount}
                            />
                        )}
                        <Renglon
                            termino="Saldo del libro"
                            valor={arqueo.expectedAmount}
                        />
                        <div className="border-t pt-1.5">
                            <Renglon
                                termino="Diferencia"
                                valor={arqueo.differenceAmount}
                                fuerte
                            />
                        </div>

                        {/*
                         * Cuadrar no es haber contado todo, y la pantalla lo
                         * dice: sin esta línea, un arqueo con la mitad del
                         * cajón sin contar se ve idéntico a uno completo.
                         */}
                        {arqueo.balanced && !arqueo.fullyCounted && (
                            <p className="pt-1 text-xs text-warning-strong">
                                Cuadra, pero no se contó todo el cajón.
                            </p>
                        )}

                        {/*
                         * Un arqueo aprobado por su propio autor prueba menos
                         * que uno que pasó por otro par de ojos. Se admite —el
                         * área trabaja con un equipo chico— pero no se calla.
                         */}
                        {/*
                         * La explicación de la diferencia se ve acá.
                         * Una diferencia la exige, y mostrarla sin ella
                         * deja el número sin sentido: el detalle completo
                         * está a un clic, pero el motivo se lee de una.
                         */}
                        {arqueo.explanation && (
                            <p className="pt-1 text-xs text-muted-foreground">
                                {arqueo.explanation}
                            </p>
                        )}

                        {/*
                         * Con nombre y apellido, no «quien contó».
                         *
                         * Decir quién firmó es el punto entero de la
                         * línea: un arqueo sin segunda firma se audita
                         * preguntando por una persona, y obligar a abrir
                         * el detalle para saber cuál convierte el aviso en
                         * un acertijo.
                         */}
                        {arqueo.selfReviewed && (
                            <p className="flex items-center gap-1.5 pt-1 text-xs text-muted-foreground">
                                <UserCheck className="size-3.5 shrink-0" />
                                {arqueo.reviewedBy
                                    ? `Revisado por ${arqueo.reviewedBy}, sin segunda firma.`
                                    : 'Revisado por quien contó, sin segunda firma.'}
                            </p>
                        )}
                    </dl>

                    {accion}
                </>
            )}
        </div>
    );
}

export function TarjetaCierre({
    cierre,
    cerrado,
    puedeExportar,
    puedeCerrar = false,
    fecha,
    accion,
}: {
    cierre: Cierre | null;
    cerrado: boolean;
    puedeExportar: boolean;
    /** Solo se usa cuando no hay `accion`: dibuja el enlace a Cierres. */
    puedeCerrar?: boolean;
    fecha: string;
    accion?: ReactNode;
}) {
    const reabierto = cierre?.status === 'reopened';
    const enlaceCierre = conMoneda(
        cierres({ query: { fecha } }).url,
        useMoneda(),
    );

    return (
        <div className="rounded-lg border bg-card p-4">
            <div className="flex items-center justify-between">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <Lock className="size-4 text-muted-foreground" />
                    {reabierto ? 'Último cierre del día' : 'Cierre del día'}
                </h2>
                {cierre && (
                    <StatusBadge
                        label={cierre.statusLabel}
                        tone={cerrado ? 'done' : 'action'}
                    />
                )}
            </div>

            {cierre === null ? (
                <>
                    <p className="mt-3 text-sm text-muted-foreground">
                        El día sigue abierto: admite movimientos.
                    </p>
                    {accion ??
                        (puedeCerrar && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="mt-3 w-full"
                                asChild
                            >
                                <Link href={enlaceCierre}>
                                    Cerrar
                                    <ArrowRight className="size-4" />
                                </Link>
                            </Button>
                        ))}
                </>
            ) : (
                <>
                    {reabierto && (
                        <div className="mt-3 flex gap-2 rounded-md bg-warning-soft px-3 py-2.5 text-sm text-warning-strong">
                            <RotateCcw className="mt-0.5 size-4 shrink-0" />
                            <div className="min-w-0">
                                <p className="font-medium">
                                    Este snapshot ya no está vigente.
                                </p>
                                <p className="mt-0.5 text-xs">
                                    {cierre.reopenedBy
                                        ? `Reabierto por ${cierre.reopenedBy}. `
                                        : 'El período fue reabierto. '}
                                    El nuevo cierre necesita otro arqueo.
                                </p>
                                {cierre.reopenReason && (
                                    <p className="mt-1 text-xs break-words">
                                        Motivo: {cierre.reopenReason}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}
                    <dl className="mt-3 space-y-1.5 text-sm">
                        <Renglon
                            termino="Saldo inicial"
                            valor={cierre.openingCash}
                        />
                        <Renglon
                            termino="Ingresos"
                            valor={cierre.receivedCash}
                        />
                        <Renglon
                            termino="Egresos"
                            valor={cierre.disbursedCash}
                        />
                        <div className="border-t pt-1.5">
                            <Renglon
                                termino="Saldo final"
                                valor={cierre.closingCash}
                                fuerte
                            />
                        </div>
                    </dl>

                    {cerrado && puedeExportar && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="mt-3 w-full"
                            asChild
                        >
                            <a href={sheet({ closing: cierre.id }).url}>
                                <FileSpreadsheet className="size-4" />
                                Planilla del día
                            </a>
                        </Button>
                    )}

                    {/*
                     * La acción va siempre, no solo cuando el período se
                     * reabrió.
                     *
                     * Cuando esto se escribió, lo único que la tarjeta
                     * podía ofrecer sobre un cierre existente era volver a
                     * cerrarlo, y eso solo tiene sentido reabierto. Hoy
                     * también ofrece mirar las planillas emitidas,
                     * rehacerlas y reabrir el día: con la condición vieja,
                     * un día cerrado las descartaba todas.
                     *
                     * Cada parte de la acción trae su propia guarda, así
                     * que dejarla pasar entera es lo correcto.
                     */}
                    {accion}
                </>
            )}
        </div>
    );
}

function Renglon({
    termino,
    valor,
    fuerte = false,
}: {
    termino: string;
    valor: string;
    fuerte?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt
                className={
                    fuerte
                        ? 'text-sm font-medium'
                        : 'text-sm text-muted-foreground'
                }
            >
                {termino}
            </dt>
            <dd>
                <Money
                    value={valor}
                    dimWhenZero={!fuerte}
                    className={fuerte ? 'font-semibold' : undefined}
                />
            </dd>
        </div>
    );
}
