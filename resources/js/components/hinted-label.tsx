import { Info } from 'lucide-react';
import { useRef, useState } from 'react';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';

type Props = {
    htmlFor: string;
    /** Qué se acepta en el campo. */
    hint: React.ReactNode;
    children: React.ReactNode;
};

/**
 * Etiqueta con un globo que explica qué acepta el campo.
 *
 * Se abre al pasar el mouse por encima y también **al tocarlo**, que es lo
 * que un globo no hace solo: sin puntero no hay hover, y sin esto el ícono
 * sería decorativo en un teléfono.
 *
 * El toque no pelea con Radix, que cierra el globo al apretar y otra vez al
 * soltar: se deja que cierre y se fija el estado que corresponde **después**
 * de que terminó el toque completo. Intentar ganarle cancelando el evento
 * funcionaba para el primer toque y dejaba el ícono muerto a partir del
 * segundo.
 */
export default function HintedLabel({ htmlFor, hint, children }: Props) {
    const [abierto, setAbierto] = useState(false);
    /** Cómo estaba el globo cuando empezó el toque, y si hubo toque. */
    const alTocar = useRef<boolean | null>(null);

    return (
        <div className="flex items-center gap-1">
            <Label htmlFor={htmlFor}>{children}</Label>

            <Tooltip open={abierto} onOpenChange={setAbierto}>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        aria-label="Qué se acepta en este campo"
                        onPointerDown={(event) => {
                            alTocar.current =
                                event.pointerType === 'mouse' ? null : abierto;
                        }}
                        onClick={() => {
                            if (alTocar.current === null) {
                                return;
                            }

                            const objetivo = !alTocar.current;
                            alTocar.current = null;

                            // Después del toque, no en medio: para entonces
                            // Radix ya hizo sus cierres.
                            window.setTimeout(() => setAbierto(objetivo), 0);
                        }}
                        className="grid size-5 place-items-center rounded-full text-muted-foreground transition-colors duration-150 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <Info className="size-3.5" aria-hidden="true" />
                    </button>
                </TooltipTrigger>

                <TooltipContent className="max-w-64 leading-relaxed">
                    {hint}
                </TooltipContent>
            </Tooltip>
        </div>
    );
}
