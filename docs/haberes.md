# Haberes en consignación

Esta guía describe el circuito implementado, contrastado el 2026-09-30 con
Actions, rutas, migraciones y tests. No convierte automáticamente ese comportamiento
en una decisión aprobada por el área. Los antecedentes y las preguntas abiertas se
distinguen al final.

El detalle de relaciones, controles y evidencia está en
[Reglas y modelo de Haberes](haberes-reglas.md). Para arqueos, cierres y saldos de
caja, consultar [La Caja de Haberes](caja-de-haberes.md).

<a id="circuito"></a>

## El circuito

1. Cargar el expediente, sus haberes por beneficiario y las cuotas previstas.
2. Identificar el ingreso: cobrar por mostrador o cruzar el comprobante de depósito
   con un crédito del extracto. Un comprobante cargado todavía no es dinero recibido.
3. Financiar la cuota y emitir el recibo de ingreso.
4. Resolver la salida según dónde esté el dinero: entrega por mostrador o transferencia
   del organismo. Un traslado al banco pendiente de acreditación no habilita el pago.
5. Documentar el egreso confirmado con su recibo y conservar la trazabilidad.

Cobrar al empleador y pagar al beneficiario son hechos distintos. La cuota financiada
no equivale a una cuota pagada.

<a id="expedientes-y-cuotas"></a>

## Expedientes, haberes y cuotas

El expediente agrupa los derechos de los beneficiarios. Cada haber reconoce un importe
y lo distribuye en cuotas; puede haber cuotas previstas todavía sin cargar. La suma
de las cuotas no anuladas no puede superar el importe reconocido del haber.

El listado está en `/haberes/expedientes`. La ficha utiliza la clave de ruta del
expediente, y cada haber se identifica por su número **dentro de ese expediente**,
no por su id global: `/haberes/expedientes/{expediente}/haber/{haber}`.

La etiqueta de gestión describe la situación administrativa. Si tiene
`blocks_payment`, retiene el egreso; no impide recibir fondos. El medio previsto de
la cuota es informativo: una vez financiada manda el medio real de sus recepciones.

Reglas: [identidad y plan](haberes-reglas.md#identidad-y-plan) y
[financiación](haberes-reglas.md#financiacion).

<a id="ingreso-y-recibo"></a>

## Ingreso y recibo

**Efectivo o cheque por mostrador.** `CollectAndIssueReceipt` integra el cobro y la
emisión en una transacción cuando corresponde cobrar. Obtiene el importe pendiente
y el medio de la cuota, no de un importe libre enviado por el navegador.

**Depósito en cuenta.** El comprobante se carga desde la cuota. La cola `/depositos`
permite buscar y vincular el crédito del extracto. La recepción y la asignación
registran el dinero identificado; la foto del ticket por sí sola no financia la cuota.

El recibo de ingreso requiere financiación completa y conserva los datos impresos
en columnas de snapshot. Corregir luego la cuota no reescribe el recibo. El cobro
de un cheque por mostrador no espera su posterior acreditación bancaria para emitirlo.

El listado `/recepciones` sigue teniendo ruta, aunque salió del menú. El alta manual
de recepciones no está expuesta: las entradas ordinarias nacen del circuito de la cuota.

Reglas y cobertura: [recibo de ingreso](haberes-reglas.md#recibo-de-ingreso).

<a id="corregir-cuota"></a>

## Corregir una cuota y resolver el excedente

Una cuota activa de un haber activo se puede corregir, respetando el total del plan
y el control de versión para no pisar cambios concurrentes. Si hay una Orden activa
con Pase no anulado, se exige abrir una ventana justificada de edición. Guardar la
corrección cierra esa ventana y registra el motivo junto al antes y el después.

**Bajar el importe por debajo de lo ya imputado está permitido.** El sistema muestra
la diferencia como `overAllocatedAmount`.

Ejemplo: con 1.000 asignados, bajar el esperado a 750 deja 250 de excedente visible.
No altera el recibo ya emitido ni devuelve dinero automáticamente.

`UnallocateFunds` permite liberar imputaciones mediante una reversión con motivo:
el dinero vuelve a fondos sin identificar. Está sujeto a las guardas de la Orden,
del traslado y del importe vigente. **Liberar una imputación no es devolver dinero
al empleador.** Tampoco significa que cualquier liberación parcial sea admisible:
el trigger de asignaciones vuelve a evaluar el tope al insertar la reversión.

Detalle: [corrección y sobreasignación](haberes-reglas.md#correccion-y-sobreasignacion).

<a id="traslado-al-banco"></a>

## Traslado al banco

Desde `/haberes/cuotas/{installment}/traslado`, el dinero recibido por mostrador se
deposita en la cuenta del organismo. Es un traslado interno: conserva la imputación
al beneficiario y el recibo original, incluido su medio de ingreso.

El asiento pasa por `CASH_IN_TRANSIT`; el banco debe confirmar la acreditación.
Mientras está en tránsito no corresponde pagar por mostrador ni emitir la Orden
como si los fondos ya estuvieran disponibles en la cuenta.

Regla: [canal de pago](haberes-reglas.md#canal-de-pago).

<a id="orden-y-pase"></a>

## Orden de Pago y Pase

Corresponden cuando el dinero sale por transferencia del organismo. Se preparan en
`/haberes/cuotas/{installment}/orden/nueva` y nacen juntos. La emisión comprueba
financiación, recibo de ingreso, canal, condición administrativa y datos requeridos
de las partes y de la cuenta verificada del beneficiario.

El servidor deriva el importe y los orígenes del dinero. El operador elige la cuenta
verificada, la foja del CBU, el número de recibo que se imprime, el destinatario del
Pase y, opcionalmente, el tesorero. No hay un tipo de Orden elegible.

La foja del CBU redacta tanto la referencia del Pase como el campo OBS de la Orden.
No son dos textos independientes. Para corregir datos accesorios se conserva el
documento y se audita el cambio; si corresponde anular la Orden, se anula también
su Pase. La reemplazante conserva el vínculo y consume un número nuevo.

La anulación de la Orden no revierte el ingreso ni libera sus imputaciones por sí sola.

Reglas: [Orden y Pase](haberes-reglas.md#orden-y-pase) y
[foja del CBU](haberes-reglas.md#foja-cbu).

<a id="egreso"></a>

## Egreso al beneficiario

| Situación del dinero | Salida implementada |
| --- | --- |
| Efectivo o cheque sin traslado vigente | Entrega por mostrador y recibo de egreso |
| Traslado pendiente de acreditación | Esperar confirmación bancaria |
| Ingreso bancario o traslado acreditado | Orden, informe, débito, validación y recibo de egreso |

En mostrador se entrega efectivo o el cheque, según el medio real. En transferencia,
el informe del organismo y el débito del extracto pueden registrarse en cualquier
orden. Reunirlos deja el egreso listo para validar; **no lo confirma automáticamente**.
La validación registra el asiento, marca la cuota pagada y completa la Orden.

El recibo de egreso requiere un egreso confirmado. Las colas y planillas de seguimiento
están en `/haberes/planillas`.

Estados, restricciones y pruebas: [egreso](haberes-reglas.md#egreso).

<a id="historicos"></a>

## Expedientes históricos

Un expediente del sistema anterior se carga con las pantallas de siempre: el
expediente, sus haberes y sus cuotas. Lo que cambia es qué se dice de cada cuota.

Una cuota que **ya se pagó fuera del circuito** se registra desde su tarjeta con
«Registrar pago anterior», de una de dos maneras:

| Modalidad | Cuándo | Qué se carga |
| --- | --- | --- |
| Antes de la apertura | Se pagó en papel, antes de que la caja abriera los libros | Recibo de ingreso; Orden y recibo de egreso si están; fecha y medio del pago |
| Desde Pagos anteriores | Se pagó con `/caja/pagos-anteriores`, contra el saldo del sistema anterior | Recibo de ingreso y el recibo del sistema que registró ese egreso |

Los papeles van con **el número de talonario y la fecha que tienen impresos**,
nunca con numeración del sistema, y la foto si está a mano. El recibo de ingreso
es obligatorio y por el importe de la cuota: las cuotas se pagan enteras.

No mueve dinero. En la primera modalidad el pago ocurrió antes de la apertura; en
la segunda el egreso ya está en el libro y lo único que se agrega es el vínculo.
Un recibo de Pagos anteriores puede respaldar varias cuotas del mismo beneficiario,
hasta su importe.

La cuota queda en `legacy_settled` y su tarjeta muestra los papeles en lugar de
los tramos de ingreso, Orden y egreso. Un registro mal cargado se anula con motivo
y la cuota vuelve a estar pendiente; si venía de Pagos anteriores, el pago sigue
en la caja.

Reglas: [cuotas pagadas fuera del circuito](haberes-reglas.md#historicos).

<a id="permisos"></a>

## Permisos y auditoría

Las rutas controlan capacidades, no nombres de roles fijos. La configuración de roles
puede cambiar; esta tabla describe las capacidades que consulta el circuito.

| Operación | Capacidad |
| --- | --- |
| Consultar expedientes e historiales | `expedientes.ver` |
| Editar cuotas y abrir la ventana justificada | `expedientes.editar` |
| Registrar / cruzar comprobantes | `depositos.registrar` / `depositos.vincular` |
| Emitir recibo de ingreso | `recibos.emitir` (el cobro comprueba además su permiso de recepción) |
| Emitir / anular Orden | `ordenes.emitir` / `ordenes.anular` |
| Registrar / validar egreso | `egresos.registrar` / `egresos.validar` |
| Registrar una cuota pagada fuera del circuito | `expedientes.registrar-historico` |
| Anular ese registro | `expedientes.anular` |

La referencia completa es [rutas de Haberes](../routes/modules/haberes.php).
Las correcciones y operaciones relevantes registran eventos; pueden consultarse en
los historiales del circuito y en la [auditoría transversal](auditoria-acceso.md).

<a id="pendientes-y-antecedentes"></a>

## Pendientes y antecedentes

- El reconocimiento formal de un excedente de recepción (`acknowledged_excess`) sigue
  sin Action, ruta ni botón. Es distinto del excedente de una cuota corregida, que sí
  se muestra y tiene el mecanismo de liberación descrito arriba.
- Los estados declarados en los enums no implican que exista una pantalla o transición
  para cada uno. Consultar las transiciones implementadas en la guía de reglas.
- Correcciones registra como pendiente de confirmación del área la exigencia del recibo
  de ingreso antes del egreso por mostrador. Hoy el sistema la exige; esta guía no
  transforma esa implementación en aprobación funcional.
- Apartar del saldo del sistema anterior la plata de una cuota que todavía está en
  custodia —para que siga el circuito normal— es la segunda etapa de la carga
  histórica y todavía no está implementada. Hasta entonces, esos casos se pagan
  desde Pagos anteriores y después se vinculan.
- Los pendientes de dólares y comprobantes siguen en
  [la guía de Caja](caja-de-haberes.md); no se resuelven con esta consolidación.

Antecedentes locales, fuera del repositorio: `Relevamiento/Modelo-de-Datos-DER-v4.3.md`
y `Relevamiento/Correcciones-al-DER-pendientes.md`, en la carpeta contenedora del proyecto.
El segundo reúne decisiones de distintas etapas, algunas superadas. Sus números
46–49 se repiten: identificar esas entradas por título o por su ancla explícita.

Para los temas cubiertos aquí, citar `docs/haberes.md#ancla` o
`docs/haberes-reglas.md#ancla`. Las referencias históricas de temas todavía no
consolidados se conservan; este documento no sustituye el esquema completo de Banking,
Ledger o Shared. Mantener las anclas aunque cambien los títulos y actualizar la guía
y su evidencia junto con cualquier cambio del circuito.
