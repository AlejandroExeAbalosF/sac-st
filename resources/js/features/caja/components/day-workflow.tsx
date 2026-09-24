import {
    Check,
    ChevronDown,
    History,
    ListOrdered,
    Lock,
    LockOpen,
    RefreshCw,
    Scale,
    Scissors,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DialogoHistorialDePlanillas,
    DialogoReapertura,
    DialogoRehacerPlanilla,
} from '@/features/caja/components/closing-dialogs';
import DialogoConteo from '@/features/caja/components/count-dialog';
import {
    TarjetaArqueo,
    TarjetaCierre,
} from '@/features/caja/components/day-cards';
import {
    DialogoCierreDelDia,
    DialogoDetalle,
    DialogoImputacion,
    DialogoRevision,
} from '@/features/caja/components/day-workflow-dialogs';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;
type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;

type Permisos = {
    count: boolean;
    review: boolean;
    adjust: boolean;
    close: boolean;
    reopen: boolean;
    export: boolean;
    regenerate: boolean;
};

/**
 * El cierre de la jornada, paso por paso.
 *
 * La caja del día termina en dos actos encadenados —contar el cajón y
 * cerrar el día— y el orden no es una convención: **el cierre no admite
 * arqueos en borrador**, y eso lo impone un trigger de la base. Antes las
 * dos tarjetas ofrecían un enlace a otra pantalla cada una, y el operador
 * tenía que descubrir por su cuenta que una iba antes que la otra.
 *
 * Ahora cada tarjeta ofrece **el paso que toca**:
 *
 * | Arqueo | Botón | Cierre |
 * |---|---|---|
 * | no hay | Contar | trabado: falta el arqueo |
 * | `draft` | Revisar | trabado: espera revisión |
 * | `reviewed` con diferencia | Imputar diferencia | trabado: la diferencia sigue viva |
 * | `reviewed` / `adjusted` | — | habilitado |
 * | `closed` (día cerrado) | Contar, apagado: hay que reabrir | — |
 *
 * Ningún botón **se esconde cuando no corresponde: se apaga y
 * dice por qué**. Un botón que desaparece obliga a adivinar; uno que
 * explica enseña el circuito.
 */
export default function FlujoDelDia({
    fecha,
    cashBoxId,
    cajaNombre,
    currency,
    arqueos,
    anterior,
    referenciaComposicion,
    cajonMovido,
    esperado,
    recaudacion,
    cierre,
    denominaciones,
    sheetVersion,
    can,
}: {
    fecha: string;
    cashBoxId: number;
    cajaNombre: string;
    currency: string;
    /**
     * Todos los turnos del día, en orden. El último es el que manda para el
     * cierre; los anteriores son la historia, y se pueden mirar.
     */
    arqueos: Arqueo[];
    /**
     * El arqueo firme anterior a este día, para comparar al revisar.
     *
     * **No entra al conteo.** Ni sus denominaciones ni su arrastre: un
     * arqueo precargado es indistinguible de uno real, y comparar es
     * trabajo de quien revisa, no de quien cuenta.
     */
    anterior: Arqueo | null;
    /** Último conteo completo anterior con composición por billete. */
    referenciaComposicion: Arqueo | null;
    /** Si la caja se movió después de este día. */
    cajonMovido: boolean;
    /** El saldo del libro para este día, para adelantar la diferencia. */
    esperado: string;
    /** Lo que entró hoy y sigue en el cajón, según el libro. */
    recaudacion: string;
    /** La versión con la que dibuja el generador de hoy. */
    sheetVersion: string;
    cierre: Cierre | null;
    denominaciones: number[];
    can: Permisos;
}) {
    const arqueo = arqueos.at(-1) ?? null;

    const [contando, setContando] = useState(false);
    const [mirando, setMirando] = useState(false);
    const [revisando, setRevisando] = useState(false);
    const [imputando, setImputando] = useState(false);
    const [cerrando, setCerrando] = useState(false);
    const [reabriendo, setReabriendo] = useState(false);
    const [rehaciendo, setRehaciendo] = useState(false);
    const [mirandoPlanillas, setMirandoPlanillas] = useState(false);

    const cerrado = cierre?.status === 'closed';

    /*
     * La planilla guardada se dibujó con una versión anterior. Los
     * números no cambian --el cierre está congelado-- así que el archivo
     * se ve igual de oficial: sin este aviso no hay forma de notarlo.
     */
    /** Si hay al menos una acción correctiva que ofrecer. */
    const hayCorrecciones =
        can.reopen ||
        (can.regenerate && cierre?.sheetAttachmentId != null) ||
        (can.export && (cierre?.sheetHistory.length ?? 0) > 1);

    const planillaAtrasada =
        cierre !== null &&
        cierre.sheetAttachmentId !== null &&
        cierre.sheetTemplateVersion !== sheetVersion;
    const reabierto = cierre?.status === 'reopened';
    const necesitaConteo = arqueo === null || arqueo.status === 'closed';

    /*
     * Solo el último arqueo revisado o ajustado respalda el cierre. Uno en
     * `closed` pertenece al snapshot anterior y, si el período se reabrió,
     * obliga a contar otra vez.
     */
    const resuelto =
        arqueo !== null &&
        ((arqueo.status === 'reviewed' && arqueo.balanced) ||
            arqueo.status === 'adjusted');

    const motivoDelBloqueo =
        necesitaConteo && reabierto
            ? 'El cierre fue reabierto. Hay que volver a contar el cajón y revisar el nuevo arqueo.'
            : arqueo === null
              ? 'Primero hay que contar el cajón: el cierre no admite un día sin arqueo.'
              : arqueo.status === 'closed'
                ? 'Este arqueo pertenece a un cierre anterior. Hay que volver a contar el cajón.'
                : arqueo.status === 'draft'
                  ? 'El arqueo está en borrador. Hay que revisarlo antes de cerrar.'
                  : arqueo.status === 'reviewed' && !arqueo.balanced
                    ? 'El arqueo no cuadra con el libro. Imputá la diferencia o volvé a contar el cajón: el cierre congela el saldo del libro, y una diferencia viva queda archivada con el día.'
                    : null;

    /*
     * El paso que la tarjeta propone: revisar el borrador, o imputar la
     * diferencia del arqueo ya revisado.
     */
    const pasoQueSigue =
        arqueo?.status === 'draft'
            ? (can.review || !arqueo.balanced) && (
                  <>
                      {can.review && (
                          <Button
                              variant="outline"
                              size="sm"
                              className="mt-3 w-full"
                              onClick={() => setRevisando(true)}
                          >
                              <Check className="size-4" />
                              Revisar el arqueo
                          </Button>
                      )}
                      {!arqueo.balanced && (
                          <p className="mt-2 text-xs text-muted-foreground">
                              Primero revisá el arqueo. Si confirmás la
                              diferencia, después se habilita «Imputar la
                              diferencia».
                          </p>
                      )}
                  </>
              )
            : arqueo?.status === 'reviewed' &&
              !arqueo.balanced &&
              can.adjust && (
                  <Button
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full"
                      onClick={() => setImputando(true)}
                  >
                      <Scissors className="size-4" />
                      Imputar la diferencia
                  </Button>
              );

    const accionArqueo = necesitaConteo
        ? can.count && (
              /*
               * Con el día cerrado el botón se apaga y dice por qué.
               *
               * El arqueo de un día cerrado queda en `closed` y la tarjeta
               * lo lee como «hace falta contar de nuevo» —que es cierto
               * después de reabrir—, así que ofrecía contar sobre una
               * jornada congelada. Esconderlo obligaría a adivinar que el
               * paso anterior es reabrir; apagarlo lo enseña.
               */
              <>
                  {cerrado && (
                      <p
                          id="motivo-conteo-bloqueado"
                          role="status"
                          className="mt-3 text-xs text-muted-foreground"
                      >
                          El día está cerrado. Para volver a contar el cajón hay
                          que reabrirlo primero.
                      </p>
                  )}
                  <Button
                      variant="outline"
                      size="sm"
                      className="mt-3 w-full"
                      disabled={cerrado}
                      aria-describedby={
                          cerrado ? 'motivo-conteo-bloqueado' : undefined
                      }
                      onClick={() => setContando(true)}
                  >
                      <Scale className="size-4" />
                      {reabierto
                          ? 'Volver a contar el cajón'
                          : 'Contar el cajón'}
                  </Button>
              </>
          )
        : /*
           * Volver a contar siempre está a mano.
           *
           * **Un conteo mal cargado dejaba una sola salida: imputar.** Si
           * el cajero contaba de menos —el efectivo del día en vez del
           * cajón entero— la tarjeta ofrecía imputar esa diferencia, que
           * asienta contra `CASH_DIFFERENCE` y mueve el libro hasta el
           * número equivocado. Recontar existía en el motor y en la
           * pantalla de Arqueos, pero no acá, que es donde termina la
           * jornada.
           *
           * Qué hace depende de en qué estado quedó el anterior, y de eso
           * se ocupa `nextSequence`: un borrador se reemplaza, uno firme
           * abre el turno siguiente.
           */
          (can.count || pasoQueSigue) && (
              <>
                  {pasoQueSigue}
                  {can.count && (
                      <>
                          {/*
                           * La aclaración va antes del botón: dice qué va a
                           * pasar, y leerla después de apretar no sirve de
                           * nada.
                           */}
                          <p className="mt-3 text-xs text-muted-foreground">
                              {arqueo?.status === 'draft'
                                  ? 'Reemplaza el borrador por un conteo nuevo.'
                                  : 'Abre un turno nuevo. El arqueo anterior queda como historia.'}
                          </p>
                          <Button
                              variant="outline"
                              size="sm"
                              className="mt-2 w-full"
                              onClick={() => setContando(true)}
                          >
                              <Scale className="size-4" />
                              Contar de nuevo
                          </Button>
                      </>
                  )}
              </>
          );

    return (
        <>
            <TarjetaArqueo
                arqueo={arqueo}
                fecha={fecha}
                accion={
                    <>
                        {accionArqueo}
                        {arqueo !== null && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="mt-2 w-full"
                                onClick={() => setMirando(true)}
                            >
                                <ListOrdered className="size-4" />
                                {arqueos.length > 1
                                    ? `Ver los ${arqueos.length} arqueos del día`
                                    : 'Ver el detalle'}
                            </Button>
                        )}
                    </>
                }
            />

            <TarjetaCierre
                cierre={cierre}
                cerrado={cerrado}
                puedeExportar={can.export}
                fecha={fecha}
                accion={
                    <>
                        {/*
                         * Lo que se puede hacer con el día ya cerrado:
                         * mirar sus planillas, rehacer la última y
                         * reabrirlo.
                         *
                         * Estaban solo en la pantalla de Cierres, y para
                         * usarlas había que salir del día que se estaba
                         * mirando y buscar su tarjeta en una lista de
                         * todos los períodos. El día ya está acá.
                         */}
                        {/*
                         * Las acciones correctivas, plegadas.
                         *
                         * Son excepcionales --rehacer una planilla o
                         * reabrir un día pasa pocas veces-- y sueltas
                         * competían con «Planilla del día», que es lo que
                         * el operador viene a buscar todos los días. Se
                         * abren solas cuando la planilla quedó atrás: ahí
                         * el aviso y su remedio tienen que verse juntos.
                         */}
                        {cerrado && hayCorrecciones && (
                            <Collapsible
                                defaultOpen={planillaAtrasada}
                                className="mt-3"
                            >
                                <CollapsibleTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="w-full justify-between text-muted-foreground"
                                    >
                                        Corregir este cierre
                                        <ChevronDown className="size-4" />
                                    </Button>
                                </CollapsibleTrigger>

                                <CollapsibleContent>
                                    <div className="mt-1 flex flex-wrap gap-2">
                                        {can.export &&
                                            cierre !== null &&
                                            cierre.sheetHistory.length > 1 && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setMirandoPlanillas(
                                                            true,
                                                        )
                                                    }
                                                >
                                                    <History className="size-4" />
                                                    {cierre.sheetHistory.length}{' '}
                                                    versiones
                                                </Button>
                                            )}
                                        {can.regenerate &&
                                            cierre?.sheetAttachmentId !=
                                                null && (
                                                <Button
                                                    variant={
                                                        planillaAtrasada
                                                            ? 'default'
                                                            : 'ghost'
                                                    }
                                                    size="sm"
                                                    onClick={() =>
                                                        setRehaciendo(true)
                                                    }
                                                >
                                                    <RefreshCw className="size-4" />
                                                    Rehacer la planilla
                                                </Button>
                                            )}
                                        {can.reopen && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setReabriendo(true)
                                                }
                                            >
                                                <LockOpen className="size-4" />
                                                Reabrir
                                            </Button>
                                        )}
                                    </div>

                                    {planillaAtrasada && (
                                        <p className="mt-2 text-xs text-warning-strong">
                                            La planilla guardada usa un formato
                                            anterior. Los números son los
                                            mismos: el cierre está congelado.
                                        </p>
                                    )}
                                </CollapsibleContent>
                            </Collapsible>
                        )}

                        {/*
                         * El snapshot dejó de coincidir con el libro.
                         *
                         * Va fuera del desplegable y en rojo, no en
                         * naranja: la planilla vieja es cosmética, esto
                         * son los números del día diciendo algo que el
                         * libro no dice. Hoy ya no puede ocurrir --el
                         * asiento anterior se rechaza-- pero los datos
                         * cargados antes de esa regla pueden traerlo.
                         */}
                        {cierre?.matchesLedger === false && (
                            <p className="mt-3 flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-3 py-2 text-xs text-destructive">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    Los saldos congelados ya no coinciden con el
                                    libro: entró un movimiento con fecha
                                    anterior después de cerrar. Reabrí el día y
                                    volvé a cerrarlo.
                                </span>
                            </p>
                        )}

                        {(cierre === null || reabierto) && can.close && (
                            <>
                                {motivoDelBloqueo && (
                                    <p
                                        id="motivo-cierre-bloqueado"
                                        role="status"
                                        className="mt-3 text-xs text-muted-foreground"
                                    >
                                        {motivoDelBloqueo}
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3 w-full"
                                    disabled={!resuelto}
                                    aria-describedby={
                                        motivoDelBloqueo
                                            ? 'motivo-cierre-bloqueado'
                                            : undefined
                                    }
                                    onClick={() => setCerrando(true)}
                                >
                                    <Lock className="size-4" />
                                    {reabierto
                                        ? 'Volver a cerrar el día'
                                        : 'Cerrar el día'}
                                </Button>
                            </>
                        )}
                    </>
                }
            />

            <DialogoHistorialDePlanillas
                cierre={mirandoPlanillas ? cierre : null}
                cerrar={() => setMirandoPlanillas(false)}
            />

            <DialogoRehacerPlanilla
                cierre={rehaciendo ? cierre : null}
                cerrar={() => setRehaciendo(false)}
            />

            <DialogoReapertura
                cierre={reabriendo ? cierre : null}
                cerrar={() => setReabriendo(false)}
            />

            {contando && (
                <DialogoConteo
                    abierto
                    cerrar={() => setContando(false)}
                    cashBoxId={cashBoxId}
                    cajaNombre={cajaNombre}
                    currency={currency}
                    fecha={fecha}
                    denominaciones={denominaciones}
                    esperado={esperado}
                    recaudacion={recaudacion}
                    referenciaComposicion={referenciaComposicion}
                    cajonMovido={cajonMovido}
                />
            )}

            {imputando && arqueo && (
                <DialogoImputacion
                    arqueo={arqueo}
                    cerrar={() => setImputando(false)}
                />
            )}

            {mirando && arqueo !== null && (
                <DialogoDetalle
                    arqueos={arqueos}
                    referenciaComposicion={referenciaComposicion}
                    cerrar={() => setMirando(false)}
                />
            )}

            {revisando && arqueo?.status === 'draft' && (
                <DialogoRevision
                    arqueo={arqueo}
                    anterior={anterior}
                    referenciaComposicion={referenciaComposicion}
                    cerrar={() => setRevisando(false)}
                    volverAContar={() => {
                        setRevisando(false);
                        setContando(true);
                    }}
                />
            )}

            {cerrando && (
                <DialogoCierreDelDia
                    fecha={fecha}
                    cashBoxId={cashBoxId}
                    currency={currency}
                    arqueo={arqueo}
                    recierre={reabierto}
                    cerrar={() => setCerrando(false)}
                />
            )}
        </>
    );
}
