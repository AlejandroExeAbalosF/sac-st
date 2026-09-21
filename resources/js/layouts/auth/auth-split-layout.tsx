import type { CSSProperties } from 'react';
import AppLogoMark from '@/components/app-logo-mark';
import SaltaLogo from '@/components/salta-logo';
import type { AuthLayoutProps } from '@/types';

/**
 * Pantalla de acceso, según el diseño del área.
 *
 * Dos mitades: a la izquierda la identidad institucional sobre el azul
 * profundo, a la derecha la tarjeta con el formulario. Abajo de `lg` la
 * mitad izquierda no se pliega: desaparece. El panel es presentación y el
 * formulario es lo que hay que usar, así que en pantalla chica queda la
 * tarjeta sola, con la marca arriba y el organismo al pie.
 *
 * El logo va suelto sobre el azul, sin placa: lo despega un resplandor
 * blanco que le hace de contorno (`halo`, ver AppLogoMark). Está dibujado
 * para fondo claro, así que sin ese contorno la silueta se diluye contra
 * el panel.
 *
 * Arriba va el membrete institucional, el mismo recurso que usa Bitácora
 * en su pantalla de acceso: tarjeta ancha, organismo al medio y escudo de
 * la Provincia al extremo. Traído acá, pierde el vidrio esmerilado —no hay
 * nada detrás que refractar, así que `backdrop-blur` sería una capa de
 * composición al pedo— y toma el azul y las versalitas de este proyecto.
 *
 * Las entradas se escalonan con `.sac-entra` (ver app.css), que no hace
 * nada si el sistema pidió menos movimiento. El membrete abre la secuencia
 * (0 ms) porque es lo primero que se lee de arriba hacia abajo.
 */
export default function AuthSplitLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const anio = new Date().getFullYear();
    const pie = `Ministerio de Gobierno y Justicia · Secretaría de Trabajo`;

    return (
        <div className="grid min-h-dvh lg:grid-cols-[min(46%,640px)_minmax(0,1fr)]">
            {/* Panel institucional */}
            <aside className="relative isolate hidden flex-col overflow-hidden bg-primary text-primary-foreground lg:flex">
                {/*
                 * Una veladura muy tenue detrás del logo, para que el panel
                 * no sea una plancha plana. Antes acompañaba a un rayado a
                 * 21° —el ángulo de las aristas del logo—, que se sacó: con
                 * el logo suelto sobre el azul, el rayado le competía al
                 * halo en lugar de sostenerlo.
                 */}
                <div
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-0 -z-10"
                    style={{
                        backgroundImage:
                            'radial-gradient(55% 40% at 50% 26%, rgba(255,255,255,0.16), transparent 72%)',
                    }}
                />

                {/*
                 * Membrete institucional: solo de lg para arriba, igual que
                 * el resto del panel. Esquinas rectas, a diferencia de la
                 * tarjeta del formulario: es un membrete, y el canto vivo es
                 * lo que lo hace leer como papel con sello.
                 */}
                <div className="hidden px-12 pt-10 lg:block">
                    <div
                        className="sac-entra relative flex w-full items-center gap-4 overflow-hidden bg-white/[0.06] p-3 pr-4 shadow-lg ring-1 shadow-black/20 ring-white/15 ring-inset"
                        style={{ '--sac-retardo': '0ms' } as CSSProperties}
                    >
                        <div className="min-w-0 flex-1">
                            <p className="text-[10px] font-semibold tracking-[0.16em] text-brand-accent uppercase">
                                Ministerio de Gobierno
                            </p>
                            <p className="mt-1 truncate text-sm font-medium">
                                Secretaría de Trabajo
                            </p>
                        </div>

                        <SaltaLogo className="size-11 shrink-0 object-contain" />

                        {/* Filete inferior, en el acento de marca: cierra
                            el membrete con el mismo azul con el que abre,
                            arriba, la línea del organismo. Desvanecido en
                            las puntas para que no corte como una regla.

                            Vuelve a 2 px: los 3 px existían para compensar
                            el bordo, que se separaba del fondo por tono y
                            no por brillo. El acento rinde 4.17:1 contra la
                            tarjeta, así que no necesita el píxel de más. */}
                        <div
                            aria-hidden="true"
                            className="pointer-events-none absolute inset-x-3 bottom-0 h-0.5 rounded-full bg-gradient-to-r from-transparent via-brand-accent to-transparent"
                        />
                    </div>
                </div>

                {/* Identidad completa: solo de lg para arriba */}
                <div className="hidden flex-1 flex-col justify-center px-12 py-16 lg:flex">
                    <div className="mx-auto w-full max-w-sm text-center">
                        {/*
                         * Decorativo: el nombre completo va acá abajo en
                         * texto. Etiquetarlo duplicaba el anuncio en el
                         * lector de pantalla.
                         */}
                        <AppLogoMark
                            animado
                            halo
                            className="sac-entra mx-auto size-35"
                            style={{ '--sac-retardo': '60ms' } as CSSProperties}
                            aria-hidden="true"
                        />

                        <h1
                            className="sac-entra mt-8 text-4xl font-bold tracking-tight"
                            style={
                                { '--sac-retardo': '180ms' } as CSSProperties
                            }
                        >
                            SAC
                        </h1>
                        <p
                            className="sac-entra mt-2 text-lg text-primary-foreground/85"
                            style={
                                { '--sac-retardo': '240ms' } as CSSProperties
                            }
                        >
                            Sistema administrativo contable
                        </p>

                        <div
                            className="sac-entra mx-auto mt-6 h-px w-16 bg-primary-foreground/25"
                            style={
                                { '--sac-retardo': '300ms' } as CSSProperties
                            }
                        />

                        <p
                            className="sac-entra mt-6 text-sm leading-relaxed text-balance text-primary-foreground/65"
                            style={
                                { '--sac-retardo': '360ms' } as CSSProperties
                            }
                        >
                            Centraliza la gestión de Haberes de Consignación,
                            Aranceles, Multas y Reportes en una única
                            plataforma.
                        </p>
                    </div>
                </div>

                {/* El organismo no se repite acá: ya lo dice el membrete,
                    arriba de todo. Queda el lugar y el año.

                    70 % y no 50 %: al 50 % el pie queda en 4.04:1 contra el
                    azul del panel, por debajo del mínimo AA. Es el mismo
                    criterio que la barra lateral (ver AppLogo). */}
                <p className="hidden px-12 pb-10 text-xs text-primary-foreground/70 lg:block">
                    Salta, Argentina · © {anio}
                </p>
            </aside>

            {/* Formulario */}
            <main className="flex items-center justify-center bg-background px-6 py-10 sm:py-12">
                <div className="w-full max-w-md">
                    {/*
                     * Marca sobre la tarjeta: solo abajo de lg, que es
                     * donde el panel institucional no se muestra. Va sobre
                     * el fondo claro, así que el logo no necesita ni placa
                     * ni halo — está dibujado justamente para esto.
                     *
                     * A 64 px va el logo completo y no el isotipo: el
                     * isotipo es la versión podada para 16-48 px, y a este
                     * tamaño se lee como un dibujo al que le faltan cosas
                     * (ver AppLogoIcon).
                     */}
                    <div
                        className="sac-entra mb-6 flex items-center justify-center gap-3.5 lg:hidden"
                        style={{ '--sac-retardo': '60ms' } as CSSProperties}
                    >
                        {/* Decorativo: la sigla va escrita al lado. */}
                        <AppLogoMark
                            className="size-16 shrink-0"
                            aria-hidden="true"
                        />
                        <div className="min-w-0">
                            <p className="text-xl leading-tight font-bold tracking-wide">
                                SAC
                            </p>
                            <p className="truncate text-sm text-muted-foreground">
                                Sistema administrativo contable
                            </p>
                        </div>
                    </div>

                    <div
                        className="sac-entra overflow-hidden rounded-xl border bg-card shadow-sm"
                        style={{ '--sac-retardo': '120ms' } as CSSProperties}
                    >
                        {/* Filete superior: ata la tarjeta al azul del panel. */}
                        <div aria-hidden="true" className="h-1 bg-primary/85" />

                        <div className="p-8 sm:p-10">
                            <div className="mb-8">
                                <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                    Acceso al sistema
                                </p>
                                <h2 className="mt-2 text-2xl font-semibold tracking-tight">
                                    {title}
                                </h2>
                                {description ? (
                                    <p className="mt-1.5 text-sm text-balance text-muted-foreground">
                                        {description}
                                    </p>
                                ) : null}
                            </div>

                            {children}
                        </div>
                    </div>

                    {/* Acá sí va el organismo completo: abajo de lg el
                        membrete no se muestra y este es el único lugar
                        donde se nombra. */}
                    <p className="mt-6 text-center text-xs text-muted-foreground lg:hidden">
                        © {anio} {pie}
                    </p>
                </div>
            </main>
        </div>
    );
}
