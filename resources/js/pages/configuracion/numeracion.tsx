import { Head, router, useForm } from '@inertiajs/react';
import FormError from '@/components/form-error';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { update } from '@/routes/configuracion/numeracion';

type Series = {
    id: number;
    type: string;
    label: string;
    active: boolean;
    prefix: string;
    prefixDigits: number;
    numberDigits: number;
    separator: string;
    nextNumber: number;
    preview: string;
    token: string;
    lastNumber: number | null;
};

export default function Numeracion({ series }: { series: Series[] }) {
    return (
        <>
            <Head title="Numeración de comprobantes" />
            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Configuración"
                    title="Numeración de comprobantes"
                    description="Definí cómo se identificarán los próximos recibos y órdenes de pago."
                />
                <p className="max-w-3xl text-sm text-muted-foreground">
                    Los documentos emitidos conservan su número. Cambiar un
                    formato ya utilizado crea una nueva versión de la serie. El
                    número del talonario se sigue cargando por separado.
                </p>
                {series
                    .filter((s) => s.active)
                    .map((s) => (
                        <SeriesForm
                            key={s.token}
                            series={s}
                            history={series.filter(
                                (h) => h.type === s.type && !h.active,
                            )}
                        />
                    ))}
            </div>
        </>
    );
}

function SeriesForm({
    series,
    history,
}: {
    series: Series;
    history: Series[];
}) {
    const form = useForm({
        prefix: series.prefix,
        prefixDigits: String(series.prefixDigits),
        numberDigits: String(series.numberDigits),
        separator: series.separator,
        nextNumber: String(series.nextNumber),
        reason: '',
        token: series.token,
    });
    const { data, errors, setData } = form;
    const preview =
        data.prefix.padStart(
            Math.min(12, Math.max(1, Number(data.prefixDigits) || 1)),
            '0',
        ) +
        data.separator +
        data.nextNumber.padStart(
            Math.min(12, Math.max(1, Number(data.numberDigits) || 1)),
            '0',
        );
    const fields = [
        { key: 'prefix', label: 'Prefijo', maxLength: 12, type: 'text' },
        {
            key: 'prefixDigits',
            label: 'Dígitos del prefijo',
            max: 12,
            type: 'number',
        },
        {
            key: 'numberDigits',
            label: 'Dígitos del correlativo',
            max: 12,
            type: 'number',
        },
        {
            key: 'nextNumber',
            label: 'Próximo número',
            max: 999999999999,
            type: 'number',
        },
    ] as const;

    return (
        <section
            className="rounded-xl border bg-card p-4 sm:p-6"
            aria-labelledby={`series-${series.id}`}
        >
            <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2
                        id={`series-${series.id}`}
                        className="text-lg font-semibold"
                    >
                        {series.label}
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {series.lastNumber === null
                            ? 'Todavía no se emitieron comprobantes con esta versión.'
                            : `Último correlativo utilizado en esta versión: ${series.lastNumber}.`}
                    </p>
                </div>
                <div className="min-w-0 rounded-lg bg-muted px-4 py-3">
                    <p className="text-xs text-muted-foreground">
                        Vista previa del próximo comprobante
                    </p>
                    <output
                        className="mt-1 block font-mono text-lg font-semibold break-all"
                        aria-live="polite"
                    >
                        {preview}
                    </output>
                </div>
            </div>
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(update(series.type).url, { preserveScroll: true });
                }}
            >
                <FormError message={errors.token} />
                {errors.token && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => router.reload()}
                    >
                        Recargar configuración
                    </Button>
                )}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    {fields.map((field) => (
                        <div className="space-y-2" key={field.key}>
                            <Label htmlFor={`${series.id}-${field.key}`}>
                                {field.label}
                            </Label>
                            <Input
                                id={`${series.id}-${field.key}`}
                                type={field.type}
                                inputMode="numeric"
                                min={field.type === 'number' ? 1 : undefined}
                                max={'max' in field ? field.max : undefined}
                                maxLength={
                                    'maxLength' in field
                                        ? field.maxLength
                                        : undefined
                                }
                                required
                                value={data[field.key]}
                                onChange={(event) =>
                                    setData(field.key, event.target.value)
                                }
                                aria-invalid={Boolean(errors[field.key])}
                            />
                            <InputError message={errors[field.key]} />
                        </div>
                    ))}
                    <div className="space-y-2">
                        <Label htmlFor={`${series.id}-separator`}>
                            Separador
                        </Label>
                        <Select
                            value={data.separator}
                            onValueChange={(value) =>
                                setData('separator', value)
                            }
                        >
                            <SelectTrigger id={`${series.id}-separator`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="/">Barra (/)</SelectItem>
                                <SelectItem value="-">Guion (-)</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.separator} />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    Los dígitos indican el mínimo de posiciones: se completan
                    con ceros y nunca se recortan números. Cambiar el prefijo no
                    reinicia el correlativo; indicá el próximo número que querés
                    utilizar.
                </p>
                <div className="max-w-2xl space-y-2">
                    <Label htmlFor={`${series.id}-reason`}>
                        Motivo del cambio
                    </Label>
                    <Textarea
                        id={`${series.id}-reason`}
                        required
                        minLength={5}
                        maxLength={500}
                        value={data.reason}
                        onChange={(event) =>
                            setData('reason', event.target.value)
                        }
                        placeholder="Por ejemplo: adecuación a la numeración del área."
                    />
                    <InputError message={errors.reason} />
                </div>
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                >
                    {form.processing ? 'Guardando…' : 'Guardar numeración'}
                </Button>
            </form>
            {history.length > 0 && (
                <details className="mt-5 border-t pt-4">
                    <summary className="cursor-pointer text-sm font-medium">
                        Versiones anteriores ({history.length})
                    </summary>
                    <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
                        {history.map((s) => (
                            <li key={s.id}>
                                Prefijo {s.prefix.padStart(s.prefixDigits, '0')}{' '}
                                · Separador {s.separator} · {s.numberDigits}{' '}
                                dígitos · Último correlativo:{' '}
                                {s.lastNumber ?? 'sin emisiones'}
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
