import {
    createContext,
    useCallback,
    useContext,
    useMemo,
    useState,
} from 'react';
import type { ReactNode } from 'react';
import type { HistorySubject } from '@/features/haberes/components/history-panel';

/**
 * Qué está mirando el panel lateral.
 *
 * Es una unión discriminada: sumar un sujeto es agregar una variante acá y
 * una entrada en el host; nada más se entera.
 *
 * El historial es **una sola variante para los tres sujetos** y no tres
 * parecidas. Cuota, haber y expediente se piden igual y se dibujan igual;
 * lo único que cambia es a qué dirección ir, y eso el panel lo resuelve
 * con `subject`.
 *
 * El rótulo viaja acá en vez de pedirse al servidor porque quien abre el
 * panel siempre lo tiene a mano: hacer una consulta para escribir un
 * encabezado sería pagar dos veces por el mismo dato.
 */
export type DrawerSubject =
    | { kind: 'receipt'; id: number }
    | {
          kind: 'history';
          subject: HistorySubject;
          /** La dirección del historial, armada con el helper tipado. */
          url: string;
          /** Cómo sigue el título: «del expediente 125959/2026», «de la cuota 1». */
          label: string;
      };

type DrawerContextValue = {
    subject: DrawerSubject | null;
    open: boolean;
    openDrawer: (subject: DrawerSubject) => void;
    closeDrawer: () => void;
};

const DrawerContext = createContext<DrawerContextValue | null>(null);

/**
 * El panel lateral, disponible desde cualquier pantalla.
 *
 * Se monta en `withApp`, por fuera del switch de layouts, que es donde ya
 * vive el `Toaster`: ahí sobrevive a las navegaciones de Inertia en lugar
 * de remontarse con cada pantalla.
 *
 * El estado es deliberadamente mínimo —qué sujeto y si está abierto— y no
 * guarda los datos del sujeto. Es lo que hace que abrir el panel vuelva a
 * pedirlos: un panel que sobrevive a la navegación y **además** cachea lo
 * que trajo termina mostrando un haber activo que se anuló hace dos
 * pantallas.
 */
export function DrawerProvider({ children }: { children: ReactNode }) {
    const [subject, setSubject] = useState<DrawerSubject | null>(null);
    const [open, setOpen] = useState(false);

    const openDrawer = useCallback((next: DrawerSubject) => {
        setSubject(next);
        setOpen(true);
    }, []);

    /*
     * El sujeto no se borra al cerrar: Radix necesita que el contenido
     * siga dibujado mientras dura la animación de salida, y vaciarlo acá
     * deja el panel en blanco justo mientras se va.
     */
    const closeDrawer = useCallback(() => setOpen(false), []);

    const value = useMemo(
        () => ({ subject, open, openDrawer, closeDrawer }),
        [subject, open, openDrawer, closeDrawer],
    );

    return (
        <DrawerContext.Provider value={value}>
            {children}
        </DrawerContext.Provider>
    );
}

export function useDrawer(): DrawerContextValue {
    const contexto = useContext(DrawerContext);

    if (contexto === null) {
        throw new Error('useDrawer necesita estar dentro de <DrawerProvider>.');
    }

    return contexto;
}
