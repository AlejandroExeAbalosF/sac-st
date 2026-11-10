# Estados visibles en la interfaz

Cada fila muestra un rótulo visible y su significado. Se incluyen etapas y alertas de negocio; se excluyen opciones como «Todos», indicadores de carga y errores técnicos. El contexto de la pantalla importa: «Pendiente» o «Cerrado» no significan lo mismo para todas las entidades.

## Haberes

### Expedientes y haberes

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Expedientes | Activo | Admite gestión. |
| Expedientes | Suspendido | Gestión pausada. |
| Expedientes | Cerrado | Expediente finalizado. |
| Expedientes | Anulado | Expediente dejado sin efecto. |
| Haberes activos | Plan incompleto | Las cuotas cargadas no cubren el importe reconocido. |
| Haberes activos | Sin pagos | El plan cubre el importe, pero ninguna cuota se pagó. Debajo: «Pagado $ … de $ …». |
| Haberes activos | Pago parcial | Se pagó parte del importe reconocido. Debajo: «Pagado $ … de $ …», sin excedentes. |
| Haberes activos | Pago completado | Se pagó el importe reconocido. |
| Haberes no activos | Suspendido | Gestión pausada; prevalece sobre el avance del pago. |
| Haberes no activos | Bloqueado | Hay un impedimento para avanzar. |
| Haberes no activos | Anulado | Haber dejado sin efecto. |
| Haberes no activos | Cerrado | Gestión terminada. |

### Cuotas

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Cuotas, gestión | Pendiente | Cuota vigente. Puede tener fondos; este estado no significa «sin financiar». |
| Cuotas, gestión | Suspendida | Gestión pausada. |
| Cuotas, gestión | Bloqueada | No puede avanzar normalmente. |
| Cuotas, gestión | Anulada | Cuota dejada sin efecto. |
| Cuotas, gestión | Pagada | Pago registrado. |
| Cuotas, gestión | Pagada antes de la apertura | Saldada en papel antes de abrir el sistema. |
| Cuotas, etapa | Sin financiar | Aún no tiene todos los fondos imputados; puede tener fondos parciales. |
| Cuotas, etapa | Falta el recibo | Fondos cubiertos; falta el recibo de ingreso. |
| Cuotas, etapa | En caja | Fondos cobrados por mostrador, en el cajón. |
| Cuotas, etapa | Depositada, en tránsito | Se depositó; falta la acreditación bancaria. |
| Cuotas, etapa | Fondos acreditados · falta Orden de Pago | El dinero está en la cuenta: entró por banco o se trasladó y se acreditó. |
| Cuotas, etapa | Orden emitida | Existe una Orden de Pago. |
| Cuotas, etapa | Transferencia informada · falta débito | Llegó el informe; falta identificar el débito. |
| Cuotas, etapa | Débito identificado · falta informe | Se identificó el débito; falta el informe. |
| Cuotas, etapa | Informe y débito registrados · falta validación | Están el informe y el débito; falta validarlos. |
| Cuotas, etapa | Pagada | Circuito de pago terminado. |
| Cuotas, etapa | Pagada antes de la apertura | Pago histórico anterior al sistema. |

La etapa también puede mostrar **Anulada**, **Bloqueada** o **Suspendida** cuando prevalece el estado administrativo de la cuota.

### Planillas

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Todas las cuotas | Sin fondos imputados | No hay fondos asignados a la cuota. |
| Todas las cuotas | Fondos parciales · falta $… | Hay fondos asignados, pero no alcanzan el importe de la cuota. |
| Todas las cuotas | Fondos recibidos · emitir recibo de ingreso | Importe cubierto; falta documentar el ingreso. |
| Todas las cuotas | Entrega pendiente | Falta pagar en mostrador. |
| Todas las cuotas | Depósito en tránsito · falta acreditación | Se espera el crédito en el extracto bancario. |
| Todas las cuotas | Fondos acreditados · falta Orden de Pago | El dinero está acreditado; falta emitir la orden. |
| Todas las cuotas | Orden emitida · faltan informe y débito | Faltan ambas evidencias de la transferencia. |
| Todas las cuotas | Transferencia informada · falta débito | Llegó el informe; falta identificar el débito. |
| Todas las cuotas | Débito identificado · falta informe | Se identificó el débito; falta el informe. |
| Todas las cuotas | Informe y débito registrados · falta validación | Falta cotejar y validar el egreso. |
| Todas las cuotas | Pago confirmado · falta recibo de egreso | Egreso validado; falta documentarlo con recibo. |
| Todas las cuotas | Pago confirmado | Egreso validado y documentado. |
| Todas las cuotas | · pago retenido | Sufijo adicional cuando existe una restricción de pago. |
| Transferencias sin confirmar | Transferencia informada · falta débito | Informe recibido; débito pendiente. |
| Transferencias sin confirmar | Débito identificado · falta informe | Débito identificado; informe pendiente. |
| Transferencias sin confirmar | Informe y débito registrados · falta validación | Ambas evidencias disponibles; validación pendiente. |

«Por entregar» agrupa cuotas cuyo pago en mostrador sigue pendiente. «Todas las cuotas» también muestra los estados administrativos de cuotas suspendidas, bloqueadas o anuladas.

### Documentos, egresos y fondos

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Órdenes de pago | Emitida | Orden creada y numerada; todavía está en el área. |
| Órdenes de pago | Revisada | Se completó la revisión. |
| Órdenes de pago | Aprobada | Se completó la aprobación. |
| Órdenes de pago | Remitida | Se envió al organismo. |
| Órdenes de pago | Transferencia informada · falta débito | Llegó el informe de transferencia. |
| Órdenes de pago | Débito identificado · falta informe | Se identificó el débito bancario. |
| Órdenes de pago | Informe y débito registrados · falta validación | Están el informe y el débito. |
| Órdenes de pago | Completada | Egreso validado. |
| Órdenes de pago | Rechazada | Rechazo del organismo. |
| Órdenes de pago | Anulada | Baja por el área. |
| Egresos | Preparado · faltan informe y débito | Todavía no salió dinero. |
| Egresos | Transferencia informada · falta débito | Informe recibido; débito pendiente. |
| Egresos | Débito identificado · falta informe | Débito identificado; informe pendiente. |
| Egresos | Informe y débito registrados · falta validación | Falta cotejar y validar ambas evidencias. |
| Egresos | Pagado | Egreso confirmado. |
| Egresos | Revertido | Operación deshecha. |
| Egresos | Fallido | Pago no completado. |
| Comprobantes de depósito | Esperando en el banco | Falta vincular la acreditación. |
| Comprobantes de depósito | Conciliado con el banco | Se vinculó el movimiento bancario correspondiente. |
| Comprobantes de depósito | Descartado | Comprobante apartado del circuito. |
| Recepciones | Asignada | Ya no queda saldo para distribuir. |
| Recepciones | Revertida | Se deshizo la recepción. |
| Cuotas vinculadas a recepciones | Financiada | Los fondos asignados cubren el importe de la cuota. |
| Recibos | Emitido | Documento vigente. |
| Recibos | Anulado | Documento dejado sin efecto. |

Algunos estados intermedios de las órdenes están definidos en el dominio aunque no todos tienen hoy una transición operativa propia.

## Banco y Caja

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Extractos | Cargado | Archivo recibido. |
| Extractos | Procesando | Se están interpretando las filas. |
| Extractos | Importado | Importación terminada. |
| Extractos | Rechazado | El archivo no superó los controles. |
| Filas del extracto | Interpretada | Fila utilizable. |
| Filas del extracto | Sin saldo | No se informó el saldo posterior. |
| Filas del extracto | Rechazada | Fila inválida. |
| Filas del extracto | Ya registrado | Movimiento duplicado. |
| Continuidad de saldos | Cierra | Los saldos del extracto son coherentes. |
| Continuidad de saldos | No cierra | Hay una diferencia. |
| Continuidad de saldos | No verificable | Faltan datos para comprobarla. |
| Movimientos bancarios | Sin identificar | Importe sin explicación contable. |
| Movimientos bancarios | Identificado en parte | Solo una parte del importe está explicada. |
| Movimientos bancarios | Identificado | Importe explicado por completo. |
| Movimientos bancarios | Excluido de la conciliación | Movimiento apartado con un motivo. |
| Vínculo bancario con Haberes | Comprobante conciliado | El comprobante coincide con el movimiento. |
| Vínculo bancario con Haberes | Imputado a la cuota | Fondos asignados a una cuota. |
| Vínculo bancario con Haberes | Efectivo depositado en el banco | Acreditación de efectivo que salió de caja. |
| Vínculo bancario con Haberes | Débito vinculado | Débito asociado a un egreso. |
| Vínculo bancario con Haberes | Pago confirmado | Pago vinculado y validado. |
| Arqueos | En borrador | Conteo editable. |
| Arqueos | Revisado | Conteo aceptado. |
| Arqueos | Ajustado | Diferencia asentada contablemente. |
| Arqueos | Cerrado | Conteo congelado como respaldo del cierre. |
| Cierres de Caja | En preparación | Cálculo todavía no firme. |
| Cierres de Caja | Cerrado | Período cerrado. |
| Cierres de Caja | Reabierto | Cierre deshecho con motivo y registro. |
| Calendario de Caja | Cerrado | Día cerrado. |
| Calendario de Caja | Reabierto | Día cuyo cierre se deshizo. |
| Calendario de Caja | Con movimientos, sin cerrar | Hubo actividad y falta el cierre diario. |
| Calendario de Caja | Solo reversiones, sin cerrar | Hubo actividad contable sin cobro o pago ordinario. |
| Calendario de Caja | Sin movimientos | Día sin actividad contable. |
| Calendario de Caja | Todavía no | Día futuro. |

Los vínculos bancarios con Haberes explican la relación de un movimiento; no son otro estado del movimiento.

## Aranceles

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Cobros | Pendiente: faltan $… | Aún queda importe por completar. |
| Cobros | Pagado | Cobro completo. |
| Cobros | Anulado | Cobro dejado sin efecto. |
| Cobros | Por banco | El medio de ingreso es bancario. |
| Cobros | Depositado | El efectivo se trasladó al banco. |
| Depósitos | Sin comprobante | Falta registrar el comprobante del traspaso. |
| Depósitos | Sin acreditar | Falta ver el crédito en el extracto. |
| Depósitos | Acreditados | Se encontró el crédito. |
| Depósitos | Cancelados | Traspaso deshecho. |
| Tipos de arancel | Inactivo | Tipo deshabilitado. |
| Precios | Vigente | Precio actual. |
| Precios | Programado | Precio futuro. |
| Precios | Anterior | Precio vencido. |
| Precios | Anulado | Precio dejado sin efecto. |

«Por banco» y «Depositado» describen el medio o la ubicación del dinero y van junto al estado del cobro: «Pagado · Por banco».

## Multas

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Expedientes | Con deuda | Alguna multa conserva saldo. |
| Expedientes | Sin deuda | Ninguna multa conserva saldo. |
| Expedientes | Con excedente | Se pagó por encima de la obligación. |
| Deuda de una multa | Pendiente | Conserva el saldo completo. |
| Deuda de una multa | Con pagos parciales | Se pagó parte; aún queda saldo. |
| Deuda de una multa | Cancelada | El saldo de deuda es cero. |
| Deuda de una multa | Sin efecto | La obligación es cero. |
| Deuda de una multa | En recurso | Un recurso abierto congela la multa. |
| Atención, en la ficha de la multa | Con excedente | Se pagó por encima de la obligación. |
| Atención, en la ficha de la multa | Pago a regularizar · cheque rechazado | Un cheque rechazado sin reemplazo requiere resolución. |
| Recursos | En trámite | Espera resolución y congela la multa. |
| Recursos | Prosperó | Recurso aceptado. |
| Recursos | No prosperó | Recurso rechazado. |
| Recursos | Sin efecto · pago descartado o anulado | Se descartó o anuló, antes del veredicto, el pago que lo sostenía. |
| Pagos | Informado | Comunicado; falta verificar el crédito. |
| Pagos | Aplicado | Verificado e imputado a la deuda. |
| Pagos | Descartado | Informe de pago que no correspondía o se corrigió. |
| Pagos | Anulado | Cobro aplicado que después se revirtió. |
| Pagos, clasificación | Cancelatorio | Cubre el saldo. |
| Pagos, clasificación | En término | Se efectuó dentro del plazo. |
| Pagos, clasificación | Fuera de término | Se efectuó después del plazo. |
| Pagos, clasificación | Término a revisar | No hay datos suficientes para determinarlo. |
| Movimientos anteriores | Pendiente de revisión | Pago o devolución histórica sin validar. |
| Movimientos anteriores | Validado | Dato histórico aceptado. |
| Movimientos anteriores | Descartado | Dato histórico rechazado. |
| Lugar del cobro | En la caja | Dinero bajo custodia de caja. |
| Lugar del cobro | Depositado, sin acreditar | Se depositó; falta crédito bancario. |
| Lugar del cobro | Acreditado en el banco | Crédito identificado. |
| Lugar del cobro | Cheque rechazado | El cheque no se cobró. |
| Cheques rechazados | A regularizar | Falta resolver el cobro. |
| Cheques rechazados | Regularizado | Hubo reemplazo. |
| Cheques rechazados | Cobro anulado | Se deshizo el cobro. |
| Situación bancaria del cheque | Banco: no corresponde | No requiere cotejo bancario. |
| Situación bancaria del cheque | Revisión bancaria pendiente | Falta verificar lo ocurrido en el banco. |
| Situación bancaria del cheque | Rechazo con acreditación previa: revisión bancaria pendiente | Hubo crédito previo; falta revisar el eventual débito. |
| Situación bancaria del cheque | Banco: débito registrado | El débito del rechazo quedó asentado. |
| Rendiciones | Cerrado | Mes cerrado; permite emitir la rendición. |
| Rendiciones | Reabierto | Mes reabierto; la rendición espera un nuevo cierre. |

«Pagos informados», «Por depositar», «Sin acreditar» y «Rechazos» son pestañas de trabajo pendiente, no estados adicionales. La ficha separa el **estado de la deuda** de lo que pide **atención**: una deuda «Cancelada» puede tener todavía un cheque rechazado por resolver.

## Personas, cuentas y configuración

| Pantalla | Estado | Qué indica |
| --- | --- | --- |
| Personas | Activa | Persona habilitada. |
| Personas | Inactiva | Persona deshabilitada. |
| Usuarios | Activo | Puede ingresar al sistema. |
| Usuarios | Inactivo | No puede ingresar. |
| Usuarios | Clave provisoria | Debe cambiar la contraseña. |
| Cuentas bancarias | Inactiva | Deshabilitada para nuevas operaciones. |
| Cuentas de Caja | Predeterminada | Se usa por defecto. |
| Cuentas de Caja | Dada de baja | Terminó su vigencia. |
| Mi cuenta | Esta sesión | Sesión actual del usuario. |
| Mi cuenta | Correo no verificado | Falta confirmar el correo electrónico. |

Inicio muestra cantidades derivadas de estos estados; no agrega estados de negocio.

## Fuentes principales

- Haberes: [`InstallmentStages.php`](../app/Modules/Haberes/Support/InstallmentStages.php), [`installment-stage.ts`](../resources/js/features/haberes/installment-stage.ts), [`haber-status.ts`](../resources/js/features/haberes/haber-status.ts), [`AllInstallmentRowData.php`](../app/Modules/Haberes/Data/AllInstallmentRowData.php)
- Banco y Caja: [`banco/movimientos/index.tsx`](../resources/js/pages/banco/movimientos/index.tsx), [`caja/calendario.tsx`](../resources/js/pages/caja/calendario.tsx)
- Aranceles: [`cobros.tsx`](../resources/js/pages/aranceles/cobros.tsx), [`depositos.tsx`](../resources/js/pages/aranceles/depositos.tsx)
- Multas: [`labels.ts`](../resources/js/features/multas/labels.ts)
