import { usePage } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

/*
 * Las medidas del papel a 96 dpi.
 *
 * El ancho es el mismo para todos los documentos —A4 vertical y A5
 * apaisado comparten los 794 px— y el alto no: la Orden y el Pase ocupan
 * la hoja entera, los recibos media, que es el papel del talonario.
 *
 * **Todos redondean hacia arriba.** Los 148 mm del A5 son 559,37 px, y
 * quedarse corto saca una barra vertical, la barra angosta el ancho útil,
 * y el ancho angostado saca la horizontal.
 */
export const HOJA_ANCHO = 794;
export const HOJA_ALTO_A4 = 1123;
export const HOJA_ALTO_A5 = 560;

/**
 * Si el navegador sabe achicar re-maquetando en vez de escalar la imagen.
 *
 * `zoom` es lo que se quiere —ver el porqué abajo, en el marco— pero es
 * reciente en Firefox. Sin soporte se cae a `transform`, que dibuja peor
 * pero dibuja: sin ninguno de los dos, la hoja saldría a tamaño completo y
 * recortada, que es bastante peor que un trazo fino perdido.
 */
const SOPORTA_ZOOM =
    typeof CSS !== 'undefined' &&
    typeof CSS.supports === 'function' &&
    CSS.supports('zoom', '0.5');

/**
 * La hoja adentro de su marco, escalada al ancho disponible.
 *
 * **Es el único lugar del sistema donde se arma un `<iframe>` de
 * documento.** No es prolijidad: enmarcar un documento exige que su ruta
 * esté declarada en `security.headers.same_origin_frame_routes`, y esa
 * condición vive en un archivo de PHP que ningún test del front puede
 * mirar. Tres veces se agregó un visor sin declarar la ruta, y las tres el
 * síntoma fue el mismo —un rectángulo en blanco, cero errores del lado del
 * servidor— porque el navegador descarta la respuesta sin avisarle a
 * nadie.
 *
 * Concentrarlo acá permite que el aviso exista: este componente coteja la
 * dirección contra lo que el servidor declara y falla fuerte en desarrollo
 * en vez de dibujar el vacío.
 */
export default function DocumentFrame({
    url,
    titulo,
    hojaAlto = HOJA_ALTO_A4,
    className,
    maxAlto,
    creceHasta,
    onCargado,
    sinInteraccion = false,
}: {
    url: string;
    /** Va al `title` del marco: es lo que anuncia el lector de pantalla. */
    titulo: string;
    /** `HOJA_ALTO_A4` para la Orden y el Pase, `HOJA_ALTO_A5` para los recibos. */
    hojaAlto?: number;
    className?: string;
    /**
     * Hasta dónde puede crecer la hoja, en unidades CSS, con barra si no
     * entra.
     *
     * **Va explícito y no por flex.** `DialogContent` es `position: fixed`
     * sin altura propia, así que un `flex-1` adentro no tiene contra qué
     * resolver: la hoja crecía hasta su tamaño real y se desbordaba por
     * abajo del diálogo. Un tope en `vh` no depende de que la cadena de
     * padres tenga altura definida.
     */
    maxAlto?: string;
    /**
     * Hasta qué alto puede crecer la hoja, en unidades CSS.
     *
     * **Se aplica como un tope de ancho, y eso no es un rodeo.** La escala
     * sale del ancho medido —la hoja llena la columna que le toca— así que
     * limitar el alto directamente no serviría de nada: la hoja seguiría
     * creciendo a lo ancho y el tope de alto la recortaría. Angostándola en
     * la proporción del papel, el alto sale solo.
     *
     * Sirve para un visor dentro de un diálogo, donde la hoja compite con
     * el encabezado y el pie por el alto de la ventana. Sin esto, un
     * diálogo ancho da una hoja grande que no entra, y el diálogo entero se
     * pone a scrollear: se gana tamaño y se pierde el comprobante de vista.
     */
    creceHasta?: string;
    onCargado?: () => void;
    /** Al lado de un formulario, el marco no debe robar el foco ni el clic. */
    sinInteraccion?: boolean;
}) {
    const [escala, setEscala] = useState(1);
    const [bloqueado, setBloqueado] = useState(false);
    const observador = useRef<ResizeObserver | null>(null);

    useDocumentoDeclarado(url);

    const medirElMarco = useCallback((nodo: HTMLDivElement | null) => {
        observador.current?.disconnect();

        if (nodo === null) {
            return;
        }

        observador.current = new ResizeObserver(([entrada]) => {
            /*
             * Una medición en cero no se toma, y no es defensa de más: la
             * caja de afuera se encoge a su contenido en los diálogos, y su
             * contenido es esta caja, cuyo ancho sale de la escala. Aceptar
             * un cero cerraría el círculo en cero y la hoja no volvería a
             * crecer nunca. Con el valor anterior, el siguiente cuadro
             * corrige.
             */
            if (entrada.contentRect.width > 0) {
                setEscala(entrada.contentRect.width / HOJA_ANCHO);
            }
        });

        observador.current.observe(nodo);
    }, []);

    /*
     * Si el navegador bloqueó el marco, `load` dispara igual: lo que carga
     * es su propia pantalla de error, que es de otro origen. Por eso el
     * bloqueo se reconoce en que el documento no se deja mirar, y no en
     * que esté vacío —una hoja todavía sin pintar también lo estaría—.
     */
    const alCargar = useCallback(
        (evento: React.SyntheticEvent<HTMLIFrameElement>) => {
            let accesible = false;

            try {
                accesible = evento.currentTarget.contentDocument !== null;
            } catch {
                accesible = false;
            }

            setBloqueado(!accesible);

            if (accesible) {
                onCargado?.();
            }
        },
        [onCargado],
    );

    return (
        <div
            ref={medirElMarco}
            className={cn(
                'rounded-lg border bg-white',
                maxAlto === undefined ? 'overflow-hidden' : 'overflow-auto',
                // Angostada por el tope, la hoja se queda en el medio de lo
                // que le tocaba en vez de pegarse al borde izquierdo.
                creceHasta === undefined ? undefined : 'mx-auto w-full',
                className,
            )}
            style={{
                ...(maxAlto === undefined ? {} : { maxHeight: maxAlto }),
                ...(creceHasta === undefined
                    ? {}
                    : {
                          maxWidth: `calc(${creceHasta} * ${HOJA_ANCHO} / ${hojaAlto})`,
                      }),
            }}
        >
            {/*
             * La caja declara el tamaño ya reducido y recorta lo que
             * sobre. Con `zoom` el marco ocupa lo que mide, así que no
             * debería sobrar nada; el recorte queda como red por si el
             * navegador no lo soporta y lo dibuja a tamaño completo.
             */}
            <div
                className="relative"
                style={{
                    width: HOJA_ANCHO * escala,
                    height: hojaAlto * escala,
                    overflow: 'hidden',
                }}
            >
                <iframe
                    key={url}
                    src={url}
                    title={titulo}
                    tabIndex={sinInteraccion ? -1 : undefined}
                    onLoad={alCargar}
                    style={{
                        width: HOJA_ANCHO,
                        height: hojaAlto,
                        /*
                         * `zoom` antes que `transform: scale()`.
                         *
                         * Los dos achican la hoja, pero `transform` la
                         * dibuja a tamaño completo y después escala esa
                         * imagen: los trazos de un cuarto de milímetro
                         * quedan en fracciones de píxel y el navegador se
                         * come algunos. En el formulario de la Orden el
                         * recuadro del importe aparecía sin su base —que es
                         * la misma línea que separa las franjas— y en el
                         * papel estaba.
                         *
                         * `zoom` rehace la maquetación al tamaño chico, así
                         * que cada línea cae sobre píxeles enteros y
                         * ninguna desaparece.
                         */
                        ...(SOPORTA_ZOOM
                            ? { zoom: escala }
                            : {
                                  transform: `scale(${escala})`,
                                  transformOrigin: 'top left',
                              }),
                        pointerEvents: sinInteraccion ? 'none' : undefined,
                    }}
                />

                {bloqueado && <MarcoBloqueado url={url} />}
            </div>
        </div>
    );
}

/**
 * Lo que se ve cuando el navegador descartó el documento.
 *
 * Antes quedaba el cartel del navegador —«no se puede abrir esta página si
 * otro sitio la ha incrustado»—, que no dice qué hacer ni a quién
 * avisarle. Esto tampoco arregla nada, pero nombra la causa: el que lo lee
 * puede reportarlo en vez de pensar que el comprobante salió mal.
 */
function MarcoBloqueado({ url }: { url: string }) {
    return (
        <div className="absolute inset-0 flex items-center justify-center bg-card p-6">
            <p className="flex max-w-sm items-start gap-2 text-sm text-destructive-strong">
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    El navegador no dejó mostrar el documento acá. Se puede
                    abrir en una pestaña; si pasa siempre, avisá que{' '}
                    <span className="font-mono text-xs break-all">{url}</span>{' '}
                    no está declarada como enmarcable.
                </span>
            </p>
        </div>
    );
}

/**
 * Avisa, al escribir el código, cuando el documento no está declarado.
 *
 * El servidor comparte las plantillas de las rutas que sus cabeceras
 * dejan enmarcar. Si la dirección no coincide con ninguna, el navegador va
 * a descartarla: eso acá es un error de programación, no una condición de
 * ejecución, y por eso revienta en desarrollo en lugar de degradarse.
 *
 * En producción no tira: un comprobante que no se puede mirar es un
 * problema, pero tumbar la pantalla entera del operador es peor. Ahí queda
 * el aviso del marco.
 */
function useDocumentoDeclarado(url: string): void {
    const { framableDocuments } = usePage().props as unknown as {
        framableDocuments?: string[];
    };

    if (import.meta.env.PROD || framableDocuments === undefined) {
        return;
    }

    const ruta = url.split('?')[0] ?? url;

    const declarado = framableDocuments.some((plantilla) =>
        new RegExp(`^/?${plantilla.replace(/\{[^}]+\}/g, '[^/]+')}/?$`).test(
            ruta,
        ),
    );

    if (!declarado) {
        throw new Error(
            `«${ruta}» no está declarada como enmarcable, así que el navegador ` +
                'va a descartarla y el visor va a quedar en blanco. Agregá el ' +
                'nombre de su ruta a `same_origin_frame_routes`, en ' +
                'config/security.php.',
        );
    }
}
