import { Link } from '@inertiajs/react';
import { ArrowRight, TriangleAlert } from 'lucide-react';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';

type CashDay = App.Modules.Ledger.Data.CashDayStatusData;

/**
 * Cómo está la caja hoy.
 *
 * La primera pregunta de la mañana, contestada sin entrar a Caja: cuánto
 * hay, dónde está y en qué estado quedó el día. Los cuatro números son
 * los mismos que muestra la pantalla de Caja porque salen del mismo
 * `CashBalance`; acá no se calcula nada.
 *
 * `unassigned` va con los otros tres a propósito: no dice dónde está la
 * plata sino de quién todavía no se sabe, y ponerlo al lado es lo que
 * convierte cuatro saldos en una respuesta.
 */
export default function CashDayCard({ day }: { day: CashDay }) {
    const saldos = [
        { label: 'En el cajón', value: day.balances.cash },
        { label: 'Cheques en custodia', value: day.balances.cheques },
        { label: 'En el banco', value: day.balances.bank },
        {
            label: 'Sin identificar',
            value: day.balances.unassigned,
            alerta: true,
        },
    ];

    return (
        <section
            aria-labelledby="caja-del-dia"
            className="overflow-hidden rounded-lg border bg-card shadow-raised"
        >
            <div className="flex flex-wrap items-center gap-2 border-b px-5 py-4">
                <h2 id="caja-del-dia" className="text-sm font-semibold">
                    Caja {day.balances.name}
                </h2>

                <EstadoDelDia day={day} />

                <Link
                    href={day.href}
                    className="group ml-auto flex items-center gap-1 text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Ir a la caja
                    <ArrowRight
                        className="size-3.5 transition-transform group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
            </div>

            {day.needsOpening ? (
                /*
                 * Mientras los libros no se abran, los cuatro saldos son
                 * cero y mostrarlos sería mentir con precisión de dos
                 * decimales. Es lo único que importa de la caja hasta que
                 * alguien registre el saldo inicial.
                 */
                <p className="flex items-start gap-3 px-5 py-6 text-sm">
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0 text-warning-strong"
                        aria-hidden="true"
                    />
                    <span className="text-muted-foreground">
                        Los libros de esta caja todavía no se abrieron. Hasta
                        que se registre el saldo inicial, todos los saldos son
                        cero y el primer arqueo daría una diferencia igual al
                        saldo histórico completo.
                    </span>
                </p>
            ) : (
                <dl className="grid gap-px bg-border sm:grid-cols-4">
                    {saldos.map((saldo) => (
                        <div key={saldo.label} className="bg-card px-5 py-4">
                            <dt className="text-xs font-medium tracking-wide text-field-label uppercase">
                                {saldo.label}
                            </dt>
                            <dd
                                className={cn(
                                    'mt-1 font-mono text-xl leading-none font-semibold tabular-nums',
                                    saldo.alerta &&
                                        saldo.value !== '0.00' &&
                                        'text-warning-strong',
                                )}
                            >
                                {money(saldo.value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

/**
 * En qué punto del día está la caja: sin contar, arqueada o cerrada.
 *
 * Son tres hitos y no un estado libre. El cierre reabierto se muestra
 * aparte porque no es lo mismo que «todavía no cerró»: alguien lo cerró y
 * hubo que volver atrás, y eso pide explicación.
 */
function EstadoDelDia({ day }: { day: CashDay }) {
    const { texto, clase } =
        day.closingStatus === 'closed'
            ? {
                  texto: 'día cerrado',
                  clase: 'bg-success-soft text-success-strong',
              }
            : day.closingStatus === 'reopened'
              ? {
                    texto: 'cierre reabierto',
                    clase: 'bg-warning-soft text-warning-strong',
                }
              : day.countsToday > 0
                ? {
                      texto:
                          day.countsToday === 1
                              ? 'arqueado'
                              : `${day.countsToday} arqueos`,
                      clase: 'bg-info-soft text-info-strong',
                  }
                : {
                      texto: 'sin arquear',
                      clase: 'bg-muted text-muted-foreground',
                  };

    return (
        <span
            className={cn(
                'rounded-full px-2 py-0.5 text-[11px] font-medium',
                clase,
            )}
        >
            {texto}
        </span>
    );
}
