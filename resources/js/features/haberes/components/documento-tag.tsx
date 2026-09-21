import { cn } from '@/lib/utils';

/** En cuál de los dos papeles cae lo que se está cargando. */
export type EnQuePapel = 'orden' | 'pase' | 'ambos';

const ETIQUETA: Record<EnQuePapel, string> = {
    orden: 'Orden de Pago',
    pase: 'Nota de Pase',
    ambos: 'Los dos papeles',
};

const ESTILO: Record<EnQuePapel, string> = {
    orden: 'bg-primary/10 text-primary',
    pase: 'bg-accent-foreground/10 text-accent-foreground',
    ambos: 'bg-muted text-muted-foreground',
};

/**
 * Dice en qué papel se imprime lo que este bloque carga.
 *
 * **Hace falta porque los dos documentos se llenan en la misma pantalla y
 * no se parecen en nada.** El caso que lo hizo evidente es la foja del
 * CBU: se cargaba junto a la cuenta bancaria del beneficiario, y ahí no
 * significa lo que parece —la Orden ni siquiera imprime el CBU—. Termina
 * escrita en los dos papeles, pero de maneras distintas: redacta la cita
 * de la nota, «a la CBU informada en fs. 19 del Expte.», y el renglón OBS
 * de la Orden. Sin el rótulo, quien la completa no tiene cómo saberlo.
 */
export default function DocumentoTag({ papel }: { papel: EnQuePapel }) {
    return (
        <span
            className={cn(
                'rounded px-1.5 py-0.5 text-[0.65rem] font-medium tracking-normal normal-case',
                ESTILO[papel],
            )}
        >
            {ETIQUETA[papel]}
        </span>
    );
}
