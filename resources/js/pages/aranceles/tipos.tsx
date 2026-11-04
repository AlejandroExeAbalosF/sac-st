import { Head } from '@inertiajs/react';
import { Plus, Tags } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import FeeTypeCombobox from '@/features/aranceles/components/fee-type-combobox';
import FeeTypeFormDialog from '@/features/aranceles/components/fee-type-form-dialog';
import FeeTypePricesDialog from '@/features/aranceles/components/fee-type-prices-dialog';
import { feeTypeValue } from '@/features/aranceles/lib/fee-type-value';
import { date } from '@/lib/format';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;

type Props = {
    feeTypes: Tipo[];
    canManage: boolean;
    nextCode: number;
};

/**
 * El catálogo de aranceles: qué se cobra y cuánto vale desde cada fecha.
 *
 * El tipo abierto se guarda por id y se busca en las props: al agregar un precio
 * el servidor devuelve el catálogo otra vez, y la ficha tiene que mostrarlo sin
 * cerrarse.
 */
export default function TiposDeArancel({
    feeTypes,
    canManage,
    nextCode,
}: Props) {
    const [abiertoId, setAbiertoId] = useState<number | null>(null);
    const [formAbierto, setFormAbierto] = useState(false);
    const [editando, setEditando] = useState<Tipo | null>(null);

    const abierto = feeTypes.find((tipo) => tipo.id === abiertoId) ?? null;

    const abrirFormulario = (tipo: Tipo | null) => {
        setEditando(tipo);
        setFormAbierto(true);
    };

    return (
        <>
            <Head title="Tipos de arancel" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Tipos de arancel"
                    description="Lo que se cobra y cuánto vale desde cada fecha. Lo ya emitido no cambia."
                    actions={
                        canManage ? (
                            <Button onClick={() => abrirFormulario(null)}>
                                <Plus className="size-4" aria-hidden="true" />
                                Nuevo tipo
                            </Button>
                        ) : null
                    }
                />

                {feeTypes.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <Tags
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-3 font-medium">
                            Todavía no hay tipos de arancel.
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            El catálogo inicial se carga con el seeder de
                            catálogos.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="grid max-w-xl gap-2">
                            <Label htmlFor="buscar-arancel">
                                Buscar un arancel
                            </Label>
                            <FeeTypeCombobox
                                id="buscar-arancel"
                                types={feeTypes}
                                value={abiertoId}
                                onChange={(tipo) => setAbiertoId(tipo.id)}
                            />
                        </div>

                        <div className="grid gap-3 md:hidden">
                            {feeTypes.map((tipo) => (
                                <article
                                    key={tipo.id}
                                    className="rounded-lg border bg-card p-4 shadow-xs"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <h2 className="font-medium">
                                            {tipo.code} · {tipo.name}
                                        </h2>
                                        {!tipo.isActive && (
                                            <StatusBadge label="Inactivo" />
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm tabular-nums">
                                        <Valor tipo={tipo} />
                                    </p>
                                    <Button
                                        variant="outline"
                                        className="mt-3 min-h-11 w-full"
                                        onClick={() => setAbiertoId(tipo.id)}
                                    >
                                        Ver precios
                                    </Button>
                                </article>
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-lg border md:block">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Cód.
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Inciso
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Concepto
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Valor vigente
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Desde
                                        </th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {feeTypes.map((tipo) => (
                                        <tr key={tipo.id} className="border-t">
                                            <td className="p-3 text-right tabular-nums">
                                                {tipo.code}
                                            </td>
                                            <td className="p-3">
                                                {tipo.decreeLetter ?? '—'}
                                            </td>
                                            <td className="p-3">
                                                <span>{tipo.name}</span>
                                                {!tipo.isActive && (
                                                    <StatusBadge
                                                        label="Inactivo"
                                                        className="ml-2"
                                                    />
                                                )}
                                            </td>
                                            <td className="p-3 text-right whitespace-nowrap tabular-nums">
                                                <Valor tipo={tipo} />
                                            </td>
                                            <td className="p-3 whitespace-nowrap tabular-nums">
                                                {tipo.currentPrice
                                                    ? date(
                                                          tipo.currentPrice
                                                              .validFrom,
                                                      )
                                                    : '—'}
                                            </td>
                                            <td className="p-3 text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setAbiertoId(tipo.id)
                                                    }
                                                >
                                                    Ver precios
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </div>

            <FeeTypePricesDialog
                tipo={abierto}
                onClose={() => setAbiertoId(null)}
                canManage={canManage}
                onEdit={abrirFormulario}
            />

            <FeeTypeFormDialog
                open={formAbierto}
                onOpenChange={setFormAbierto}
                tipo={editando}
                nextCode={nextCode}
            />
        </>
    );
}

function Valor({ tipo }: { tipo: Tipo }) {
    const valor = feeTypeValue(tipo.calculationMode, tipo.currentPrice);

    return (
        <>
            {valor}
            {tipo.unitLabel && (
                <span className="text-muted-foreground">
                    {' '}
                    por {tipo.unitLabel}
                </span>
            )}
        </>
    );
}
