# Instrucciones del proyecto

Al crear o modificar una pantalla, consultá la [guía de interfaz](docs/interfaz.md).
Si se acuerda una nueva convención compartida entre pantallas, documentala en esa guía.

## Antes de crear un commit

- Revisá el diff y ejecutá `git diff --check`.
- Corré las verificaciones que correspondan a los archivos modificados según la
  sección «Verificación» de `../CLAUDE.md`. Para cualquier cambio en
  `resources/js`, ejecutá `composer ci:frontend` completo: ESLint, Prettier,
  TypeScript y Vitest.
- Corregí los errores y repetí los chequeos afectados antes de crear el commit.
  No consideres suficiente que pase solo ESLint si el comando de CI incluye
  otras verificaciones.
