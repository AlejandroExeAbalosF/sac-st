{{--
    El recibo de ingreso, siguiendo el formulario F.010 del área.

    Una sola plantilla para los dos destinos, con `$conFondo` como
    interruptor:

    - `true`  → papel en blanco: se dibuja el formulario entero.
    - `false` → talonario preimpreso: solo los valores manuscritos, en la
                posición en que el papel ya trae sus rótulos y sus líneas.

    **En modo talonario no se imprime ningún número arriba.** El papel ya
    trae el suyo —es el que se carga como `talonario_number`— y superponerle
    el del sistema dejaría dos números peleando por el mismo renglón, que
    es justamente lo que la numeración única quiso evitar.

    Medidas en milímetros sobre A5 apaisado, que es el tamaño del papel.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de ingreso {{ $recibo->formatted_number }}</title>
    <style>
        @page { margin: 0; size: 210mm 148mm; }

        body {
            /*
             * **El recibo se compone sobre 210 mm en los dos lados.**
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
             * Acá `@page` no descuenta nada, así que hoy el número coincide
             * con el ancho del iframe y declararlo no cambia lo que se ve.
             * Se declara igual para dejar de depender de que `HOJA_ANCHO`
             * valga justo 210 mm.
             */
            width: 210mm;
            margin: 0 auto;
            /*
             * El papel es blanco y hay que decirlo: dompdf lo asume, pero
             * el navegador que muestra la vista previa no, y en modo
             * oscuro pintaba el lienzo negro con la tinta encima.
             */
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 8.5pt;
            color: #111;
        }

        .hoja { position: relative; width: 210mm; height: 148mm; }
        .marco { position: absolute; inset: 7mm; border: 0.35mm solid #111; }

        .campo { position: absolute; }
        .rotulo { font-size: 8pt; }
        .valor { font-weight: bold; }

        /* Lo manuscrito del papel: se distingue de los rótulos impresos. */
        .escrito { font-weight: bold; letter-spacing: 0.15mm; }
        .en-letras::first-letter { text-transform: uppercase; }

        .linea { border-bottom: 0.25mm solid #111; }

        /* Las casillas de fecha, cuenta y cuota. */
        .casilla {
            display: inline-block;
            width: 8mm; height: 6.5mm;
            border: 0.3mm solid #111; border-radius: 1mm;
            text-align: center; line-height: 6.5mm;
            font-weight: bold; margin-right: 0.8mm;
        }

        .tilde {
            display: inline-block;
            width: 7mm; height: 5.5mm;
            border: 0.3mm solid #111; border-radius: 0.8mm;
            text-align: center; line-height: 5.5mm; font-weight: bold;
        }

        .titulo-doc {
            border: 0.35mm solid #111; border-radius: 1mm;
            padding: 1.2mm 4mm; display: inline-block;
            font-size: 10pt; font-weight: bold; letter-spacing: 0.3mm;
        }

        .sello {
            position: absolute; left: 74mm; top: 9mm;
            width: 28mm; height: 28mm;
            border: 0.3mm dashed #bbb; border-radius: 50%;
            text-align: center; font-size: 5pt; color: #bbb; padding-top: 12mm;
        }

        .importe-caja {
            border: 0.35mm solid #111; border-radius: 1mm;
            padding: 1.5mm 3mm; display: inline-block;
            font-size: 12pt; font-weight: bold;
        }

        .pie-firma { text-align: center; font-size: 7.5pt; }
        .pie-firma .nombre { font-weight: bold; font-size: 8.5pt; }
    </style>
</head>
<body>
<div class="hoja">
    @if ($conFondo)
        <div class="marco"></div>

        {{-- ─── Encabezado institucional ─── --}}
        <div class="campo" style="left: 11mm; top: 10mm; font-size: 7pt; line-height: 1.5;">
            Subsecretaría de Trabajo<br>
            <span style="margin-left: 4mm;">Ministerio de<br>
            <span style="margin-left: 0;">Gobierno y Justicia</span></span>
        </div>
        <div class="campo" style="left: 11mm; top: 22mm; font-size: 7pt;">
            Bolívar 141 - Tel. 387 4318451/54 - Salta
        </div>

        @if ($selloUrl)
            <img src="{{ $selloUrl }}" alt="" style="position:absolute;left:74mm;top:9mm;width:28mm;">
        @else
            {{-- El sello y el logotipo del organismo no están cargados en
                 el sistema. Se reserva el lugar en vez de dibujar una
                 aproximación: es la identidad institucional, y una
                 imitación en un documento que se entrega es peor que un
                 espacio vacío. --}}
            <div class="sello">sello<br>del organismo</div>
        @endif

        <div class="campo" style="left: 108mm; top: 15.5mm; font-size: 9.5pt; font-weight: bold;">
            RECIBO DE INGRESO N.º
        </div>

        <div class="campo" style="left: 62mm; top: 30mm;">
            <span class="titulo-doc">HABERES EN CONSIGNACIÓN</span>
        </div>
        <div class="campo rotulo" style="left: 152mm; top: 31mm;">FECHA</div>

        {{-- ─── Fila de medios ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 45mm;">Efectivo</div>
        <div class="campo rotulo" style="left: 47mm; top: 45mm;">Cheque</div>
        <div class="campo rotulo" style="left: 76mm; top: 43mm;">
            Depósito Cta.<br>
            <span style="font-size: 7.5pt;">310000123456789</span>
        </div>
        <div class="campo rotulo" style="left: 152mm; top: 45mm;">N.º Op</div>

        {{-- ─── Los renglones ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 57mm;">Recibí de:</div>
        <div class="campo linea" style="left: 32mm; top: 62mm; width: 162mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 65mm;">En concepto de:</div>
        <div class="campo linea" style="left: 40mm; top: 70mm; width: 154mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 73mm;">Beneficiario:</div>
        <div class="campo linea" style="left: 34mm; top: 78mm; width: 160mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 81mm;">D.N.I. N.º</div>
        <div class="campo linea" style="left: 30mm; top: 86mm; width: 70mm;"></div>
        <div class="campo rotulo" style="left: 104mm; top: 81mm;">Expte. N.º</div>
        <div class="campo linea" style="left: 124mm; top: 86mm; width: 70mm;"></div>

        <div class="campo rotulo" style="left: 108mm; top: 93mm;">Cuota</div>
        <div class="campo rotulo" style="left: 130mm; top: 93mm;">de</div>

        <div class="campo rotulo" style="left: 11mm; top: 105mm;">Son Pesos:</div>
        <div class="campo linea" style="left: 33mm; top: 110mm; width: 161mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 113mm;">Cheque N.º:</div>
        <div class="campo linea" style="left: 34mm; top: 118mm; width: 60mm;"></div>
        <div class="campo rotulo" style="left: 100mm; top: 113mm;">Banco:</div>
        <div class="campo linea" style="left: 115mm; top: 118mm; width: 79mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 124mm;">Obs:</div>
        <div class="campo linea" style="left: 22mm; top: 129mm; width: 68mm;"></div>

        <div class="campo" style="left: 5mm; top: 128mm; font-size: 6pt; color: #555;">F.010</div>

        <div class="campo pie-firma" style="left: 118mm; top: 135mm; width: 76mm;">
            <div style="border-top: 0.3mm solid #111; padding-top: 0.6mm;">Firma y Aclaración</div>
        </div>
    @endif

    {{-- ══════ Los valores. Se imprimen siempre, con o sin fondo. ══════ --}}

    @unless ($sobreTalonario)
        {{-- El número del documento.

             Encabeza el que el operador eligió al emitirlo. Con el del
             talonario arriba, el del sistema baja a referencia en chico:
             los dos quedan en el papel, y la unicidad es la combinación
             de ambos.

             En el modo talonario no se imprime ninguno: el papel ya trae
             el suyo preimpreso. --}}
        <div class="campo valor" style="left: 155mm; top: 15mm; font-size: 10.5pt;">
            {{ $encabezaTalonario ? $recibo->talonario_number : $recibo->formatted_number }}
        </div>

        @if ($encabezaTalonario)
            <div class="campo" style="left: 155mm; top: 20.5mm; font-size: 6.5pt; color: #555;">
                Registro {{ $recibo->formatted_number }}
            </div>
        @endif
    @endunless

    {{-- Fecha, en sus tres casillas. --}}
    <div class="campo" style="left: 152mm; top: 36mm;">
        <span class="casilla">{{ $recibo->issue_date->format('d') }}</span>
        <span class="casilla">{{ $recibo->issue_date->format('m') }}</span>
        <span class="casilla">{{ $recibo->issue_date->format('y') }}</span>
    </div>

    {{-- El medio, tildado donde corresponde. --}}
    <div class="campo" style="left: 29mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'cash' ? 'X' : '' }}</span>
    </div>
    <div class="campo" style="left: 62mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'cheque' ? 'X' : '' }}</span>
    </div>
    <div class="campo" style="left: 112mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'bank' ? 'X' : '' }}</span>
    </div>

    @if ($extra->numeroOperacion)
        <div class="campo escrito" style="left: 168mm; top: 45mm; font-size: 8pt;">
            {{ $extra->numeroOperacion }}
        </div>
    @endif

    <div class="campo escrito" style="left: 33mm; top: 57mm; width: 160mm;">
        {{ $recibo->counterparty_name_snapshot ?? $recibo->person?->name ?? '' }}
    </div>

    <div class="campo escrito" style="left: 41mm; top: 65mm; width: 152mm; font-size: 8pt;">
        {{ $recibo->concept_snapshot }}
    </div>

    <div class="campo escrito" style="left: 35mm; top: 73mm; width: 158mm;">
        {{ $recibo->beneficiary_name_snapshot }}
    </div>

    <div class="campo escrito" style="left: 31mm; top: 81mm;">
        {{ $recibo->beneficiary_document_snapshot }}
    </div>

    <div class="campo escrito" style="left: 125mm; top: 81mm;">
        {{ $recibo->expediente_number_snapshot }}
    </div>

    {{-- El importe, en su recuadro, y las casillas de cuota al lado. --}}
    <div class="campo" style="left: 22mm; top: 92mm;">
        <span class="importe-caja">$ {{ $importe }}</span>
    </div>

    <div class="campo" style="left: 120mm; top: 92mm;">
        <span class="casilla">{{ $extra->cuotaNumero !== null ? str_pad((string) $extra->cuotaNumero, 2, '0', STR_PAD_LEFT) : '' }}</span>
    </div>
    <div class="campo" style="left: 137mm; top: 92mm;">
        <span class="casilla">{{ $extra->cuotaTotal !== null ? str_pad((string) $extra->cuotaTotal, 2, '0', STR_PAD_LEFT) : '' }}</span>
    </div>

    {{-- El importe en letras: la comprobación que atrapa un cero de más.
         «diez millones» y «cien millones» no se parecen en nada. --}}
    <div class="campo escrito en-letras" style="left: 34mm; top: 105mm; width: 158mm;">
        {{ $importeEnLetras }}
    </div>

    @if ($extra->chequeNumero)
        <div class="campo escrito" style="left: 35mm; top: 113mm;">{{ $extra->chequeNumero }}</div>
    @endif
    @if ($extra->chequeBanco)
        <div class="campo escrito" style="left: 116mm; top: 113mm;">{{ $extra->chequeBanco }}</div>
    @endif

    @if ($extra->observaciones)
        <div class="campo escrito" style="left: 23mm; top: 124mm; width: 66mm; font-size: 7pt;">
            {{ $extra->observaciones }}
        </div>
    @endif

    @if ($recibo->signed_by_name_snapshot)
        {{-- El nombre y el cargo van debajo de la línea; el espacio de
             arriba queda para la firma ológrafa, como en el papel. --}}
        <div class="campo pie-firma" style="left: 118mm; top: 123mm; width: 76mm; line-height: 1.25;">
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
