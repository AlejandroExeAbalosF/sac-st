# Guía de interfaz de SAC-ST

Esta guía reúne las convenciones que comparten las pantallas del sistema.

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
