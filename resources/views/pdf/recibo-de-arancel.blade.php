{{--
    El recibo de Aranceles, en papel en blanco. Sigue el talonario del área y usa el
    marco, las casillas y el recuadro del importe de los recibos de Haberes y Multas.

    La renderiza DomPDF: flujo y tablas, sin flexbox ni variables CSS. Lo que dice sale
    de la copia congelada al emitir. Sin tope de renglones: la tabla sigue en otra
    hoja, repitiendo su encabezado, y el pie no se parte.

    Se compone sobre 196 mm en los dos lados, que es lo que deja el margen de `@page`:
    si cambia ese margen, este número cambia con él (ver recibo-de-ingreso).

    El diseño es provisorio: falta el visto bueno del área.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de Aranceles {{ $recibo->formatted_number }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        @page { margin: 7mm; size: 210mm 148mm; }

        body {
            width: 196mm;
            margin: 0 auto;
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #111;
            {{-- El navegador no conoce `@page`: el margen de arriba hay que dárselo. --}}
            @if ($enPantalla ?? false) padding-top: 7mm; @endif
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: bottom; padding: 0; }

        .marco {
            border: 0.35mm solid #111;
            border-radius: 2mm;
            padding: 3mm 4mm 2.5mm;
        }

        .institucion { font-size: 7pt; line-height: 1.4; vertical-align: top; }
        .institucion .gobierno { font-size: 10pt; font-weight: bold; }
        .direccion { font-size: 6.5pt; }
        .sello {
            width: 26mm; height: 26mm; margin: 0 auto;
            border: 0.3mm dashed #bbb; border-radius: 13mm;
            text-align: center; font-size: 5pt; color: #bbb; line-height: 1.3;
        }
        .sello span { display: block; padding-top: 11mm; }
        .tipo { font-size: 9.5pt; font-weight: bold; white-space: nowrap; }
        .numero { font-size: 10.5pt; font-weight: bold; white-space: nowrap; }
        .apagado { font-size: 6.5pt; color: #555; }
        .anulado { color: #b00; font-weight: bold; font-size: 9pt; }

        .titulo {
            font-family: DejaVu Serif, serif;
            font-size: 10.5pt; font-weight: bold; letter-spacing: 0.1mm;
        }
        .casilla {
            display: inline-block;
            width: 8mm; height: 6mm; line-height: 4.6mm;
            border: 0.3mm solid #111; border-radius: 1mm;
            text-align: center; font-weight: bold; font-size: 8pt;
        }
        .casilla.anio { width: 11mm; }

        .rotulo { font-size: 8pt; white-space: nowrap; padding-right: 1.5mm; }
        .escrito { font-weight: bold; letter-spacing: 0.15mm; border-bottom: 0.25mm dotted #111; padding-bottom: 0.3mm; }
        .fila td { padding-top: 2.2mm; }

        .tilde {
            display: inline-block;
            width: 7mm; height: 5mm; line-height: 3.8mm;
            border: 0.3mm solid #111; border-radius: 0.8mm;
            text-align: center; font-weight: bold;
        }

        .conceptos { margin-top: 2.5mm; }
        .renglones thead { display: table-header-group; }
        .renglones tr { page-break-inside: avoid; }
        .renglones th {
            font-size: 7pt; font-weight: normal; text-align: left;
            border-bottom: 0.3mm solid #111; padding: 0 1mm 0.6mm;
        }
        .renglones td {
            font-size: 7.5pt; vertical-align: top;
            border-bottom: 0.15mm dotted #888; padding: 0.7mm 1mm;
        }
        .n, .renglones th.n, .renglones td.n { text-align: right; white-space: nowrap; }

        .pie { page-break-inside: avoid; margin-top: 2mm; }
        .importe-caja {
            border: 0.35mm solid #111; border-radius: 1.5mm;
            padding: 1.3mm 1mm 1.3mm 3mm;
            font-size: 12pt; font-weight: bold; white-space: nowrap;
        }
        .en-letras::first-letter { text-transform: uppercase; }
        .firma { border-top: 0.3mm dotted #111; text-align: center; font-size: 7.5pt; padding-top: 0.6mm; }
        .nombre { text-align: center; font-size: 7.5pt; line-height: 1.25; }
        .nombre strong { font-size: 8.5pt; }
    </style>
</head>
<body>
<div class="marco">
    <table>
        <tr>
            <td class="institucion" style="width: 66mm;">
                Ministerio de Gobierno,<br>
                Derechos Humanos y Trabajo<br>
                <span class="gobierno">Gobierno de Salta</span><br>
                Secretaría de Trabajo<br>
                <span class="direccion">Bolívar 141 - Tel. 0387-4318451/54 - Salta</span>
            </td>
            <td style="width: 32mm; vertical-align: top;">
                @if ($selloUrl)
                    <img src="{{ $selloUrl }}" alt="" style="width: 26mm;">
                @else
                    {{-- Se reserva el lugar del sello en vez de imitarlo: ver recibo-de-ingreso. --}}
                    <div class="sello"><span>sello<br>del organismo</span></div>
                @endif
            </td>
            <td style="vertical-align: top; text-align: right; padding-top: 4mm;">
                <span class="tipo">RECIBO DE INGRESO N.º</span>
                <span class="numero">{{ $recibo->formatted_number }}</span><br>
                @if ($recibo->talonario_number)
                    <span class="apagado">Talonario {{ $recibo->talonario_number }}</span><br>
                @endif
                {{-- Un recibo sin valor no se puede imprimir como si lo tuviera. --}}
                @if ($recibo->status === \App\Modules\Shared\Enums\ReceiptStatus::Voided)
                    <span class="anulado">ANULADO</span><br>
                @elseif ($recibo->status === \App\Modules\Shared\Enums\ReceiptStatus::Replaced)
                    <span class="anulado">REEMPLAZADO</span><br>
                @endif
            </td>
        </tr>
    </table>

    <table style="margin-top: 1.5mm;">
        <tr>
            <td class="titulo" style="vertical-align: middle;">TASA POR SERVICIO ADMINISTRATIVO LABORAL</td>
            <td style="width: 46mm; text-align: right; white-space: nowrap; vertical-align: middle;">
                <span class="rotulo">FECHA</span>
                <span class="casilla">{{ $recibo->issue_date->format('d') }}</span>
                <span class="casilla">{{ $recibo->issue_date->format('m') }}</span>
                <span class="casilla anio">{{ $recibo->issue_date->format('Y') }}</span>
            </td>
        </tr>
    </table>

    <div style="margin-top: 2mm; font-size: 8pt;">
        Efectivo <span class="tilde">{{ $casilla === 'cash' ? 'X' : '' }}</span>
        &nbsp;&nbsp;&nbsp;&nbsp;
        Depósito Cta. {{ $papel['bankAccountNumber'] ?? '' }} <span class="tilde">{{ $casilla === 'deposit' ? 'X' : '' }}</span>
    </div>

    <table class="fila" style="margin-top: 0.5mm;">
        {{-- Como en el talonario: «Recibí de» y «Expte.» en un renglón. El documento, solo si se cargó. --}}
        <tr>
            <td class="rotulo" style="width: 1%;">Recibí de:</td>
            <td class="escrito">{{ $papel['payerName'] }}</td>
            <td class="rotulo" style="width: 1%; padding-left: 5mm;">Expte. N.º</td>
            {{-- Aranceles no tiene expediente: el renglón sale tachado. --}}
            <td style="width: 34mm; white-space: nowrap; letter-spacing: -0.2mm;">————————</td>
        </tr>
        @if ($documento)
            <tr>
                <td class="rotulo">{{ $documento['label'] }}:</td>
                <td class="escrito">{{ $documento['number'] }}</td>
                <td colspan="2"></td>
            </tr>
        @endif
    </table>

    {{-- Sin el renglón del documento la tabla crece, para que el pie quede en el mismo lugar. --}}
    <div class="conceptos" style="min-height: {{ $documento ? '27mm' : '33.5mm' }};">
        <table class="renglones">
            <thead>
                <tr>
                    <th style="width: 12mm;">Código</th>
                    <th>En concepto de:</th>
                    <th class="n" style="width: 10mm;">Cant.</th>
                    <th class="n" style="width: 26mm;">Precio</th>
                    <th class="n" style="width: 28mm;">Importe recibido</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($papel['lines'] as $linea)
                    <tr>
                        <td>{{ $linea['code'] }}</td>
                        <td>{{ $linea['name'] }}{{ $bases[$loop->index] }}</td>
                        <td class="n">{{ $linea['quantity'] ?? '' }}</td>
                        <td class="n">{{ $precios[$loop->index] }}</td>
                        <td class="n">$ {{ \App\Support\Money\Decimal::format((string) $linea['amount']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="pie">
        {{-- El total, a la derecha: cierra la columna de importes. --}}
        <table>
            <tr>
                <td class="rotulo" style="width: 1%; vertical-align: middle;">SON PESOS:</td>
                <td class="escrito en-letras" style="vertical-align: middle;">{{ $importeEnLetras }}</td>
                <td class="n" style="width: 42mm; vertical-align: middle;"><span class="importe-caja">$ {{ $importe }}</span></td>
            </tr>
        </table>

        {{-- Separado del total, para firmar y sellar. Con muchos renglones se acerca: así no pasa a otra hoja antes de tiempo. --}}
        <table style="margin-top: {{ count($papel['lines']) > 4 ? '2mm' : '12mm' }};">
            <tr>
                <td style="width: 70mm;"></td>
                <td style="width: 38mm;"></td>
                {{-- El espacio de arriba queda para la firma, como en el papel. --}}
                <td class="nombre" style="height: 11mm;">
                    @if ($recibo->signed_by_name_snapshot)
                        <strong>{{ $recibo->signed_by_name_snapshot }}</strong><br>
                        @if ($recibo->signed_by_title_snapshot)
                            {{ $recibo->signed_by_title_snapshot }}<br>
                        @endif
                        Secretaría de Trabajo
                    @endif
                </td>
            </tr>
            <tr>
                <td><div class="firma">Período</div></td>
                <td></td>
                <td><div class="firma">Firma y Aclaración</div></td>
            </tr>
        </table>
    </div>
</div>
</body>
</html>
