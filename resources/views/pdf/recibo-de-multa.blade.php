{{--
    El recibo de Multas, siguiendo el formulario F.022 del área.

    Igual que el de ingreso de Haberes: `$conFondo` dibuja el formulario
    entero para papel en blanco; sin fondo salen solo los valores, en la
    posición en que el talonario preimpreso ya trae sus rótulos.

    Lo que dice de la multa —concepto, total, pagos anteriores— sale del
    snapshot congelado al emitir: el papel no cambia aunque después cambie
    la multa (M09).

    Medidas en milímetros sobre A5 apaisado.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de Multas {{ $recibo->formatted_number }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        @page { margin: 0; size: 210mm 148mm; }

        body {
            /* Ver el comentario equivalente en recibo-de-ingreso: 210 mm en los dos lados. */
            width: 210mm;
            margin: 0 auto;
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #111;
        }

        .hoja { position: relative; width: 210mm; height: 148mm; }
        .marco { position: absolute; inset: 7mm; border: 0.35mm solid #111; border-radius: 2mm; }

        .campo { position: absolute; }
        .rotulo { font-size: 8pt; }
        .valor { font-weight: bold; }
        .escrito { font-weight: bold; letter-spacing: 0.15mm; }
        .en-letras::first-letter { text-transform: uppercase; }
        .linea { border-bottom: 0.25mm dotted #111; }

        .tilde {
            display: inline-block;
            width: 7mm; height: 6mm;
            border: 0.3mm solid #111; border-radius: 1mm;
            text-align: center; line-height: 6mm; font-weight: bold;
        }

        .titulo-doc {
            border: 0.35mm solid #111; border-radius: 2mm;
            padding: 1mm 6mm; display: inline-block; background: #ddd;
            font-size: 10pt; font-weight: bold; letter-spacing: 0.3mm;
        }

        .sello {
            position: absolute; left: 92mm; top: 9mm;
            width: 30mm; height: 30mm;
            border: 0.3mm dashed #bbb; border-radius: 50%;
            text-align: center; font-size: 5pt; color: #bbb; padding-top: 13mm;
        }

        .importe-caja {
            border: 0.35mm solid #111; border-radius: 1.5mm;
            padding: 1.5mm 3mm; display: inline-block; min-width: 45mm;
            font-size: 12pt; font-weight: bold;
        }

        .detalle { font-size: 6.5pt; line-height: 1.35; }
        .pie-firma { text-align: center; font-size: 7.5pt; }
        .pie-firma .nombre { font-weight: bold; font-size: 8.5pt; }
    </style>
</head>
<body>
<div class="hoja">
    @if ($conFondo)
        <div class="marco"></div>

        {{-- ─── Encabezado institucional ─── --}}
        <div class="campo" style="left: 11mm; top: 10mm; font-size: 7pt; line-height: 1.4;">
            Ministerio de Gobierno,<br>
            Derechos Humanos y Trabajo<br>
            <span style="font-size: 10pt; font-weight: bold;">Gobierno de Salta</span><br>
            Secretaría de Trabajo
        </div>
        <div class="campo" style="left: 11mm; top: 28mm; font-size: 6.5pt;">
            Bolívar 141 - Tel. 0387-4318451/54 - Salta
        </div>

        @if ($selloUrl)
            <img src="{{ $selloUrl }}" alt="" style="position:absolute;left:92mm;top:9mm;width:30mm;">
        @else
            {{-- Se reserva el lugar del sello en vez de imitarlo: ver recibo-de-ingreso. --}}
            <div class="sello">sello<br>del organismo</div>
        @endif

        <div class="campo" style="left: 125mm; top: 18mm; font-size: 11pt; font-weight: bold;">
            RECIBO N.º
        </div>

        <div class="campo" style="left: 97mm; top: 36mm;">
            <span class="titulo-doc">MULTAS</span>
        </div>

        {{-- ─── Fila de medios ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 48mm;">Efectivo</div>
        <div class="campo rotulo" style="left: 40mm; top: 48mm;">Cheque Tercero</div>
        <div class="campo rotulo" style="left: 85mm; top: 48mm;">Orden Pago Judicial</div>
        <div class="campo rotulo" style="left: 138mm; top: 46mm; text-align: center; width: 36mm;">
            Depósito Cta.<br>
            <span style="font-size: 7pt;">{{ $cuenta ?? '' }}</span>
        </div>

        {{-- ─── Los renglones ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 60mm;">Recibí de:</div>
        <div class="campo linea" style="left: 28mm; top: 64mm; width: 166mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 68mm;">La suma de pesos:</div>
        <div class="campo linea" style="left: 38mm; top: 72mm; width: 156mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 76mm;">En concepto de:</div>
        <div class="campo linea" style="left: 35mm; top: 80mm; width: 159mm;"></div>
        <div class="campo linea" style="left: 11mm; top: 86mm; width: 183mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 90mm;">Expte. Adm. N.º</div>
        <div class="campo linea" style="left: 35mm; top: 94mm; width: 66mm;"></div>
        <div class="campo rotulo" style="left: 104mm; top: 90mm;">Expte. Judicial N.º</div>
        <div class="campo linea" style="left: 132mm; top: 94mm; width: 62mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 102mm;">SON:</div>

        <div class="campo rotulo" style="left: 11mm; top: 120mm;">Salta:</div>
        <div class="campo linea" style="left: 21mm; top: 124mm; width: 70mm;"></div>

        <div class="campo" style="left: 5mm; top: 128mm; font-size: 6pt; color: #555;">F.022</div>

        <div class="campo pie-firma" style="left: 122mm; top: 135mm; width: 72mm;">
            <div style="border-top: 0.3mm dotted #111; padding-top: 0.6mm;">Firma y Aclaración</div>
        </div>
    @endif

    {{-- ══════ Los valores. Se imprimen siempre, con o sin fondo. ══════ --}}

    @unless ($sobreTalonario)
        <div class="campo valor" style="left: 152mm; top: 17.5mm; font-size: 11pt;">
            {{ $recibo->formatted_number }}
        </div>
        @if ($recibo->talonario_number)
            <div class="campo" style="left: 152mm; top: 23mm; font-size: 6.5pt; color: #555;">
                Talonario {{ $recibo->talonario_number }}
            </div>
        @endif
    @endunless

    {{-- El medio, tildado donde corresponde. --}}
    <div class="campo" style="left: 25mm; top: 47mm;"><span class="tilde">{{ $casilla === 'cash' ? 'X' : '' }}</span></div>
    <div class="campo" style="left: 66mm; top: 47mm;"><span class="tilde">{{ $casilla === 'cheque' ? 'X' : '' }}</span></div>
    <div class="campo" style="left: 116mm; top: 47mm;"><span class="tilde">{{ $casilla === 'judicial' ? 'X' : '' }}</span></div>
    <div class="campo" style="left: 176mm; top: 47mm;"><span class="tilde">{{ $casilla === 'deposit' ? 'X' : '' }}</span></div>

    <div class="campo escrito" style="left: 29mm; top: 60mm; width: 164mm;">
        {{ $recibo->counterparty_name_snapshot }}
    </div>

    {{-- La suma en letras: la comprobación que atrapa un cero de más. --}}
    <div class="campo escrito en-letras" style="left: 39mm; top: 68mm; width: 154mm;">
        {{ $importeEnLetras }}
    </div>

    <div class="campo escrito" style="left: 36mm; top: 76mm; width: 157mm; font-size: 8pt; line-height: 6mm;">
        {{ $recibo->concept_snapshot }}
    </div>

    <div class="campo escrito" style="left: 36mm; top: 90mm;">{{ $recibo->expediente_number_snapshot }}</div>
    @if (! empty($snapshot['judicialReference']))
        <div class="campo escrito" style="left: 133mm; top: 90mm;">{{ $snapshot['judicialReference'] }}</div>
    @endif

    <div class="campo" style="left: 20mm; top: 99mm;">
        <span class="importe-caja">$ {{ $importe }}</span>
    </div>
    <div class="campo escrito" style="left: 104mm; top: 101mm;">Total multa $ {{ $totalMulta }}</div>

    {{-- El cancelatorio dice cómo se llegó a cero, y separa el excedente (M05, M09). --}}
    @if ($esCancelatorio || ($snapshot['excess'] ?? '0.00') !== '0.00')
        <div class="campo detalle" style="left: 104mm; top: 107mm; width: 90mm;">
            @if ($esCancelatorio)
                <strong>Pago cancelatorio.</strong>
                @foreach ($snapshot['previousPayments'] ?? [] as $anterior)
                    {{ \App\Modules\Multas\Support\PartialDate::fromStored($anterior['date'], \App\Modules\Multas\Enums\DatePrecision::tryFrom($anterior['precision'] ?? '') ?? \App\Modules\Multas\Enums\DatePrecision::Unknown)->toDisplay() }}
                    $ {{ \App\Support\Money\Decimal::format((string) $anterior['amount']) }}{{ $anterior['receipt'] ? ' (rec. '.$anterior['receipt'].')' : '' }};
                @endforeach
                Saldo $ 0,00.
            @endif
            @if (($snapshot['excess'] ?? '0.00') !== '0.00')
                Excedente pendiente $ {{ \App\Support\Money\Decimal::format((string) $snapshot['excess']) }}.
            @endif
        </div>
    @endif

    <div class="campo escrito" style="left: 22mm; top: 120mm;">
        {{ $recibo->issue_date->locale('es')->translatedFormat('j \d\e F \d\e Y') }}
    </div>

    @if ($recibo->signed_by_name_snapshot)
        <div class="campo pie-firma" style="left: 122mm; top: 123mm; width: 72mm; line-height: 1.25;">
            <span class="nombre">{{ $recibo->signed_by_name_snapshot }}</span><br>
            @if ($recibo->signed_by_title_snapshot)
                {{ $recibo->signed_by_title_snapshot }}<br>
            @endif
            Secretaría de Trabajo
        </div>
    @endif
</div>
</body>
</html>
