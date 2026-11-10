import { Link } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { rutasDeCaja } from '@/features/caja/rutas';
import type { CodigoDeCaja } from '@/features/caja/rutas';

/** La caja sin apertura, como la manda el servidor. */
export type CajaPendiente = {
    /** Si quien mira puede resolverlo: si no, no se le ofrece un enlace a un 403. */
    canFix: boolean;
};

const NOMBRES: Record<CodigoDeCaja, string> = {
    haberes: 'Haberes',
    aranceles: 'Aranceles',
    multas: 'Multas',
};

/**
 * Por qué falta un botón que depende de la caja.
 *
 * Sin apertura cobrar, depositar o verificar fallaría, y la pantalla no lo
 * ofrece. Este aviso lo dice y, a quien puede, lo lleva a abrirla; sin él
 * el botón simplemente no estaba, sin explicación.
 */
export default function CashBoxNotReady({
    caja,
    pendiente,
    consecuencia,
}: {
    caja: CodigoDeCaja;
    pendiente: CajaPendiente;
    /** Qué no se puede hacer mientras tanto, como frase completa. */
    consecuencia: string;
}) {
    const destino = rutasDeCaja(caja).apertura();

    return (
        <div
            role="status"
            className="flex items-start gap-2 rounded-md border border-warning-strong/30 bg-warning-soft p-3 text-sm text-warning-strong"
        >
            <BookOpen className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p className="min-w-0">
                <span className="font-medium">
                    La caja de {NOMBRES[caja]} todavía no se abrió.
                </span>{' '}
                {consecuencia}{' '}
                {pendiente.canFix ? (
                    <Link
                        href={destino.url}
                        className="font-medium underline underline-offset-2"
                    >
                        Abrir la caja
                    </Link>
                ) : (
                    'Pedíselo a un administrador.'
                )}
            </p>
        </div>
    );
}
