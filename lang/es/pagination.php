<?php

declare(strict_types=1);

/*
| Paginación.
|
| Las pantallas del sistema arman su propia paginación con
| `components/pagination-footer.tsx`, así que estos textos casi no se ven.
| Están igual para que un paginador que use las vistas del framework —un
| export, una respuesta JSON— no devuelva «pagination.previous».
*/

return [

    'previous' => '&laquo; Anterior',

    'next' => 'Siguiente &raquo;',

];
