import { Head, useForm, usePage } from '@inertiajs/react';
import { BadgeCheck, Eye, FileSignature, TriangleAlert } from 'lucide-react';
import { useMemo, useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { DocumentoParaVer } from '@/features/haberes/components/document-preview';
import DocumentViewerDialog from '@/features/haberes/components/document-viewer-dialog';
import {
    Campo,
    CuentasDelBeneficiario,
    FojaEnLosPapeles,
    Opcion,
    Seccion,
    TablaDeDepositos,
} from '@/features/haberes/components/order-form-fields';
import { order as emitirOrden } from '@/routes/haberes/installments';
import {
    complete as guardarDatos,
    preview as previsualizar,
} from '@/routes/haberes/installments/order';
import { print as imprimirBorrador } from '@/routes/haberes/installments/order/preview';
import { preview as previsualizarPase } from '@/routes/haberes/installments/pase';
import { print as imprimirBorradorPase } from '@/routes/haberes/installments/pase/preview';

type Estado = App.Modules.Haberes.Data.InstallmentOrderStateData;
type Contexto = App.Modules.Haberes.Data.PaymentOrderContextData;

type Props = {
    cuota: {
        id: number;
        number: number;
        concept: string | null;
        amount: string;
    };
    haber: { id: number; beneficiaryName: string };
    expediente: { id: number; displayNumber: string };
    estado: Estado;
    contexto: Contexto;
    puedeVerificarCbu: boolean;
    puedeForzarCbu: boolean;
};

/**
 * La pantalla que arma la Orden de Pago y su nota de Pase.
 *
 * **Era un modal y no daba.** Acá se completan datos de dos personas y se
 * verifica un CBU contra la foja del expediente; eso no entra en un
 * diálogo sin apretarlo hasta volverlo incómodo.
 *
 * El borrador, en cambio, sí vive en un diálogo: se mira de una vez
 * cuando el formulario ya está completo, no mientras se tipea. Al costado
 * le comía a los campos el ancho que necesitan y pedía las dos hojas de
 * nuevo por cada tecla.
 *
 * **Dos botones y no uno.** Completar el maestro es un acto aparte de
 * emitir: el domicilio que se acaba de tipear es cierto aunque la Orden
 * después se rechace, y meterlo en la misma transacción obligaría a
 * escribirlo de nuevo.
 */
/*
 * `haber` y `expediente` siguen llegando del controlador, pero ya no se
 * desestructuran: el beneficiario y el número de expediente los dice el camino
 * de migas del encabezado, que es donde vive la ubicación de la pantalla.
 */
export default function OrdenCreate({
    cuota,
    estado,
    contexto,
    puedeVerificarCbu,
    puedeForzarCbu,
}: Props) {
    const datos = useForm({
        beneficiaryDocument: contexto.beneficiaryDocument ?? '',
        beneficiaryAddress: contexto.beneficiaryAddress ?? '',
        beneficiaryPhone: contexto.beneficiaryPhone ?? '',
        employerDocument: contexto.employerDocument ?? '',
        employerAddress: contexto.employerAddress ?? '',
        employerPhone: contexto.employerPhone ?? '',
        custodyStartDate: contexto.custodyStartDate ?? '',
    });

    const verificadas = contexto.accounts.filter(
        (cuenta) => cuenta.verificationStatus === 'verified',
    );

    const emitir = useForm({
        beneficiaryBankAccountId: verificadas[0]?.id ?? null,
        cbuFolio: '',
        /*
         * Se propone el mismo criterio con el que se emitió el recibo. Que
         * los dos documentos de la misma cuota se refieran al comprobante
         * de maneras distintas es lo que hace que después nadie los cruce.
         */
        incomeReceiptNumberSource: (estado.incomeReceiptPrintsTalonario
            ? 'talonario'
            : 'system') as App.Modules.Haberes.Enums.ReceiptNumberSource,
        paseDestination: contexto.defaultPaseDestination,
        treasurerId: null as number | null,
    });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    /** Lo que falta, agrupado por el bloque donde se completa. */
    const faltantes = useMemo(() => {
        const porSeccion: Record<string, Estado['missing']> = {};

        for (const campo of estado.missing) {
            (porSeccion[campo.section] ??= []).push(campo);
        }

        return porSeccion;
    }, [estado.missing]);
    const faltantesObligatorios = estado.missing.filter(
        (campo) => campo.required,
    );
    const nombresDeSeccion: Record<string, string> = {
        beneficiary: 'Beneficiario',
        account: 'Cuenta del beneficiario',
        employer: 'Empleador',
        expediente: 'Expediente',
        organism: 'Origen del dinero',
    };

    /**
     * Las dos hojas del borrador, con lo elegido hasta este momento.
     *
     * Van las dos porque viajan juntas: quien firma tiene que poder leer la
     * nota antes de que salga, no enterarse después de lo que decía.
     */
    const borradores = useMemo((): DocumentoParaVer[] => {
        const params = new URLSearchParams({
            incomeReceiptNumberSource: emitir.data.incomeReceiptNumberSource,
            paseDestination: emitir.data.paseDestination,
        });

        if (emitir.data.beneficiaryBankAccountId !== null) {
            params.set(
                'beneficiaryBankAccountId',
                String(emitir.data.beneficiaryBankAccountId),
            );
        }

        if (emitir.data.cbuFolio !== '') {
            params.set('cbuFolio', emitir.data.cbuFolio);
        }

        const query = params.toString();

        /*
         * Dos URLs por hoja, y no la misma repetida. La de vista devuelve
         * HTML, que es lo que el visor muestra dentro del iframe; la de
         * impresión devuelve el PDF, que es lo que abre el lector del
         * navegador y llega al papel.
         *
         * Iban las dos a la misma ruta y por eso «Imprimir» no imprimía:
         * abría otra vez la hoja en HTML.
         */
        return [
            {
                etiqueta: 'Orden de Pago',
                urlVista: `${previsualizar(cuota.id).url}?${query}`,
                urlImpresion: `${imprimirBorrador(cuota.id).url}?${query}`,
            },
            {
                etiqueta: 'Nota de Pase',
                urlVista: `${previsualizarPase(cuota.id).url}?${query}`,
                urlImpresion: `${imprimirBorradorPase(cuota.id).url}?${query}`,
            },
        ];
    }, [cuota.id, emitir.data]);

    const [viendo, setViendo] = useState(false);

    /**
     * Qué hojas del borrador se vieron, y de qué versión del papel.
     *
     * **Las dos hojas, y no cualquiera.** Antes alcanzaba con que cargara
     * una: como el visor arranca en la Orden, se podía emitir el Pase sin
     * haberlo leído nunca, que es justo lo que el cartel decía exigir. La
     * etiqueta siempre viajó en `onCargado`; lo que faltaba era usarla.
     *
     * La firma es de lo que el papel imprime. Si cambia, lo revisado ya no
     * es lo que va a salir y hay que mirarlo de nuevo.
     */
    const [revisado, setRevisado] = useState<{
        firma: string;
        hojas: string[];
    }>({ firma: '', hojas: [] });

    const firmaDelBorrador = JSON.stringify({
        eleccion: emitir.data,
        depositos: estado.deposits,
        total: estado.depositsTotal,
        cuentaOrganismo: estado.organismAccountLabel,
        cuentasBeneficiario: contexto.accounts,
    });

    const marcarHojaVista = (etiqueta: string) => {
        setRevisado((previa) => {
            if (previa.firma !== firmaDelBorrador) {
                return { firma: firmaDelBorrador, hojas: [etiqueta] };
            }

            return previa.hojas.includes(etiqueta)
                ? previa
                : { ...previa, hojas: [...previa.hojas, etiqueta] };
        });
    };

    const hojasPendientes = borradores
        .filter(
            (hoja) =>
                revisado.firma !== firmaDelBorrador ||
                !revisado.hojas.includes(hoja.etiqueta),
        )
        .map((hoja) => hoja.etiqueta);

    const borradorAlDia = hojasPendientes.length === 0;
    const puedePrevisualizar = !datos.processing && !datos.isDirty;
    const puedeGenerar =
        estado.canIssue && !datos.processing && !datos.isDirty && borradorAlDia;

    /**
     * Por qué el botón del visor está apagado, dicho adentro del visor.
     *
     * Un botón deshabilitado que no explica su condición es el que dejó a
     * alguien mirando la pantalla sin saber qué le faltaba. Acá el motivo
     * viaja al lado del botón, no a media pantalla de distancia.
     */
    const motivoParaNoGenerar = !estado.canIssue
        ? (estado.blockedReason ??
          'Faltan datos que el formulario imprime. Cerrá y completalos.')
        : hojasPendientes.length > 0
          ? `Falta mirar ${hojasPendientes.join(' y ')}: cambiá de solapa.`
          : null;

    const guardar = (e: React.FormEvent) => {
        e.preventDefault();

        datos.patch(guardarDatos(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                datos.setDefaults(datos.data);
                /*
                 * Se reinicia a mano y no alcanza con la firma: el
                 * domicilio y el teléfono salen impresos pero no entran en
                 * ella —viven en `datos`, no en `emitir`—, así que guardar
                 * cambia el papel sin cambiar la firma.
                 */
                setRevisado({ firma: '', hojas: [] });
            },
        });
    };

    /*
     * Abre el visor. El borrador se da por revisado cuando la hoja cargó,
     * no al apretar: si el PDF no aparece, nadie leyó nada y la guarda
     * previa a emitir no tiene por qué darse por cumplida.
     */
    const verBorrador = () => {
        if (!puedePrevisualizar) {
            return;
        }

        setViendo(true);
    };

    const generar = () => {
        if (!puedeGenerar) {
            return;
        }

        emitir.post(emitirOrden(cuota.id).url);
    };

    return (
        <>
            <Head title={`Orden de Pago · cuota ${cuota.number}`} />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title={`Orden de Pago de la cuota ${cuota.number}`}
                    description={
                        cuota.concept ??
                        'La Orden y su nota de Pase se emiten juntas y viajan juntas al organismo.'
                    }
                    actions={
                        <div className="text-right">
                            <p className="text-xs tracking-wide text-field-label uppercase">
                                Importe
                            </p>
                            <Money
                                value={cuota.amount}
                                className="block text-lg font-semibold"
                            />
                        </div>
                    }
                />

                {estado.canIssue ? (
                    <p className="flex items-start gap-2 rounded-lg border border-success bg-success-soft px-3 py-2 text-sm text-success-strong">
                        <BadgeCheck
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Está todo lo que el papel imprime. Para emitir, primero
                        guardá cualquier cambio y revisá el borrador
                        actualizado.
                    </p>
                ) : (
                    <div className="rounded-lg border border-warning bg-warning-soft px-3 py-2 text-sm text-warning-strong">
                        <p className="flex items-start gap-2">
                            <TriangleAlert
                                className="mt-0.5 size-4 shrink-0"
                                aria-hidden="true"
                            />
                            {estado.blockedReason ??
                                'Faltan datos que el formulario imprime. Podés ir directo a cada sección.'}
                        </p>
                        {faltantesObligatorios.length > 0 && (
                            <nav
                                className="mt-2 flex flex-wrap gap-2 pl-6"
                                aria-label="Datos obligatorios pendientes"
                            >
                                {Array.from(
                                    new Set(
                                        faltantesObligatorios.map(
                                            (campo) => campo.section,
                                        ),
                                    ),
                                ).map((seccion) => (
                                    <a
                                        key={seccion}
                                        href={`#orden-${seccion}`}
                                        className="rounded-md border border-warning/60 bg-background/70 px-2 py-1 text-xs font-medium hover:bg-background"
                                    >
                                        {nombresDeSeccion[seccion] ?? seccion}
                                    </a>
                                ))}
                            </nav>
                        )}
                    </div>
                )}

                {/*
                 * Una sola columna, con tope de ancho: son campos cortos
                 * -un documento, un teléfono, una foja- y estirarlos hasta
                 * el borde de una pantalla ancha los vuelve ilegibles.
                 */}
                <div className="mx-auto w-full max-w-5xl">
                    <form onSubmit={guardar} className="space-y-5">
                        <Seccion
                            id="orden-beneficiary"
                            titulo="Beneficiario"
                            papel="ambos"
                            faltan={faltantes.beneficiary}
                            descripcion={contexto.beneficiaryName}
                        >
                            <div className="grid gap-3 sm:grid-cols-3">
                                <Campo
                                    id="ben-doc"
                                    etiqueta="DNI o CUIT"
                                    valor={datos.data.beneficiaryDocument}
                                    onChange={(v) =>
                                        datos.setData('beneficiaryDocument', v)
                                    }
                                    error={datos.errors.beneficiaryDocument}
                                />
                                <Campo
                                    id="ben-dom"
                                    etiqueta="Domicilio"
                                    className="sm:col-span-2"
                                    valor={datos.data.beneficiaryAddress}
                                    onChange={(v) =>
                                        datos.setData('beneficiaryAddress', v)
                                    }
                                    error={datos.errors.beneficiaryAddress}
                                    placeholder="B° San Jorge Mza 54 Casa 03, Campo Quijano"
                                />
                                <Campo
                                    id="ben-tel"
                                    etiqueta="Teléfono"
                                    valor={datos.data.beneficiaryPhone}
                                    onChange={(v) =>
                                        datos.setData('beneficiaryPhone', v)
                                    }
                                    error={datos.errors.beneficiaryPhone}
                                    placeholder="387-6004216"
                                />
                            </div>
                        </Seccion>

                        <Seccion
                            id="orden-account"
                            titulo="Cuenta del beneficiario"
                            papel="pase"
                            faltan={faltantes.account}
                            descripcion="El sistema opera únicamente con CBU, y la cuenta tiene que estar verificada."
                        >
                            <CuentasDelBeneficiario
                                contexto={contexto}
                                cuentas={contexto.accounts}
                                elegida={emitir.data.beneficiaryBankAccountId}
                                onElegir={(id) =>
                                    emitir.setData(
                                        'beneficiaryBankAccountId',
                                        id,
                                    )
                                }
                                puedeVerificar={puedeVerificarCbu}
                                puedeForzar={puedeForzarCbu}
                            />
                        </Seccion>

                        <Seccion
                            id="orden-employer"
                            titulo="Empleador"
                            papel="orden"
                            faltan={faltantes.employer}
                            descripcion={
                                contexto.employerName ??
                                'El expediente no tiene empleador cargado.'
                            }
                        >
                            {contexto.employerId === null ? (
                                <p className="text-xs text-muted-foreground">
                                    Hay que cargarlo en el expediente: la Orden
                                    imprime la empresa que depositó.
                                </p>
                            ) : (
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <Campo
                                        id="emp-doc"
                                        etiqueta="CUIT"
                                        valor={datos.data.employerDocument}
                                        onChange={(v) =>
                                            datos.setData('employerDocument', v)
                                        }
                                        error={datos.errors.employerDocument}
                                        placeholder="30-71731318-2"
                                    />
                                    <Campo
                                        id="emp-dom"
                                        etiqueta="Domicilio"
                                        className="sm:col-span-2"
                                        valor={datos.data.employerAddress}
                                        onChange={(v) =>
                                            datos.setData('employerAddress', v)
                                        }
                                        error={datos.errors.employerAddress}
                                    />
                                    <Campo
                                        id="emp-tel"
                                        etiqueta="Teléfono"
                                        valor={datos.data.employerPhone}
                                        onChange={(v) =>
                                            datos.setData('employerPhone', v)
                                        }
                                        error={datos.errors.employerPhone}
                                    />
                                </div>
                            )}
                        </Seccion>

                        <Seccion
                            id="orden-expediente"
                            titulo="Expediente"
                            papel="ambos"
                            faltan={faltantes.expediente}
                            descripcion={
                                contexto.expedienteCanonical ??
                                contexto.expedienteNumber
                            }
                        >
                            <div className="max-w-xs">
                                <Label
                                    htmlFor="fecha-ini"
                                    className="text-xs font-normal"
                                >
                                    Fecha de ingreso · «FECHA INI.»
                                </Label>
                                <Input
                                    id="fecha-ini"
                                    type="date"
                                    value={datos.data.custodyStartDate}
                                    onChange={(e) =>
                                        datos.setData(
                                            'custodyStartDate',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Desde cuándo el organismo tiene estos fondos
                                    en custodia.
                                </p>
                                <InputError
                                    message={datos.errors.custodyStartDate}
                                />
                            </div>
                        </Seccion>

                        <Seccion
                            id="orden-organism"
                            titulo="De dónde vino el dinero"
                            papel="orden"
                            faltan={faltantes.organism}
                            descripcion="Es la tabla de depósitos que el formulario imprime, y la casilla que marca."
                        >
                            <TablaDeDepositos estado={estado} />
                        </Seccion>

                        <Seccion titulo="Recibo de ingreso" papel="orden">
                            <div className="flex flex-wrap gap-2">
                                <Opcion
                                    activa={
                                        emitir.data
                                            .incomeReceiptNumberSource ===
                                        'system'
                                    }
                                    onClick={() =>
                                        emitir.setData(
                                            'incomeReceiptNumberSource',
                                            'system',
                                        )
                                    }
                                    etiqueta={
                                        estado.incomeReceiptSystemNumber ?? '—'
                                    }
                                    ayuda="Número del sistema"
                                />
                                <Opcion
                                    activa={
                                        emitir.data
                                            .incomeReceiptNumberSource ===
                                        'talonario'
                                    }
                                    onClick={() =>
                                        emitir.setData(
                                            'incomeReceiptNumberSource',
                                            'talonario',
                                        )
                                    }
                                    etiqueta={
                                        estado.incomeReceiptTalonarioNumber ??
                                        'Sin talonario'
                                    }
                                    ayuda="Número del talonario"
                                    deshabilitada={
                                        estado.incomeReceiptTalonarioNumber ===
                                        null
                                    }
                                />
                            </div>
                        </Seccion>

                        {/*
                         * La foja va sola y rotulada «los dos papeles»
                         * porque es el único dato que se escribe una vez y
                         * se imprime dos: redacta la cita de la nota —«a la
                         * CBU informada en fs. 19»— y el renglón OBS de la
                         * Orden, que el área confirmó que no lleva nada más
                         * (D-003). Cuando eran dos campos, nada garantizaba
                         * que dijeran el mismo número.
                         */}
                        <Seccion
                            titulo="Foja donde el expediente informa el CBU"
                            papel="ambos"
                            descripcion="Se escribe una vez y sale en los dos documentos."
                        >
                            <div className="max-w-xs">
                                <Label
                                    htmlFor="cbu-folio"
                                    className="text-xs font-normal"
                                >
                                    Foja
                                </Label>
                                <Input
                                    id="cbu-folio"
                                    value={emitir.data.cbuFolio}
                                    onChange={(e) =>
                                        emitir.setData(
                                            'cbuFolio',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="19"
                                    className="mt-1"
                                />
                                <InputError message={emitir.errors.cbuFolio} />
                            </div>

                            <FojaEnLosPapeles foja={emitir.data.cbuFolio} />
                        </Seccion>

                        <Seccion
                            titulo="Cómo se redacta la nota"
                            papel="pase"
                            descripcion="El resto lo arma sola: el beneficiario, el importe en letras y la referencia del expediente salen de la Orden."
                        >
                            <div className="max-w-sm">
                                <Label
                                    htmlFor="pase-destino"
                                    className="text-xs font-normal"
                                >
                                    Destinatario
                                </Label>
                                <Input
                                    id="pase-destino"
                                    value={emitir.data.paseDestination}
                                    onChange={(e) =>
                                        emitir.setData(
                                            'paseDestination',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1"
                                />
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Encabeza «Pase de Contable — A{' '}
                                    {emitir.data.paseDestination}».
                                </p>
                                <InputError
                                    message={emitir.errors.paseDestination}
                                />
                            </div>
                        </Seccion>

                        <Seccion
                            titulo="El borrador"
                            papel="ambos"
                            descripcion="Las dos hojas como saldrían con lo elegido hasta acá. El número que muestran es el que les tocaría: el definitivo se toma recién al confirmar."
                        >
                            <Button
                                type="button"
                                variant="outline"
                                onClick={verBorrador}
                                disabled={!puedePrevisualizar}
                            >
                                <Eye className="size-4" />
                                Ver la Orden y el Pase
                            </Button>
                        </Seccion>

                        <InputError message={errors?.installmentId} />

                        {datos.isDirty && (
                            <p
                                className="rounded-md border border-warning/60 bg-warning-soft px-3 py-2 text-xs text-warning-strong"
                                aria-live="polite"
                            >
                                Hay cambios sin guardar. Guardalos para que el
                                borrador y los documentos usen esos datos.
                            </p>
                        )}

                        <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={datos.processing || !datos.isDirty}
                            >
                                Guardar los datos
                            </Button>

                            {/*
                             * El último paso abre el papel, y generar vive
                             * adentro del visor. Antes acá había un
                             * «Generar» apagado cuya condición se explicaba
                             * en un cartel de arriba: comunicaba el «no»
                             * sin comunicar la salida.
                             *
                             * De paso desaparece la revisión vencida. Como
                             * no se puede emitir sin tener las hojas en
                             * pantalla, la firma nunca llega a envejecer
                             * entre que se mira y se confirma.
                             */}
                            <Button
                                type="button"
                                onClick={verBorrador}
                                disabled={!puedePrevisualizar}
                            >
                                <Eye className="size-4" />
                                Revisá la Orden y el Pase
                            </Button>
                        </div>
                    </form>
                </div>
            </div>

            <DocumentViewerDialog
                titulo={`Borrador de la cuota ${cuota.number}`}
                descripcion="Todavía no está emitido: se puede cerrar, corregir y volver a mirarlo."
                documentos={borradores}
                abierto={viendo}
                onCerrar={() => setViendo(false)}
                onCargado={marcarHojaVista}
                acciones={
                    <>
                        <Button
                            type="button"
                            onClick={generar}
                            disabled={emitir.processing || !puedeGenerar}
                        >
                            <FileSignature className="size-4" />
                            Generar Orden y Pase
                        </Button>
                        {motivoParaNoGenerar !== null && (
                            <span
                                className="text-xs text-muted-foreground"
                                aria-live="polite"
                            >
                                {motivoParaNoGenerar}
                            </span>
                        )}
                    </>
                }
            />
        </>
    );
}
