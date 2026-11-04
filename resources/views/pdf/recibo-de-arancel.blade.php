{{--
    El recibo de Aranceles, en papel en blanco. La renderiza DomPDF: se maqueta
    con tablas, sin flexbox ni variables CSS.

    Lo que dice sale de la copia congelada al emitir. Sin tope de renglones: la
    tabla sigue en otra hoja, repitiendo su encabezado, y el pie no se parte.

    El diseño es provisorio: falta el visto bueno del área.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de Aranceles {{ $recibo->formatted_number }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        @page { margin: 8mm 9mm 12mm 9mm; size: 210mm 148mm; }

        body {
            margin: 0 auto;
            max-width: 192mm;
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8pt;
            color: #111;
        }

        table { border-collapse: collapse; width: 100%; }
        td, th { vertical-align: top; }
        .encabezado td { padding: 0; }
        .institucion { font-size: 7pt; line-height: 1.35; }
        .institucion strong { font-size: 10pt; }
        .numero { text-align: right; }
        .numero strong { font-size: 10.5pt; }
        .apagado { color: #555; font-size: 6.5pt; }
        .titulo { font-size: 9.5pt; font-weight: bold; padding: 2.5mm 0 1.5mm; }
        .datos td { padding: 0.6mm 2mm 0.6mm 0; }
        .rotulo { font-size: 7pt; color: #444; }
        .casilla {
            display: inline-block; width: 3.4mm; height: 3.4mm; line-height: 3.4mm;
            border: 0.25mm solid #111; text-align: center; font-weight: bold; font-size: 7pt;
        }
        .renglones { margin-top: 1.5mm; }
        .renglones thead { display: table-header-group; }
        .renglones tr { page-break-inside: avoid; }
        .renglones th {
            text-align: left; font-size: 7pt; font-weight: normal; color: #444;
            border-top: 0.35mm solid #111; border-bottom: 0.35mm solid #111; padding: 0.8mm 1mm;
        }
        .renglones td { border-bottom: 0.15mm solid #999; padding: 0.8mm 1mm; font-size: 7.5pt; }
        .n, .renglones th.n { text-align: right; white-space: nowrap; }
        .anulado { color: #b00; font-weight: bold; font-size: 9pt; }
        .pie { page-break-inside: avoid; margin-top: 2.5mm; }
        .total {
            border: 0.35mm solid #111; font-size: 10pt; font-weight: bold;
            padding: 0.8mm 3mm; text-align: right; white-space: nowrap;
        }
        .firma { border-top: 0.25mm dotted #111; text-align: center; font-size: 7pt; padding-top: 0.6mm; }
        .en-letras::first-letter { text-transform: uppercase; }
    </style>
</head>
<body>
    <table class="encabezado">
        <tr>
            <td style="width: 45%;" class="institucion">
                Ministerio de Gobierno, Derechos Humanos y Trabajo<br>
                <strong>Gobierno de Salta</strong><br>
                Secretaría de Trabajo<br>
                Bolívar 141 - Tel. 0387-4318451/54 - Salta
            </td>
            <td style="width: 20%; text-align: center;">
                @if ($selloUrl)
                    <img src="{{ $selloUrl }}" alt="" style="width: 18mm;">
                @endif
            </td>
            <td class="numero">
                <strong>Recibo de ingreso N.º {{ $recibo->formatted_number }}</strong><br>
                @if ($recibo->talonario_number)
                    <span class="apagado">Talonario {{ $recibo->talonario_number }}</span><br>
                @endif
                Fecha: {{ $recibo->issue_date->format('d/m/Y') }}
                {{-- Un recibo sin valor no se puede imprimir como si lo tuviera. --}}
                @if ($recibo->status === \App\Modules\Shared\Enums\ReceiptStatus::Voided)
                    <br><span class="anulado">ANULADO</span>
                @elseif ($recibo->status === \App\Modules\Shared\Enums\ReceiptStatus::Replaced)
                    <br><span class="anulado">REEMPLAZADO</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="titulo">Tasa por servicio administrativo laboral</div>

    <table class="datos">
        <tr>
            <td style="width: 60%;"><span class="rotulo">Recibí de:</span> <strong>{{ $papel['payerName'] }}</strong></td>
            <td><span class="rotulo">CUIT:</span> {{ $papel['payerCuit'] ?? '' }}</td>
        </tr>
        <tr>
            <td><span class="rotulo">Expte. N.º:</span> ————————</td>
            <td>
                <span class="casilla">{{ $casilla === 'cash' ? 'X' : '' }}</span> Efectivo
                &nbsp;
                <span class="casilla">{{ $casilla === 'deposit' ? 'X' : '' }}</span> Depósito Cta. {{ $papel['bankAccountNumber'] ?? '' }}
            </td>
        </tr>
    </table>

    <table class="renglones">
        <thead>
            <tr>
                <th style="width: 9mm;">Cód.</th>
                <th>En concepto de</th>
                <th class="n" style="width: 12mm;">Cant.</th>
                <th class="n" style="width: 22mm;">Precio</th>
                <th class="n" style="width: 24mm;">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($papel['lines'] as $linea)
                <tr>
                    <td>{{ $linea['code'] }}</td>
                    <td>{{ $linea['name'] }}</td>
                    <td class="n">{{ $linea['quantity'] ?? '' }}</td>
                    <td class="n">
                        @if ($linea['unitPrice'] !== null)
                            $ {{ \App\Support\Money\Decimal::format((string) $linea['unitPrice']) }}
                        @elseif ($linea['rate'] !== null)
                            {{ rtrim(rtrim(str_replace('.', ',', (string) $linea['rate']), '0'), ',') }} % s/ $ {{ \App\Support\Money\Decimal::format((string) $linea['baseAmount']) }}
                        @endif
                    </td>
                    <td class="n">$ {{ \App\Support\Money\Decimal::format((string) $linea['amount']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="pie">
        <table>
            <tr>
                <td style="vertical-align: middle;"><span class="rotulo">Son pesos:</span> <span class="en-letras">{{ $importeEnLetras }}</span></td>
                <td style="width: 38mm; vertical-align: middle;"><div class="total">$ {{ $importe }}</div></td>
            </tr>
        </table>

        <table style="margin-top: 9mm;">
            <tr>
                <td style="width: 45%; vertical-align: bottom;"><div class="firma">Período</div></td>
                <td style="width: 10%;"></td>
                <td style="vertical-align: bottom; text-align: center;">
                    @if ($recibo->signed_by_name_snapshot)
                        <strong>{{ $recibo->signed_by_name_snapshot }}</strong><br>
                        @if ($recibo->signed_by_title_snapshot)
                            {{ $recibo->signed_by_title_snapshot }}<br>
                        @endif
                    @endif
                    <div class="firma">Firma y aclaración</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
