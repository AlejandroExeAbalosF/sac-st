/**
 * Forma del alta de un expediente en el formulario.
 *
 * Es tipado de interfaz, no del backend: describe lo que el usuario está
 * armando en pantalla, incluidos los campos a medio completar. Lo que
 * viaja al servidor se construye a partir de esto en el momento de
 * enviar, normalizando los importes.
 */

export type InstallmentDraft = {
    /** Clave estable para React; no viaja al servidor. */
    key: string;
    number: number;
    /** Tal como se tipeó: puede traer coma y puntos de miles. */
    amount: string;
    managementLabelId: number | null;
    /** Vacío significa «el mismo concepto del haber». */
    concept: string;
    dueDate: string;
    /**
     * Medio previsto para esta cuota. `null` es «todavía no se sabe».
     *
     * Cuotas distintas del mismo haber pueden usar medios distintos: es
     * habitual que la primera se cobre en efectivo por mostrador y las
     * siguientes lleguen por transferencia.
     */
    expectedMedium: 'cash' | 'cheque' | 'bank' | null;
    /** Observaciones libres. No se imprimen en el comprobante. */
    notes: string;
};

/**
 * Una cuota en blanco.
 *
 * Vive acá y no en el componente porque hay dos lugares que arrancan una
 * cuota —el alta del haber y el botón «Agregar cuota»—, y con dos copias
 * cada campo nuevo se olvidaba en una.
 */
export const nuevaCuota = (numero: number): InstallmentDraft => ({
    key: crypto.randomUUID(),
    number: numero,
    amount: '',
    managementLabelId: null,
    concept: '',
    dueDate: '',
    expectedMedium: null,
    notes: '',
});

export type HaberDraft = {
    key: string;
    beneficiaryId: number | null;
    /** Cuenta propuesta para pagar; puede seguir pendiente de verificación. */
    defaultBankAccountId: number | null;
    assignedAmount: string;
    expectedInstallmentCount: number;
    concept: string;
    legalDate: string;
    resolutionReference: string;
    notes: string;
    installments: InstallmentDraft[];
};

export type ExpedienteDraft = {
    number: string;
    subject: string;
    receivedDate: string;
    employerId: number | null;
    declaredTotalAmount: string;
    externalId: string;
    externalReference: string;
    notes: string;
    haberes: HaberDraft[];
};

export type EtiquetaOption = {
    id: number;
    code: string;
    description: string;
};

/**
 * Una caja del organismo, tal como la ofrece un formulario.
 *
 * `code` viaja además del nombre porque las pantallas eligen «haberes»
 * por defecto: es el circuito que están construyendo, y buscarla por
 * nombre se rompería al renombrarla.
 */
export type Caja = { id: number; code: string; name: string };

/**
 * Todo lo que la documentación de pago necesita, en un solo bulto.
 *
 * Va junto y no como props sueltas porque atraviesa cuatro componentes
 * —la página, la lista, la tarjeta y el panel— sin que ninguno de los
 * intermedios lo mire: son un pasamanos, y un pasamanos de varios
 * parámetros se desordena en la primera prop que se agrega.
 *
 * Lo que la Orden necesita para **armarse** ya no está acá: eso lo pide su
 * propia pantalla, que es la que lo usa.
 */
export type OrdenDePagoProps = {
    /** El estado de cada cuota, indexado por su id. */
    estados: Record<number, App.Modules.Haberes.Data.InstallmentOrderStateData>;
    permisos: {
        ver: boolean;
        emitir: boolean;
        anular: boolean;
        verificarCbu: boolean;
    };
};

/**
 * Todo lo que la sección de egreso necesita, en un solo bulto.
 *
 * Mismo criterio que `OrdenDePagoProps`: atraviesa la página, la lista y
 * la tarjeta sin que ninguno de los intermedios lo mire, y props sueltas
 * de pasamanos se desordenan en la primera que se agrega.
 */
export type EgresoProps = {
    /** El estado de cada cuota, indexado por su id. */
    estados: Record<
        number,
        App.Modules.Haberes.Data.InstallmentDisbursementData
    >;
    permisos: {
        /** Entregar el dinero y emitir el recibo que el beneficiario firma. */
        registrar: boolean;
        /**
         * Cotejar Orden, informe y débito, y dar el pago por hecho.
         *
         * Va aparte de `registrar` porque es otra responsabilidad: los dos
         * primeros pasos del circuito bancario son carga de datos; éste
         * postea el asiento y deja la cuota pagada (§2.3.4).
         */
        validar: boolean;
    };
};
