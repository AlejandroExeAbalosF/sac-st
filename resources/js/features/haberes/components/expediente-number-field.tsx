import { CircleCheck, Loader2 } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const COMPLETO = /^(\d{3})(\d{4})-(\d{1,6})\/(\d{4})-(\d{1,2})$/;
const CORTO = /^(\d{1,6})\/(\d{4})$/;

/** En qué punto de la verificación está el número que se está escribiendo. */
export type EstadoNumero = 'vacio' | 'verificando' | 'disponible' | 'existente';

/**
 * Descompone el número de SiCE para mostrarlo mientras se escribe.
 *
 * Es **solo presentación**: la validación y la normalización las hace
 * `App\Modules\Haberes\Support\ExpedienteNumber` en el servidor, que es
 * la autoridad sobre el formato. Acá se replica el patrón para dar
 * respuesta inmediata, no para decidir nada.
 */
export function descomponer(raw: string) {
    const valor = raw.trim();

    const completo = COMPLETO.exec(valor);

    if (completo) {
        return {
            cds: completo[1],
            iud: completo[2],
            numero: completo[3],
            periodo: completo[4],
            instancia: completo[5],
            corto: `${completo[3]}/${completo[4]}`,
        };
    }

    const corto = CORTO.exec(valor);

    if (corto) {
        return {
            cds: '003',
            iud: '0064',
            numero: corto[1],
            periodo: corto[2],
            instancia: '0',
            corto: `${corto[1]}/${corto[2]}`,
        };
    }

    return null;
}

type Props = {
    value: string;
    onChange: (value: string) => void;
    estado: EstadoNumero;
    error?: string;
};

export default function ExpedienteNumberField({
    value,
    onChange,
    estado,
    error,
}: Props) {
    const partes = descomponer(value);

    return (
        <div>
            <Label htmlFor="number">Número de expediente</Label>
            <Input
                id="number"
                name="number"
                className="mt-1 font-mono tabular-nums"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                aria-invalid={Boolean(error) || estado === 'existente'}
                aria-describedby="number-estado"
                autoComplete="off"
                placeholder="0030064-125957/2026-0"
            />

            {/*
             * La verificación corre sola al terminar de escribir. Un botón
             * que hay que acordarse de apretar es un control que el día
             * apurado no se hace, y el duplicado entra igual.
             */}
            {/*
             * El caso «ya está cargado» no se anuncia acá: lo dice la
             * tarjeta que sale justo debajo, que además muestra cuál es y
             * ofrece abrirlo. Dos veces la misma frase, una arriba de la
             * otra, no avisa el doble.
             */}
            <div id="number-estado" aria-live="polite" className="mt-1.5">
                {estado === 'verificando' && (
                    <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Loader2
                            className="size-3 animate-spin"
                            aria-hidden="true"
                        />
                        Verificando si ya está cargado…
                    </p>
                )}

                {estado === 'disponible' && (
                    <p className="flex items-center gap-1.5 text-xs text-success-strong">
                        <CircleCheck className="size-3" aria-hidden="true" />
                        Disponible, no está cargado.
                    </p>
                )}

                {estado === 'vacio' &&
                    (partes ? (
                        <dl className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                            <div className="flex gap-1">
                                <dt className="text-field-label">N°</dt>
                                <dd className="font-mono text-foreground">
                                    {partes.numero}
                                </dd>
                            </div>
                            <div className="flex gap-1">
                                <dt className="text-field-label">Período</dt>
                                <dd className="font-mono text-foreground">
                                    {partes.periodo}
                                </dd>
                            </div>
                            <div className="flex gap-1">
                                <dt className="text-field-label">Inst.</dt>
                                <dd className="font-mono text-foreground">
                                    {partes.instancia}
                                </dd>
                            </div>
                        </dl>
                    ) : (
                        <p className="text-xs text-muted-foreground">
                            Completo{' '}
                            <span className="font-mono">
                                0030064-125957/2026-0
                            </span>{' '}
                            o corto{' '}
                            <span className="font-mono">125957/2026</span>.
                        </p>
                    ))}
            </div>

            {error && (
                <p className="mt-1 text-xs text-destructive-strong">{error}</p>
            )}
        </div>
    );
}
