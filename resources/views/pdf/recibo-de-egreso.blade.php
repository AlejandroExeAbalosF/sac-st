{{--
    El recibo de egreso, siguiendo el formulario del área.

    Mismo mecanismo que el de ingreso —`$conFondo` decide si se dibuja el
    formulario entero o solo los valores sobre el talonario preimpreso— y
    otro papel. Las diferencias no son de maquetación:

    - **el orden de los renglones cambia**: acá el beneficiario encabeza,
      porque es quien firma, y el empleador baja a un renglón propio;
    - **la casilla del cheque dice `Cheque Terc.`** (§2.5.5): lo que se
      entrega es el cheque de un tercero que estaba en custodia;
    - **no hay `N.º Op` ni `Obs`**: el número de operación describe una
      entrada, y este papel documenta una salida;
    - **el pie lo firma el beneficiario**, no el área. Sale siempre en
      blanco: quien completa ese renglón es quien cobra, de puño y letra.

    Medidas en milímetros sobre A5 apaisado, que es el tamaño del papel.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recibo de egreso {{ $recibo->formatted_number }}</title>
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
    </style>
</head>
<body>
<div class="hoja">
    @if ($conFondo)
        <div class="marco"></div>

        {{-- ─── Encabezado institucional ─── --}}
        {{-- La dirección va dentro del bloque y no en una capa aparte: con
             posiciones absolutas separadas, cualquier cambio de cuerpo en
             el membrete la dejaba pisando el renglón de arriba. Acá fluye
             debajo, con la regla que el papel dibuja. --}}
        <div class="campo" style="left: 11mm; top: 10mm; font-size: 7pt; line-height: 1.5;">
            Ministerio de Gobierno<br>
            Derechos Humanos y Trabajo<br>
            <span style="font-size: 9.5pt; font-weight: bold;">Gobierno de Salta</span><br>
            Secretaría de Trabajo
            <div style="margin-top: 0.8mm; padding-top: 0.6mm; border-top: 0.25mm solid #111; font-size: 6.5pt;">
                Bolívar 141 - Tel. 387 4318451/54 - Salta
            </div>
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

        <div class="campo" style="left: 110mm; top: 15.5mm; font-size: 9.5pt; font-weight: bold;">
            RECIBO DE EGRESO N.º
        </div>

        <div class="campo" style="left: 62mm; top: 30mm;">
            <span class="titulo-doc">HABERES EN CONSIGNACIÓN</span>
        </div>
        <div class="campo rotulo" style="left: 152mm; top: 31mm;">FECHA</div>

        {{-- ─── Fila de medios ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 45mm;">Efectivo</div>
        <div class="campo rotulo" style="left: 47mm; top: 45mm;">Cheque Terc.</div>
        <div class="campo rotulo" style="left: 90mm; top: 43mm;">
            Depósito Cta.<br>
            <span style="font-size: 7.5pt;">310000123456789</span>
        </div>

        {{-- ─── Los renglones ─── --}}
        <div class="campo rotulo" style="left: 11mm; top: 57mm;">Beneficiario:</div>
        <div class="campo linea" style="left: 34mm; top: 62mm; width: 160mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 65mm;">D.N.I.:</div>
        <div class="campo linea" style="left: 25mm; top: 70mm; width: 76mm;"></div>
        <div class="campo rotulo" style="left: 104mm; top: 65mm;">Expte N.º:</div>
        <div class="campo linea" style="left: 123mm; top: 70mm; width: 71mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 73mm;">Empleador:</div>
        <div class="campo linea" style="left: 31mm; top: 78mm; width: 163mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 81mm;">Concepto:</div>
        <div class="campo linea" style="left: 30mm; top: 86mm; width: 164mm;"></div>

        {{-- La frase que convierte al papel en constancia: la escribe el
             formulario y la firma el beneficiario. --}}
        <div class="campo rotulo" style="left: 11mm; top: 90mm;">
            Recibí conforme de la Secretaría de Trabajo la suma de:
        </div>

        <div class="campo rotulo" style="left: 108mm; top: 99mm;">Cuota</div>
        <div class="campo rotulo" style="left: 133mm; top: 99mm;">de</div>
        {{-- El renglón donde el área anota «cancelatoria» cuando es la
             última. Va en blanco: es una aclaración a mano sobre el
             convenio, no un dato que el sistema tenga. --}}
        <div class="campo linea" style="left: 152mm; top: 104mm; width: 42mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 110mm;">Son Pesos:</div>
        <div class="campo linea" style="left: 33mm; top: 115mm; width: 161mm;"></div>

        <div class="campo rotulo" style="left: 11mm; top: 118mm;">Cheque N.º:</div>
        <div class="campo linea" style="left: 34mm; top: 123mm; width: 60mm;"></div>
        <div class="campo rotulo" style="left: 100mm; top: 118mm;">Banco:</div>
        <div class="campo linea" style="left: 115mm; top: 123mm; width: 79mm;"></div>

        {{-- El pie. Queda vacío a propósito: en este comprobante firma el
             beneficiario, y su aclaración la escribe él. --}}
        <div class="campo pie-firma" style="left: 118mm; top: 136mm; width: 76mm;">
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
        <div class="campo valor" style="left: 158mm; top: 15mm; font-size: 10.5pt;">
            {{ $encabezaTalonario ? $recibo->talonario_number : $recibo->formatted_number }}
        </div>

        @if ($encabezaTalonario)
            <div class="campo" style="left: 158mm; top: 20.5mm; font-size: 6.5pt; color: #555;">
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

    {{-- Con qué se pagó, tildado donde corresponde.

         En este papel la casilla describe **la salida** y no la entrada
         (§2.4.6): entrar en efectivo y salir por transferencia es una
         combinación válida, y cada comprobante dice lo suyo. --}}
    <div class="campo" style="left: 29mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'cash' ? 'X' : '' }}</span>
    </div>
    <div class="campo" style="left: 76mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'cheque' ? 'X' : '' }}</span>
    </div>
    <div class="campo" style="left: 128mm; top: 44mm;">
        <span class="tilde">{{ $recibo->medium_snapshot === 'bank' ? 'X' : '' }}</span>
    </div>

    <div class="campo escrito" style="left: 35mm; top: 57mm; width: 158mm;">
        {{ $recibo->beneficiary_name_snapshot ?? $recibo->person?->name ?? '' }}
    </div>

    <div class="campo escrito" style="left: 26mm; top: 65mm;">
        {{ $recibo->beneficiary_document_snapshot }}
    </div>

    <div class="campo escrito" style="left: 124mm; top: 65mm;">
        {{ $recibo->expediente_number_snapshot }}
    </div>

    <div class="campo escrito" style="left: 32mm; top: 73mm; width: 161mm;">
        {{ $recibo->counterparty_name_snapshot }}
    </div>

    <div class="campo escrito" style="left: 31mm; top: 81mm; width: 162mm; font-size: 8pt;">
        {{ $recibo->concept_snapshot }}
    </div>

    {{-- El importe, en su recuadro, y las casillas de cuota al lado. --}}
    <div class="campo" style="left: 22mm; top: 97mm;">
        <span class="importe-caja">$ {{ $importe }}</span>
    </div>

    <div class="campo" style="left: 121mm; top: 97mm;">
        <span class="casilla">{{ $extra->cuotaNumero !== null ? str_pad((string) $extra->cuotaNumero, 2, '0', STR_PAD_LEFT) : '' }}</span>
    </div>
    <div class="campo" style="left: 141mm; top: 97mm;">
        <span class="casilla">{{ $extra->cuotaTotal !== null ? str_pad((string) $extra->cuotaTotal, 2, '0', STR_PAD_LEFT) : '' }}</span>
    </div>

    {{-- El importe en letras: la comprobación que atrapa un cero de más.
         «diez millones» y «cien millones» no se parecen en nada. --}}
    <div class="campo escrito en-letras" style="left: 34mm; top: 110mm; width: 158mm;">
        {{ $importeEnLetras }}
    </div>

    @if ($extra->chequeNumero)
        <div class="campo escrito" style="left: 35mm; top: 118mm;">{{ $extra->chequeNumero }}</div>
    @endif
    @if ($extra->chequeBanco)
        <div class="campo escrito" style="left: 116mm; top: 118mm;">{{ $extra->chequeBanco }}</div>
    @endif
</div>
</body>
</html>
