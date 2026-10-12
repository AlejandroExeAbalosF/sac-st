# Numeración de comprobantes

En **Configuración → Numeración de comprobantes** se configura por separado el recibo de ingreso, el recibo de egreso y la Orden de Pago. Requiere `numeracion.gestionar`, concedido por defecto al administrador. Los archivos derivados conservan la identificación del documento al que pertenecen; el número de talonario sigue siendo un dato separado.

## Opciones

- Prefijo numérico de 1 a 12 dígitos, incluidos ceros iniciales.
- Mínimo de dígitos del prefijo y del correlativo: de 1 a 12. El relleno agrega ceros; nunca recorta un número.
- Separador: barra (`/`) o guion (`-`).
- Próximo correlativo: de 1 a 999999999999.
- Motivo obligatorio, que queda en la auditoría junto con el usuario y los valores anteriores y nuevos.

La pantalla muestra una vista previa. Cambiar el prefijo no reinicia automáticamente el número: para una serie con prefijo nuevo se puede indicar explícitamente desde dónde empezar. Si el prefijo ya tuvo emisiones, el próximo número debe superar el último utilizado con ese prefijo, incluso si pertenece a una versión histórica. Los prefijos de otro tipo de comprobante no pueden reutilizarse.

## Historia y concurrencia

Antes de la primera emisión se modifica la configuración de la misma serie. Si ya hay documentos, un cambio de prefijo, dígitos o separador desactiva esa versión y crea otra. Modificar solamente el próximo número no crea una versión. Los comprobantes conservan su `document_series_id` y su `formatted_number`; las versiones históricas se pueden consultar en la pantalla.

El `code` interno (`0010`, `0020`, `0030`) identifica el circuito de emisión y se mantiene estable entre versiones. `display_prefix` define el prefijo visible. Solo una versión por tipo puede estar activa. La emisión y la configuración comparten un bloqueo transaccional antes de elegir la versión. Un formulario desactualizado se rechaza si hubo una emisión u otro cambio desde que se abrió.

La base impide modificar el formato de una serie utilizada, retroceder sobre sus números emitidos y repetir números completos. La unicidad del talonario abarca todas las versiones del mismo tipo de recibo. Los comprobantes anulados también consumen su número.

No se ofrecen plantillas arbitrarias, borrado de versiones ni reinicio automático por año. Los cambios se aplican a nuevas emisiones, no a documentos anteriores.

## Validaciones de bordes

La cobertura de `NumeracionExtremosTest` emite documentos reales de los tres tipos y fuerza la comprobación de las restricciones diferidas de PostgreSQL. Incluye ciclos A → B → A → B, prefijos equivalentes por relleno, documentos anulados, reducción de dígitos, máximo de 12 dígitos y recuperación con un prefijo nuevo, rollback de emisión y movimientos, conservación del contenido y nombre de PDFs regenerados, prefijos históricos de otro tipo y escrituras directas incompatibles con las restricciones de la base.

`NumeracionConcurrenteTest` usa procesos PHP y conexiones PostgreSQL independientes. Comprueba la espera efectiva de un bloqueo consultando `pg_stat_activity`, antes de liberar la primera transacción. Cubre configurar/emitir en ambos órdenes, dos configuraciones, dos emisiones y una configuración que revierte mientras otra espera, para ingresos, egresos y órdenes de pago. Estas pruebas reconstruyen **exclusivamente `sacst_test`**; no admiten ejecutarse contra la base de desarrollo ni producción.

Al alcanzar el límite, la emisión se rechaza sin consumir otro número ni dejar efectos parciales. El mensaje se muestra en los tres formularios de emisión; `numbering-errors.test.tsx` comprueba que se renderice como alerta, también cuando el egreso ya estaba pagado y solo falta su recibo.

## Instalación

Aplicar las migraciones y ejecutar `php artisan db:seed --class=RolesAndPermissionsSeeder` para registrar el permiso. Este seeder conserva las asignaciones existentes. El administrador puede abrir la pantalla por su autorización general; cualquier delegación a otro rol se realiza explícitamente en Roles. Regenerar rutas Wayfinder y compilar el frontend en el despliegue habitual.
