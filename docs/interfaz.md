# Guía de interfaz de SAC-ST

Esta guía reúne las convenciones que comparten las pantallas del sistema. Los
rótulos de estado de cada pantalla, con lo que indican, están en
[Estados visibles en la interfaz](estados-de-interfaz.md): un estado nuevo o un
rótulo que cambia se anota ahí en la misma tanda. Los estados que piden algo se
escriben como «lo que pasó · lo que falta» («Débito identificado · falta informe»).

## Campos de formulario en una misma fila

Al crear o modificar un formulario, revisá todas sus filas de dos o más columnas.
Incluí las filas que aparecen según una selección o los permisos de la persona.

Cada campo de la fila usa `FormRowField` como hijo directo de la misma grilla.
La etiqueta que ocupa más líneas determina la altura compartida de las etiquetas.
Los controles empiezan a la misma altura.

Dentro de cada `FormRowField`, colocá primero el control, después la ayuda si
corresponde y al final su `InputError`. Al aparecer o desaparecer un mensaje,
la fila puede cambiar de alto y los controles conservan su posición. Para un valor
calculado, usá `output`, asociá su `id` a la etiqueta mediante `htmlFor` y centrá
el valor verticalmente a la altura del input vecino.

### Comprobación en el navegador

1. En escritorio, observá el borde superior de los controles antes de validar.
   Provocá un error en un solo campo y luego corregilo. Al aparecer y desaparecer
   el mensaje, los bordes superiores de los controles de esa fila deben seguir
   alineados. Repetí la prueba con el error en otra columna.
2. Reducí el ancho hasta que una etiqueta ocupe dos líneas. Mientras los campos
   sigan uno al lado del otro, sus controles deben empezar a la misma altura.
3. En móvil, comprobá que el orden sea etiqueta, control, ayuda y error de ese
   campo, seguido de la etiqueta del siguiente.

[Componente compartido para campos en una fila](../resources/js/components/form-row-field.tsx)

## Tarjeta y estados de los campos

Agrupá los campos que se completan juntos en una sección reconocible. Usá la
superficie de tarjeta del tema (`bg-card`), blanca en el tema claro. Los controles
editables pueden compartir ese fondo si su borde los delimita con claridad.

Aplicá estas reglas a los campos de texto, las áreas de texto y los selectores.
Usá los colores del tema para que la distinción funcione en claro y oscuro.
Aplicá la clase compartida `standard-form` al formulario para reutilizar estos
estilos. En grillas de columnas desiguales, usá `className` de `FormRowField`
para definir cuántas columnas ocupa cada campo.

| Estado | Aspecto y comportamiento |
| --- | --- |
| Vacío | Conservá el borde suave habitual del control. El texto de ejemplo y los prefijos, como el signo de moneda, cuentan como campo vacío mientras falte el valor. |
| Con valor | Marcá el borde con una mezcla de `--muted-foreground` al 80 % y `--card`. Vale para datos escritos, precargados, autocompletados o seleccionados, incluido el cero. Al vaciar el campo, recuperá el borde suave. |
| Con foco | Usá un borde de `--ring` al 72 % mezclado con `--card` y un halo de 2 px de `--ring` al 25 %. Al salir del campo, recuperá el borde que corresponda a su contenido. |
| Con error | Aplicá `aria-invalid`, usá el color de error del tema y mostrá el mensaje debajo del control. En un campo habilitado, el error tiene prioridad sobre el color de foco y el de campo con valor. |
| Deshabilitado | Usá fondo `--muted`, borde `--input` y texto `--muted-foreground`, sin sombra y con opacidad completa. Aplicalo también cuando el control herede `:disabled` de un `fieldset`. |

Para comprobarlo, recorré los campos con Tab, escribí un valor, salí del campo
y volvé a vaciarlo. Repetí la prueba con un valor precargado y con un error de
validación. En ambos temas debe poder ubicarse el foco durante todo el recorrido.

## Envío, carga y movimiento

Mientras se procesa el envío, marcá el formulario con `aria-busy`, deshabilitá
los campos que se están enviando y evitá el envío repetido. En el botón, mostrá
un indicador de actividad y el texto de la acción en curso.

Si una vista previa tarda en cargar, reservá su espacio y mostrá un esqueleto de
carga (`Skeleton`) hasta que aparezca el contenido o el aviso de error. Para la
entrada de una fila nueva, usá una transición breve con `motion-safe`. Con la
preferencia de movimiento reducido, la fila debe aparecer de inmediato.

<a id="adjuntos"></a>

## Adjuntos: fotos y PDF

Los archivos que se suben —un ticket, la hoja de un talonario, un informe— se
muestran con los componentes de `resources/js/components/attachments/`. No se
arma un `<img>`, un `<iframe>` ni un enlace a `/adjuntos/{id}` por fuera de ellos.

| Dónde va | Componente |
|---|---|
| Los archivos de algo —una multa, un pago, un documento—, en mosaico | `AttachmentGallery` |
| Un archivo al costado de los datos que se copian de él | `AttachmentInlinePreview` |
| Un archivo dentro de una fila: el comprobante de una cuota, un recibo de papel | `AttachmentMini` |
| Un «Ver comprobante» en una fila de acciones | `AttachmentLink` |
| Un archivo recién elegido, antes de subirlo | `PaperPhotoDropzone` o `TicketPhotoPanel` |

Los cinco abren el mismo visor a pantalla completa (`useAttachmentViewer`), con
zoom, rotación de fotos, descarga y paso de un archivo al siguiente. Se carga
recién al abrirlo.

**El servidor manda el adjunto entero, no su id.** Cada DTO lleva
`AttachmentData` (`App\Modules\Shared\Data`), que dice dos cosas distintas:

- `kind` (`image`, `pdf` u `other`) dice cómo se muestra. Sale del MIME guardado,
  que se lee del contenido. Lo que no es foto ni PDF se descarga.
- `canOpen` dice si **quien mira** puede pedirlo: tiene `adjuntos.ver` y, si el
  archivo es reservado, `adjuntos.ver-reservados`. Lo resuelve
  `AttachmentPolicy`, la misma que usa `AttachmentController`. Con `canOpen` en
  falso la pantalla muestra que el archivo existe, con un candado, y **no pide
  nada**: ni la miniatura, que volvería con un 403. No se agrega un
  `can.viewAttachments` por pantalla.

Cuando un sujeto puede tener varios archivos, el que se muestra es **el más
reciente que no esté anulado**: los adjuntos no se reescriben, y corregir una foto
suma otra. Para armar los DTOs en lote está `Attachments`
(`App\Modules\Shared\Support`): `latestFor` para el vigente de cada sujeto,
`allFor` para todos (los anulados, marcados), `forIds` cuando el dominio ya guardó
cuál vale, y `historyFor` para cuántos tiene cada uno y cuántos anulados. Todas
resuelven una tanda en una consulta. Una relación que elige «el último» con
`latestOfMany()` lleva sus condiciones **dentro** de `ofMany`: la subconsulta del
`MAX(id)` ignora los `where` de afuera (ver `Disbursement::reportDocument`).

**Anular saca un respaldo de la vista sin borrarlo.** Solo se anulan los pares
sujeto + tipo de `Attachment::VOIDABLE` —la misma lista que
`attachment_is_voidable()` en la base— y nunca lo que generó el sistema: el ticket
del traslado, el extracto, la planilla de cierre, la rendición y el comprobante de
cobro de Aranceles son la prueba de un hecho. La base además impone que todo
adjunto nazca vigente, que se anule una sola vez sin que cambie nada más y que un
anulado no se renombre. Se anula desde el menú ⋮ de la tarjeta o del visor, con
`adjuntos.anular` y un motivo de `SharedReasonAct::VoidAttachment` (llega en la
prop compartida `attachmentReasons`, no en `motivos`). Queda en la auditoría como
`adjunto.anulado`, crítico. Al anular, el visor se cierra: la pantalla de atrás se
recarga, y su copia de los archivos ya no dice la verdad.

Donde se muestra un archivo solo —el ticket de una cuota, la foto de un papel, el
informe del egreso, la foto del talonario— va `AttachmentHistoryButton`, con el
`AttachmentHistoryData` del DTO: con el único anulado no queda miniatura, y el
historial (`GET adjuntos/historial`) es el camino para ver el motivo. Las galerías
apartan los anulados detrás de «Ver anulados».

**El archivo no se reescribe; la descripción sí se corrige.** El trigger
`attachments_immutable` deja cambiar solo `title` —compara la fila entera
menos esa columna, así que una columna nueva queda protegida sola— y no lo
deja vacío. Se corrige desde el menú ⋮ de la tarjeta o desde el lápiz del
visor, con el permiso `adjuntos.renombrar`, y la descripción anterior queda en
la auditoría (`adjunto.renombrado`). `RenameAttachment` relee la fila
bloqueada: lo que se audita es lo que de verdad se reemplazó. Si se subió el
archivo equivocado, se sube el correcto con una descripción que lo diga.

**El estado del archivo y el permiso van por separado.** `Gate::before` le
concede todo al administrador antes de llegar a `AttachmentPolicy`, así que la
policy solo responde por quien opera; si el archivo admite el cambio lo dice
el propio adjunto. El DTO y la Action exigen las dos cosas.

El campo de la descripción va marcado con `data-local-escape`: Escape cancela
la edición y no cierra el visor que lo contiene.

### PDF con pdf.js

La miniatura de un PDF es su primera hoja, y el visor dibuja todas las hojas.
Las dos cosas las hace pdf.js en el navegador; el servidor no genera miniaturas.

- pdf.js se importa siempre en forma diferida (`resources/js/lib/pdf.ts`): no
  entra en el bundle de ninguna pantalla.
- Su worker y sus recursos los copia `vite.config.ts` a `public/vendor/pdfjs/`
  al arrancar Vite y al compilar. Un Worker solo se puede crear desde el mismo
  origen, y en desarrollo Vite sirve desde otro puerto. La carpeta no se
  versiona. La copia no es un `postinstall` porque `pnpm-workspace.yaml` apaga
  los scripts de instalación.
- No usa WebAssembly (`useWasm: false`), porque la CSP no habilita
  `'wasm-unsafe-eval'`. Los PDF escaneados en JPEG 2000 o JBIG2 se decodifican
  con la versión en JavaScript, que es más lenta.
- Un PDF subido no se mira en un `<iframe>`. Por eso `frame-src` solo admite
  los documentos del propio sistema, que van por `DocumentFrame`.
- La miniatura se dibuja cuando la tarjeta entra en pantalla. Con la pestaña o
  el panel oculto, el navegador no pinta, y la miniatura espera.
