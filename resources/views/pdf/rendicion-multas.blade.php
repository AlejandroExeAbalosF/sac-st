{{--
    La rendición mensual de Multas a la SAF, como las dos notas del área
    (septiembre de 2026): ingresos del mes en término, o depósitos fuera de
    término. Todo sale de lo congelado al emitir.

    DomPDF: tablas y medidas en milímetros; sin flex, grid ni variables CSS.
    El membrete queda reservado hasta que el área entregue su archivo.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Rendición de Multas {{ $periodo }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        @page { margin: 18mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5pt; color: #111; }
        .membrete { height: 22mm; margin-bottom: 6mm; }
        .membrete-reservado {
            height: 20mm; border: 0.3mm dashed #bbb; color: #bbb;
            font-size: 7pt; text-align: center; padding-top: 8mm;
        }
        .fecha { text-align: right; font-weight: bold; margin-bottom: 8mm; }
        .destino p { margin: 0 0 1mm 0; }
        .destino .sd { margin-top: 2mm; }
        .cuerpo { margin: 8mm 0 5mm 0; text-indent: 40mm; line-height: 1.6; text-align: justify; }
        table.detalle { width: 100%; border-collapse: collapse; }
        table.detalle th, table.detalle td { border: 0.3mm solid #111; padding: 1.2mm 1.5mm; font-size: 8.5pt; }
        table.detalle th { font-weight: bold; text-align: center; }
        .num { text-align: right; white-space: nowrap; }
        /* Fecha, comprobante, expediente y recibo no se cortan: «169607/2024-0»
           partido en dos renglones ya no se lee como un número. */
        .centro { text-align: center; white-space: nowrap; }
        /* El total es la última fila y no se despega de la anterior: al final
           de una hoja llena, baja con ella en vez de quedar solo arriba de la
           siguiente. Al pie de cada hoja va «Hoja N de M» (FineRenditionPdf). */
        tr.pie { page-break-before: avoid; }
        tr.pie td { font-weight: bold; }
        .marca { font-size: 7pt; }
        .vacio { text-align: center; color: #555; padding: 4mm; }
    </style>
</head>
<body>

<div class="membrete">
    @if ($membreteUrl !== null)
        <img src="{{ $membreteUrl }}" style="height: 20mm;" alt="">
    @else
        <div class="membrete-reservado">MEMBRETE DEL ORGANISMO</div>
    @endif
</div>

<div class="fecha">{{ $lugarYFecha }}</div>

<div class="destino">
    @foreach ($destinatario as $i => $linea)
        <p @if ($i > 0) style="font-weight: bold;" @endif>{{ $linea }}</p>
    @endforeach
    <p class="sd">S. ______________/______________ D.</p>
</div>

<p class="cuerpo">
    @if ($enTermino)
        Me dirijo a Usted con el objeto de remitirle informe de los ingresos de Multas
        correspondientes al <strong>MES DE {{ $periodo }}</strong>, los que se encuentran
        acreditados en {!! $variasCuentas ? 'las cuentas de esta Secretaría' : 'la cuenta de esta Secretaría <strong>'.e($cuenta).'</strong>' !!} según detalle:
    @else
        Me dirijo a Usted con el objeto de remitirle informe de los ingresos de Multas
        correspondientes a depósitos <strong>FUERA DE TÉRMINO MES DE {{ $periodo }}</strong>,
        los que se encuentran acreditados en {!! $variasCuentas ? 'las cuentas de esta Secretaría' : 'la cuenta de esta Secretaría <strong>'.e($cuenta).'</strong>' !!}
        según el siguiente detalle:
    @endif
</p>

<table class="detalle">
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Caja</th>
            <th>Comp. Nº</th>
            <th>Carátula</th>
            <th>Expte. Nº</th>
            <th>Importe</th>
            <th>Recibo</th>
            @if ($variasCuentas)
                <th>Cuenta</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @forelse ($filas as $fila)
            <tr>
                <td class="centro">{{ \Carbon\CarbonImmutable::parse($fila['date'])->format('d/m/y') }}</td>
                <td class="centro">{{ $fila['origin'] }}</td>
                <td class="centro">{{ $fila['voucher'] }}</td>
                <td>
                    {{ $fila['caption'] }}
                    @if ($fila['rejected'])
                        <span class="marca">(cheque rechazado)</span>
                    @endif
                </td>
                <td class="centro">{{ $fila['caseLabel'] }}</td>
                <td class="num">$ {{ \App\Support\Money\Decimal::format($fila['amount']) }}</td>
                <td class="centro">{{ $fila['receipt'] }}</td>
                @if ($variasCuentas)
                    <td class="centro">{{ $fila['account'] ?? '' }}</td>
                @endif
            </tr>
        @empty
            <tr><td colspan="{{ $variasCuentas ? 8 : 7 }}" class="vacio">Sin ingresos para informar.</td></tr>
        @endforelse
        <tr class="pie">
            <td colspan="5">
                @if ($enLetras !== '')
                    Son Pesos: {{ ucfirst($enLetras) }}.
                @endif
            </td>
            <td class="num">$ {{ $total }}</td>
            <td @if ($variasCuentas) colspan="2" @endif></td>
        </tr>
    </tbody>
</table>

</body>
</html>
