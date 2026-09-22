{{--
    El molde de las planillas de pendientes.

    ── Qué es este papel ───────────────────────────────────────────────

    Una hoja de trabajo, no un comprobante. No se numera, no sale de una
    serie y no prueba nada: dice qué había pendiente en el momento en que
    se pidió. Por eso el encabezado lleva fecha, hora y usuario, y por eso
    el pie aclara que lo que se tilde acá no reemplaza al recibo.

    ── Lo que dompdf no hace ───────────────────────────────────────────

    **Nada de `<colgroup>`**: `Table::normalize()` descarta los frames
    `table-column` sin mirarles el ancho. Las medidas viajan en los `<th>`,
    que es donde dompdf sí las lee.

    **Zebra dibujada por Blade y no por `:nth-child`.** El selector está
    implementado a medias y el resultado depende de si hay `tbody` en el
    árbol; `$loop->even` decide lo mismo antes de que el CSS opine.

    **El `<thead>` se repite solo** en cada página, y es lo único de esta
    plantilla que se repite: para el pie haría falta `position: fixed` —que
    acá no se comporta como uno espera— o habilitar PHP dentro de dompdf.
    Entre un pie en cada hoja y no tocar esa llave, se elige lo segundo: el
    pie va una vez, al final.

    Medidas en milímetros sobre A4.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $planilla->title }}</title>
    <style>
        @page {
            margin: 10mm 12mm;
            size: {{ $planilla->landscape ? '297mm 210mm' : '210mm 297mm' }};
        }

        body {
            /*
             * **La planilla se compone sobre el ancho de su hoja menos los márgenes.**
             *
             * dompdf le descuenta a la hoja los márgenes de `@page` y
             * compone sobre lo que queda; el navegador que muestra la vista
             * previa no sabe nada de `@page` y compone sobre el ancho del
             * iframe. Cuando los dos números no coinciden, lo declarado en
             * milímetros sale de un tamaño en pantalla y de otro en el
             * papel, y lo declarado en porcentaje se corre.
             *
             * Declararlo acá es lo que hace que lo que se ve sea lo que se
             * imprime. **Sale del `@page` de arriba: si cambian esos
             * márgenes o el `size`, este número cambia con ellos.**
             *
             * Esta es la única que todavía no tiene visor —no figura en
             * `same_origin_frame_routes`—, así que hoy la lee solo dompdf.
             * Va declarada de todos modos para que el día que lo tenga no
             * arranque descalibrada, y sigue a la orientación: apaisada son
             * los 297 de la hoja menos los mismos márgenes.
             */
            width: {{ $planilla->landscape ? '273mm' : '186mm' }};
            margin: 0 auto;
            /* El papel es blanco y hay que decirlo: dompdf lo asume, pero el
               navegador que muestra la vista previa no, y en modo oscuro
               pintaba el lienzo negro con la tinta encima. */
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            color: #111;
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; padding: 0; }

        .membrete { font-size: 7pt; line-height: 1.45; }
        .membrete .organismo { font-size: 9pt; font-weight: bold; }

        .generacion { text-align: right; font-size: 7pt; line-height: 1.45; }

        .titulo {
            margin: 4mm 0 0;
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            letter-spacing: 0.06mm;
        }

        .subtitulo { margin-top: 1.2mm; text-align: center; font-size: 8.5pt; }

        .filtros {
            margin-top: 1.2mm;
            text-align: center;
            font-size: 7.5pt;
            font-style: italic;
            color: #444;
        }

        .datos { margin-top: 5mm; }
        .datos th {
            border: 0.25mm solid #111;
            background: #e8e8e8;
            padding: 1.4mm 1.6mm;
            font-size: 7.5pt;
            font-weight: bold;
            text-align: left;
            /* Los rótulos no se parten: una columna angosta que envuelve
               «N° operación» en dos renglones descuadra toda la fila. */
            white-space: nowrap;
        }
        .datos td {
            border: 0.25mm solid #111;
            padding: 1.3mm 1.6mm;
            vertical-align: middle;
        }
        .datos .par td { background: #f4f4f4; }

        .der { text-align: right; }
        .centro { text-align: center; }

        /* El cuadrito para tildar, dibujado con borde y no con un carácter:
           el glifo ☐ depende de que la fuente embebida lo traiga, y cuando
           no lo trae sale un rectángulo relleno que parece ya tildado. */
        .casilla {
            width: 3.4mm;
            height: 3.4mm;
            border: 0.25mm solid #111;
            margin: 0 auto;
        }

        .vacio {
            margin-top: 5mm;
            padding: 6mm;
            border: 0.25mm dashed #777;
            text-align: center;
            font-size: 9pt;
            color: #444;
        }

        .totales { margin-top: 3mm; }
        .totales td { padding: 0.8mm 1.6mm; font-size: 8.5pt; }
        .totales .rotulo { text-align: right; font-weight: bold; }
        .totales .valor {
            width: 30mm;
            text-align: right;
            font-weight: bold;
            border-top: 0.4mm solid #111;
        }

        .pie {
            margin-top: 6mm;
            padding-top: 1.6mm;
            border-top: 0.25mm solid #777;
            font-size: 7pt;
            color: #444;
        }
    </style>
</head>
<body>

<table>
    <tr>
        <td class="membrete" style="width: 55%;">
            Ministerio de Gobierno<br>
            Derechos Humanos y Trabajo<br>
            <span class="organismo">Gobierno de Salta</span><br>
            Secretaría de Trabajo
        </td>
        <td class="generacion">
            Generada el {{ $planilla->generatedAt->copy()->timezone(config('app.display_timezone'))->format('d/m/Y') }}
            a las {{ $planilla->generatedAt->copy()->timezone(config('app.display_timezone'))->format('H:i') }}
            @if ($planilla->generatedBy)
                <br>por {{ $planilla->generatedBy }}
            @endif
        </td>
    </tr>
</table>

<div class="titulo">{{ $planilla->title }}</div>

@if ($planilla->subtitle)
    <div class="subtitulo">{{ $planilla->subtitle }}</div>
@endif

@if ($planilla->filters)
    <div class="filtros">{{ $planilla->filters }}</div>
@endif

@if ($planilla->rows === [])
    <div class="vacio">{{ $planilla->emptyMessage }}</div>
@else
    <table class="datos">
        <thead>
            <tr>
                @foreach ($planilla->columns as $columna)
                    <th
                        class="{{ $columna->align->value === 'right' ? 'der' : ($columna->align->value === 'center' ? 'centro' : '') }}"
                        @if ($columna->width) style="width: {{ $columna->width }};" @endif
                    >{{ $columna->label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($planilla->rows as $fila)
                <tr class="{{ $loop->even ? 'par' : '' }}">
                    @foreach ($planilla->columns as $indice => $columna)
                        <td class="{{ $columna->align->value === 'right' ? 'der' : ($columna->align->value === 'center' ? 'centro' : '') }}">
                            @if ($columna->tickBox)
                                <div class="casilla"></div>
                            @else
                                {{ $fila[$indice] ?? '' }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($planilla->totals !== [])
        <table class="totales">
            @foreach ($planilla->totals as $rotulo => $valor)
                <tr>
                    <td class="rotulo">{{ $rotulo }}</td>
                    <td class="valor">{{ $valor }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endif

<div class="pie">
    {{ $planilla->footnote ?? 'Las marcas de esta hoja son control interno y no reemplazan el recibo.' }}
</div>

</body>
</html>
