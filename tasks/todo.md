# Correcciones de cierre, permisos y experiencia — en curso

Fecha: 2026-09-08.

Objetivo: aplicar todos los hallazgos de la segunda auditoría sin alterar la
identidad visual ni los circuitos que ya funcionan.

- [x] Exigir un arqueo diario vigente en PHP y PostgreSQL, también al recerrar.
- [x] Completar el flujo de reapertura, reconteo, revisión y nuevo cierre.
- [x] Confirmar la revisión irreversible del arqueo con sus importes visibles.
- [x] Filtrar cada bloque de Inicio por los permisos que protegen sus datos.
- [x] Aclarar la cronología de movimientos y agrupar las colas por propósito.
- [x] Mejorar targets táctiles, mensajes asociados y detalles visuales.
- [x] Agregar regresiones y ejecutar la verificación completa.

Verificación final:

- `vendor/bin/pint --parallel --test`
- `vendor/bin/phpstan analyse --memory-limit=1G`
- `npm run format:check && npm run lint:check && npm run types:check`
- `npm run test` — 34 pruebas.
- `npm run build`
- `php artisan test --compact` — 795 pruebas, 3.573 aserciones.

---

# El tablero de inicio — reseña de la tanda

Fecha: 2026-09-07.

Objetivo: mover `/dashboard` a `/inicio` y convertir el tablero en la pantalla operativa que
pretendía ser, con números reales y carga diferida.

## Por qué

El tablero se construyó cuando los módulos de dominio todavía no existían. Sus seis colas
declaraban `count = null` **a mano**, «Últimos movimientos» era un párrafo fijo y la búsqueda
global estaba deshabilitada. Hoy Banco, Órdenes, Egresos, Caja y Ledger operan, así que la
pantalla que abre la sesión todas las mañanas era la única que seguía siendo un maniquí.

Arrastraba además dos incoherencias: la ruta en inglés cuando todas las demás están en español
(`/personas`, `/caja`, `/banco`, `/mi-cuenta`), y el controlador en `App\Modules\Shared`, que por
regla de arquitectura **no puede leer** Ledger, Banking ni Haberes — exactamente los cuatro
módulos que un tablero necesita.

## Decisiones tomadas en esta tanda

### 1. El controlador sube a la capa de aplicación

`app/Modules/Shared/Http/Controllers/DashboardController.php` →
`app/Http/Controllers/InicioController.php`.

No es cosmética: `tests/Arch/ModuleBoundariesTest.php` hace fallar en CI cualquier uso de Ledger,
Banking o Haberes desde Shared. Una pantalla que compone los cuatro pertenece a la capa que está
**arriba** de los cuatro, junto a `Settings/`. El docblock del controlador lo dice para que no
vuelva. Los DTO (`WorkQueueData`, `CashBoxSummaryData`, `RecentAccessData`) se quedaron en
`Shared/Data`: no nombran ningún módulo y siguen siendo vocabulario transversal.

### 2. Cada regla vive en el módulo que la posee

Como *scope* de Eloquent, el mismo idioma de `Expediente::paraListado()` y `CashBox::active()`:

| Cola | Regla |
|---|---|
| `fondos_sin_identificar` | `BankTransaction::sinIdentificar()` — crédito + `pending` |
| `ordenes_en_saf` | `PaymentOrder::enSaf()` — `status = sent` |
| `egresos_por_validar` | `Disbursement::porValidar()` — `ready_for_validation` |
| `caja_sin_cerrar` | `Ledger\Support\CashDaysPendingClosing` |

El scope del banco cuenta `pending` y no `pending + partial` **para que el número y el enlace
digan lo mismo**: la pantalla de movimientos filtra por un solo estado, y una tarjeta que dice
cinco y muestra tres es peor que una que dice tres.

### 3. Las dos colas caras no se reimplementaron en SQL

`cuotas_sin_orden` y `beneficiarios_sin_cbu` dependen del canal (mostrador o transferencia), de la
financiación neta de reversiones y del CBU verificado. Reescribir eso en una consulta habría sido
una segunda fuente de verdad que discreparía con la tarjeta del expediente en la primera
reversión.

`Haberes\Support\PendingOrderQueues` parte el trabajo en dos: una consulta agregada recorta un
superconjunto barato —cuotas vigentes, financiadas, sin Orden vigente— y sobre ese puñado
pregunta caso por caso a `PaymentOrderEligibility`, con los medios resueltos en lote por
`InstallmentFunding::mediumForMany()`. Las dos colas salen de la **misma pasada**, con tope de 250
candidatos y `hasMore` cuando se alcanza.

### 4. El click lleva a las filas, o a los casos

El tablero prometía que el número lleva exactamente a esos casos. Banco y Caja tienen pantalla
filtrada; las colas de Haberes no —el estado de una Orden vive adentro del expediente—. Ahí la
cola trae hasta cuatro `QueueSampleData` y cada uno es su propio enlace al haber. **Ninguna
tarjeta manda al listado completo**: eso sería devolver la pregunta.

### 5. Los esqueletos tapan una espera real

`Inertia::defer()` en tres grupos —`colas`, `caja`, `movimientos`—, todos con `rescue: true`. La
primera respuesta trae encabezado, búsqueda, accesos rápidos y actividad propia; lo caro llega
después. Los esqueletos de `features/inicio/components/skeletons.tsx` copian la geometría de su
bloque para que la llegada de los datos no mueva nada, y heredan el `motion-reduce:animate-none`
de `ui/skeleton`.

### 6. Orden del circuito, no de urgencia

Se evaluó reordenar las tarjetas por tono y se descartó: la pantalla se mira todos los días y
reordenarla según qué esté en rojo hoy obliga a leerla entera cada vez. **El color dice qué
apura; el lugar dice qué es.** Lo que sí cambió: una cola en cero pasa a tono `done` y deja de
ser un enlace — no hay listado vacío al que mandar a nadie.

### 7. La búsqueda global dejó de ser decorativa

Es un `<form method="get">` contra `haberes.index`, que ya sabe buscar por número, carátula,
empleador, beneficiario y documento. El resultado queda en una dirección que se puede compartir.

## Qué se agregó a la pantalla

- **Estado de la caja del día**: los cuatro saldos leídos del mismo `CashBalance` que usa Caja,
  más apertura, arqueos y cierre. Detrás de `caja.ver`.
- **Últimos movimientos**: los ocho últimos `financial_events` posteados, con las reversiones
  tachadas en lugar de ocultas.
- **Accesos rápidos**: las tres altas con las que empieza el trabajo, filtradas por permiso.

## Verificación

| Control | Resultado |
|---|---|
| `php artisan test` | 789 pasan, 0 fallan; 3.557 aserciones |
| `phpstan` | 0 errores |
| `pint --test` | limpio |
| `tsc --noEmit` | limpio |
| `eslint .` | limpio |
| `prettier --check` | limpio |
| `vitest` | 34 pasan |

`tests/Feature/InicioTest.php` (11 casos) reemplaza a `DashboardTest`. Cubre qué cuenta cada cola
—que un débito no entra en los fondos por identificar, que un crédito `partial` tampoco, que la
cuota de mostrador no entra en las cuotas sin Orden— y **qué llega cuándo**: la primera respuesta
no trae las props diferidas y la recarga parcial sí. El caso del CBU prueba el cruce completo: la
misma cuota pasa de «no se puede emitir» a «se puede y falta hacerlo» al verificar la cuenta, sin
que nadie la toque.

## Lo que queda

- **Filtro por estado en el listado de haberes.** Es lo que permitiría que las colas de Órdenes y
  egresos tengan enlace propio en vez de muestras. Hoy las muestras alcanzan; con volumen real,
  no.
- **`EscenariosDemoSeeder` no corre dos veces**: falla por FK contra `payment_orders` si la base
  ya tiene datos. No es de esta tanda, pero apareció al preparar la verificación.
- **Volumen por caja** cuenta expedientes no anulados y da 100 % siempre, porque solo Haberes
  tiene circuito. El día que opere una segunda caja, la proporción empieza a significar algo.

---

# Robustez multimoneda, accesibilidad y escalabilidad — completado

Fecha: 2026-09-15.

Objetivo: resolver todos los hallazgos de la auditoría actual sin cambiar la
identidad visual ni debilitar los invariantes contables.

- [x] Separar correctamente los cierres y las colas por moneda en PHP y PostgreSQL.
- [x] Registrar en auditoría al actor explícito de cada operación de dominio.
- [x] Paginar el maestro de personas y adaptar sus resultados a móvil.
- [x] Eliminar sugerencias obsoletas al acortar una búsqueda.
- [x] Hacer navegables por teclado y adaptables a móvil las tablas operativas señaladas.
- [x] Reducir la concentración de responsabilidades en los componentes grandes.
- [x] Agregar regresiones y ejecutar la verificación completa.

Verificación final: 872 pruebas PHP (4.021 aserciones), 38 pruebas frontend,
Pint, PHPStan, ESLint, Prettier, TypeScript y build de producción, todo correcto.
La migración multimoneda quedó aplicada en la base local.

---

# Planillas de pendientes: mostrador, depósitos y transferencias — completado

Fecha: 2026-09-20.

Objetivo: que el área pueda imprimir las tres colas que el sistema ya conocía pero no sabía
mostrar en papel, y darle pantalla a las dos que no la tenían.

- [x] Molde común de planillas en `app/Support/Pdf` (`Worksheet`, `WorksheetData`,
      `WorksheetColumn`, `WorksheetAlign`) + `resources/views/pdf/planilla.blade.php`.
- [x] Planilla de depósitos esperando acreditación, colgada de `/depositos`, que ya era esa cola.
- [x] Pantalla `/haberes/egresos` con dos solapas: por entregar (mostrador, efectivo) y
      transferencias sin confirmar.
- [x] `CounterPayoutQueue`, que resuelve la cola de mostrador preguntándole a
      `DisbursementEligibility` en vez de reescribir el criterio en SQL.
- [x] `Disbursement::scopeSinConfirmar()`: los tres estados intermedios del circuito bancario.
- [x] Las dos planillas del egreso, con casilla de control y pie que aclara que no reemplaza
      al recibo.
- [x] Barra lateral, breadcrumbs y enlace desde la cola «Egresos por validar» del tablero, que
      contaba sin llevar a ningún lado.
- [x] Regresiones: `PantallaDeEgresosTest`, `PlanillasDeEgresosTest`,
      `PlanillaDeDepositosPendientesTest` y un caso end-to-end en `EgresoPorTransferenciaTest`.

Verificación final: `composer ci:check` verde — 981 pruebas PHP (4.728 aserciones) contra
PostgreSQL real, 40 pruebas de front, Pint, PHPStan sin baseline, ESLint, Prettier y TypeScript.
El desvío respecto del DER quedó anotado como punto 50 de `Correcciones-al-DER-pendientes.md`.

## Revisión

**Lo que cambió respecto del plan.** Dos cosas, las dos hacia lo más correcto:

- El total de cada cola se suma en el controlador y baja como cadena. La primera versión de la
  pantalla lo sumaba con `Number` en el front, que es un `float` sobre dinero de terceros: lo
  prohíbe la regla 3 y ahora hay un test que lo fija.
- El filtro de la cola de mostrador terminó siendo `canPay()` en vez de la lista de condiciones
  que preveía el plan. Es el mismo método que decide si la tarjeta de la cuota muestra el botón
  de entregar, así que las dos no pueden divergir.

**Lo que la base enseñó por el camino.** Los `CHECK` de `disbursements` rechazaron los egresos
inventados de los tests: una transferencia exige su Orden, un confirmado exige asiento y fecha.
El caso end-to-end se mudó a `EgresoPorTransferenciaTest`, que es donde vive ese escenario, y el
caso del scope quedó documentando por qué escribe los egresos con método `cash`.

**Pendiente de mirar por el área.** La pantalla `/haberes/egresos` con datos reales: acá se
verificó por tests y por el bundle compilado, pero nadie la abrió todavía contra `sacst_dev`.

---

# El comprobante que no corresponde: aviso en los dos extremos — completado

Fecha: 2026-09-20.

Un comprobante de depósito se cargó en un expediente cuyas cuotas se cobran en efectivo, sin
apuntar a ninguna cuota. Quedó esperando semanas sin que nada lo señalara, y de paso hizo creer
que la planilla de mostrador estaba mostrando de más.

- [x] El alta del comprobante avisa cuando la cuota —o el haber entero— no espera transferencia.
      Avisa y deja seguir: el medio previsto es una expectativa, y el empleador puede haber
      depositado igual.
- [ ] ~~La pantalla del haber avisa cuando su expediente tiene comprobantes esperando sin cuota
      asignada.~~ **Descartado por el área.** Se construyó y se sacó el mismo día: el aviso salía
      en los tres haberes del expediente y decía «este haber» sobre un papel que no era de
      ninguno. Peor, en los dos que cobran por mostrador la alarma quedaba en el lugar
      equivocado. La cola de `/depositos` y el contador del tablero ya lo muestran, y alcanzan.
- [x] `haberesOf()` manda el medio efectivo de cada cuota y el **ordinal** del haber —no su id,
      que es el bug que se arregló esta misma mañana—.
- [x] Tres regresiones en `PantallasDeDepositosTest`.

Verificación: `composer ci:check` verde — 986 pruebas PHP (4.766 aserciones).

## Revisión

**El diagnóstico inicial estaba equivocado.** La recomendación original decía que los nombres
«Cargar comprobante de depósito» y «Depositar en el banco» se prestaban a confusión por
parecerse. Mirando el código, los dos botones **nunca conviven**: el primero solo aparece en
cuotas con transferencia prevista, el segundo solo en las de mostrador. El agujero real está en
el alta por expediente, que ofrece todos los haberes sin mirar el medio y sin advertir nada.
Renombrar los botones no habría servido de nada.

**El aviso del haber se descartó, y la lección es de criterio.** Se puso en la pantalla del
haber porque era la que se estaba tocando, no porque fuera su lugar: un comprobante sin cuota no
es de ningún haber, así que el mismo papel avisaba tres veces y el texto afirmaba una pertenencia
que no existía. La alternativa sensata era la pantalla del expediente; el área eligió no tener
aviso, porque la cola ya lo muestra. Construir en el lugar donde uno está parado en vez de donde
el dato vive es lo que hay que evitar la próxima.

**Pendiente para el área.** El comprobante de $100.000 del 14/09 del expediente 125959/2026
coincide en importe con la cuota de March Meilion, que es la única del expediente que espera
transferencia. Probablemente sea de esa cuota y haya que apuntarlo; lo confirma el área, no el
sistema.

---

# Etapa y recorrido de la cuota — completado

Fecha: 2026-09-20.

Mirando una cuota no había forma de saber en qué punto del circuito estaba ni cuándo pasó cada
cosa. El dato existía repartido entre el recibo, el traslado, la Orden y el egreso, y nunca se
mostraba junto: una cuota cobrada en efectivo que se depositó seguía diciendo «Efectivo» a secas.

- [x] `InstallmentStage` (13 etapas) e `InstallmentStages`, que las deriva **de hechos** y en
      lote. No usa `DisbursementEligibility`: esa contesta si se puede pagar y sigue siendo la
      única fuente de eso.
- [x] `InstallmentBatch`: las cinco consultas que toda lista de cuotas necesita, en un lugar.
- [x] El detalle del expediente y el listado paginado reciben lo que antes descartaban.
- [x] La tarjeta cambia «Monto total» —que repetía el importe del concepto— por la etapa, y suma
      la sección «Recorrido» con los nueve hitos.
- [x] El listado compacto muestra la etapa en vez del `workflow_status` crudo.
- [x] `(→ Depósito)` junto al medio, con su variante «en tránsito».

Verificación: `composer ci:check` verde — 994 pruebas PHP (4.789 aserciones) y 49 de front.
Contra `sacst_dev`, el expediente 125959/2026 muestra las tres etapas distintas que tiene:
«Sin financiar», «En caja» y «En el banco».

## Revisión

**El paréntesis se entregó roto y se arregló acá.** `installment-list.tsx` nunca recibía
`cashTransfer`: `ExpedienteListItemData::fromModel()` llamaba a `HaberListItemData::fromModel()`
pasándole solo los enlaces de tickets, así que `transfers`, `receipts`, `funded` y `mediums`
quedaban en sus valores por defecto. El paréntesis era código muerto ahí, y el renglón «entró
por X» lo era desde antes. No fallaba nada: la pantalla decía menos de lo que creía, que es la
clase de defecto que no aparece en ningún log.

**Una premisa del código no se sostenía.** Un comentario de `installment-card.tsx` afirmaba que
el área había propuesto varios conceptos por cuota con su importe, y sobre eso se justificaba el
«Monto total». El DER v4.3 define la cuota con «importe, concepto, etiqueta y medio propios» —uno
de cada— y como unidad *financiable, ordenable y pagable*. Varios importes por cuota serían pago
parcial, que en Haberes no ocurre. El campo se sacó.

**La etapa no decide botones.** Deriva dónde está el dinero; si se puede operar lo sigue
contestando `DisbursementEligibility`. Una cuota bloqueada por etiqueta está «en caja» igual: el
dinero está ahí, lo que no se puede es entregarlo. Mezclarlas haría que la etiqueta del listado
contradiga al botón de la tarjeta.

---

# El medio previsto pasa a ser obligatorio — completado

Fecha: 2026-09-20.

Una cuota sin medio declarado no se puede leer: no dice si se cobra por mostrador o si se espera
un depósito, y de eso depende el circuito entero. El área confirmó que al cargar el expediente
siempre se sabe.

- [x] `required` en el alta (`StoreHaberRequest`) y en la corrección (`SaveInstallmentRequest`).
- [x] Columna `NOT NULL` y `CHECK` sin el nulo, con migración que completa lo existente.
- [x] El selector ya no ofrece «Sin definir», ni en el alta ni en la edición.
- [x] Un test por cada lado del invariante: el rechazo del formulario y el de la base.

Verificación: `composer ci:check` verde — 996 pruebas PHP (4.793 aserciones), 49 de front.
La migración corrió en `sacst_dev`: no queda ninguna cuota sin medio.

## Revisión

**El `ALTER TABLE` falló la primera vez.** Los `UPDATE` que completan las cuotas viejas dejan
triggers diferidos pendientes, y PostgreSQL no deja tocar la tabla mientras los tenga:
«no se puede hacer ALTER TABLE porque tiene eventos de trigger pendientes». Se resuelve con
`SET CONSTRAINTS ALL IMMEDIATE` antes del `ALTER`, que es el mismo recurso que el `CLAUDE.md`
documenta para los tests de guardas diferidas.

**El `NOT NULL` dejó al descubierto cinco ramas muertas**, todas señaladas por PHPStan: el
respaldo a `Cash` de `IssueIncomeReceipt`, el retorno nulo de `effectiveMedium`, tres `?->` sobre
el medio y los «Sin definir» de la tarjeta y del listado. Es la misma clase de código inalcanzable
que apareció esta mañana con el paréntesis del listado del expediente.

**Costo en los tests:** 9 archivos armaban cuotas sin medio y dejaron de pasar. No es ruido, es
la regla nueva haciéndose sentir donde correspondía.

---

# La suite tarda la mitad — completado

Fecha: 2026-09-20.

`composer ci:check` tardaba entre 8 y 9 minutos y eso frenaba el ritmo: en una sola jornada hubo
que correrlo cinco veces. Casi todo era Pest, y dentro de Pest el costo no eran las migraciones
ni PostgreSQL —`RefreshDatabase` migra una vez por proceso y revierte por transacción— sino los
seeders corriendo **en cada test**.

- [x] `CatalogosSeeder`: permisos, series, cajas y etiquetas, sembrados una sola vez por proceso
      vía `$seeder` de `TestCase`.
- [x] `operador()` deja de sembrar los permisos en cada llamada: 617 ejecuciones a cero.
- [x] 56 llamadas quitadas de los `setUp()`, en 36 archivos.
- [x] `DatabaseSeeder` delega en el catálogo, para que la lista viva en un solo lugar.

## El número

| | Antes | Después |
|---|---|---|
| Suite completa (996 tests) | 480 s | **215 s** |
| `AgregarHaberTest` (29 tests) | 38,3 s | 7,8 s |
| `GestionarCuotasTest` (11 tests) | 6,9 s | 4,1 s |

Con **las mismas 4.793 aserciones**, que era la condición: si alguna cambiaba, significaba que un
test había dejado de probar lo suyo por tener datos que antes no tenía.

## Revisión

**Los seeders de demo no se tocaron.** `HaberesDemoSeeder` y compañía crean expedientes y cuotas
—datos de negocio, no configuración— y hay tests que dependen de arrancar sin ellos, como
`PantallasDeDepositosTest::test_la_cola_arranca_vacia_y_abre`. Sembrarlos globalmente habría
obligado a revisar 996 aserciones para saber cuáles se rompían, por un ahorro de un minuto.

**El primer intento fue un reemplazo automático, y estuvo mal.** Borró las llamadas al seeder que
estaban dentro del cuerpo de los tests, donde sembrar no era escenografía sino **el acto bajo
prueba**: `test_volver_a_sembrar_no_pisa_los_permisos_configurados` quedó sin sembrar nada.
Fallaron dos tests y por ahí se detectó, pero el riesgo real era el contrario —los que hubieran
seguido pasando sin probar nada—. Se deshizo el cambio en los tests y se rehizo acotado a los
bloques `setUp()`, verificando después que las diez llamadas de adentro de los tests siguieran
en su lugar.
