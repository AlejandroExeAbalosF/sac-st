{{--
    La Orden de Pago, siguiendo el formulario del área.

    Se dibuja entera sobre papel en blanco: la Orden pasó a generarla el
    sistema, así que no hay preimpreso sobre el que superponerse.

    ── La grilla, que es la del papel ──────────────────────────────────

    **Son filas grandes, no columnas grandes.** La línea horizontal cruza
    de lado a lado, y cada franja tiene su parte izquierda y su parte
    derecha. Por eso el «RECIBO DE INGRESO N°» queda a la altura del cuadro
    de depósitos y no arriba junto al importe: pertenece a la segunda
    franja, no a una columna que baja entera.

    ```text
    ┌──────────────────────────────────────────────────────┐
    │            3582                                      │
    ├──────────────────────────────────────────────────────┤
    │          10/6/2026                                   │
    ├────────────────────────────────────┬─────────────────┤
    │ beneficiario, cheque y cuentas     │ banco, importe  │
    │                                    │ y fecha ini.    │
    ├────────────────────────────────────┼─────────────────┤
    │ expediente, empresa y depósitos    │ recibo de       │
    │                                    │ ingreso y firma │
    ├────────────────────────────────────┼─────────────────┤
    │ recibo de egreso · registro        │ fecha           │
    ├────────────────────────────────────┴─────────────────┤
    │ OBS                                                  │
    └──────────────────────────────────────────────────────┘
    ```

    **Dos columnas**, que es como quedan las franjas del papel: una parte
    izquierda ancha y una derecha angosta. Hubo cinco mientras el
    formulario encabezaba con «VALORES EN CUSTODIA» y «CHEQUES PROPIOS»,
    cada título con su casilla al lado; el área confirmó que esos campos ya
    no van y con ellos se fue la única razón para subdividir.

    **Las franjas llevan altura mínima.** Sin eso el formulario se aplasta
    contra su contenido y sale ancho y corto, cuando el papel del área es
    una hoja alta. La altura la fija el papel, no el texto que le toque.

    **Estructura de tablas y no de posiciones absolutas.** El recibo de
    ingreso va posicionado en milímetros porque tiene que caer sobre los
    rótulos de un talonario que ya existe; acá el papel lo define esta
    plantilla, y una tabla se mantiene cuadrada cuando un domicilio ocupa
    dos renglones en vez de uno. Con posiciones fijas, ese domicilio se
    montaría encima del renglón de abajo.

    Medidas en milímetros sobre A4 vertical.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Orden de Pago {{ $numeroImpreso }}</title>
    <style nonce="{{ Vite::cspNonce() }}">
        @page { margin: 12mm; size: 210mm 297mm; }

        body {
            /*
             * **El formulario se compone sobre 186 mm en los dos lados.**
             *
             * dompdf le descuenta a la hoja los márgenes de `@page` y
             * maqueta sobre 186; el navegador de la vista previa no sabe
             * nada de `@page` y maquetaba sobre los 210 del iframe. Un 13%
             * de diferencia: todo lo declarado en milímetros —las casillas,
             * los rótulos— salía proporcionalmente más chico en pantalla, y
             * todo lo declarado en porcentaje, más ancho.
             *
             * Con el ancho fijo las dos vistas resuelven contra la misma
             * caja y lo que se ve en pantalla es lo que sale impreso. Los
             * márgenes de `@page` se conservan para que, si el documento
             * alguna vez pasa de una hoja, la segunda los tenga.
             */
            width: 186mm;
            margin: 0 auto;
            /*
             * El papel es blanco y hay que decirlo: dompdf lo asume, pero
             * el navegador que muestra la vista previa no, y en modo
             * oscuro pintaba el lienzo negro con la tinta encima.
             */
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9pt;
            color: #111;
        }

        table { border-collapse: collapse; width: 100%; }
        td { vertical-align: top; padding: 0; }

        .titulo-pagina {
            margin: 0 0 4mm;
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
        }

        .marco {
            border: 0.4mm solid #111;
        }

        /*
         * **El marco reparte 71/29, y eso se declara en las celdas de la
         * franja 1 —no en un `<colgroup>` y no con `table-layout: fixed`.**
         *
         * Acá hubo una vista previa que no coincidía con el PDF: el
         * navegador repartía 71/29 y dompdf mitad y mitad. Son dos
         * limitaciones de dompdf que se suman.
         *
         * `Table::normalize()` descarta los frames `table-column` sin
         * mirarles el ancho: **`<colgroup>` y `<col>` no están
         * implementados**. Y en `Cellmap`, el ancho porcentual de una
         * celda solo cuenta si `colspan === 1` y —con `table-layout:
         * fixed`— únicamente si la celda está en la **fila 0**. Las dos
         * primeras filas del formulario son `colspan="2"`, así que en modo
         * fijo ninguna columna recibía ancho nunca.
         *
         * En modo automático desaparece la restricción de la fila 0 y el
         * porcentaje de la franja 1 se aplica, que es lo que hay ahora.
         * Las dos columnas suman 100%, así que no queda nada librado al
         * contenido, y el navegador lee lo mismo.
         *
         * Lo que no sirve es declararlo en una fila fantasma al principio:
         * dompdf le da 0,35 mm de alto igual, y el borde de arriba del
         * formulario sale duplicado.
         */
        .marco td { border: 0.3mm solid #111; padding: 1.5mm 2.5mm; }

        /*
         * Las tablas internas no heredan el trazo del marco. Va después a
         * propósito: misma especificidad que `.marco td`, y en el empate
         * gana la última. Con `>` no alcanzaba —el navegador y dompdf
         * insertan un `tbody` que el selector no contempla—.
         */
        .interna td { border: none; padding: 0; }

        /*
         * Las franjas que no se parten al medio.
         *
         * Con `border-collapse` el trazo entre dos celdas es **uno solo y
         * compartido**: sigue dibujándose mientras a alguno de los dos
         * vecinos le corresponda. Por eso hacen falta las dos clases y no
         * alcanza con una.
         *
         * Y por eso tampoco sirve `class="interna"` en el `<tr>`: eso saca
         * los cuatro lados, así que junto con la división del medio se
         * lleva la línea horizontal que separa la franja de la siguiente.
         *
         * Van con `td.` delante para empatarle en especificidad a
         * `.marco td` —clase más elemento— y ganar por venir después,
         * igual que `.interna td`.
         */
        td.sin-borde-derecho { border-right: none; }
        td.sin-borde-izquierdo { border-left: none; }
        /*
         * El importe y la fecha inicial, apilados contra la línea que
         * separa las franjas.
         *
         * En el papel son dos casillas pegadas que comparten su borde, y
         * ese borde compartido **es** la línea de la franja: por eso la
         * línea cruza entera y las casillas no dibujan la suya de ese
         * lado. Sin quitarles el relleno a las celdas quedarían flotando a
         * milímetro y medio de la línea, con aire en el medio.
         */
        td.pegado-abajo { padding-bottom: 0 !important; }
        td.pegado-arriba { padding-top: 0 !important; }
        .cuadro-derecha .sin-tapa { border-top: none !important; }
        .cuadro-derecha .sin-base { border-bottom: none !important; }

        .rotulo { font-size: 8.2pt; letter-spacing: 0.02mm; }
        .valor { font-weight: bold; }

        .numero-doc {
            height: 5mm;
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
            padding: 1.4mm 0 0 !important;
        }

        .fecha-doc {
            height: 5mm;
            text-align: center;
            font-size: 9.2pt;
            font-weight: bold;
            padding: 1.4mm 0 0 !important;
        }

        /*
         * Los renglones del cuerpo: rótulo a la izquierda con ancho fijo y
         * valor pegado. Con `min-width` en un `span`, dompdf lo ignora y
         * los valores quedaban en diagonal; una tabla interna los alinea.
         */
        .campos td { padding-bottom: 2.2mm !important; }
        /*
         * Ancho fijo, y no automático. Con `auto` la tabla repartía a
         * mitades y los valores se iban al medio de la hoja, lejos de su
         * rótulo; en el papel del área van pegados.
         */
        .campos .rotulo { width: 29mm; white-space: nowrap; }

        .datos-banco { margin-top: 28mm; }

        /*
         * **La columna de los importes llega hasta el marco.**
         *
         * En el formulario del área las casillas de «IMPORTE» y «FECHA
         * INI.» apoyan contra la línea del borde, sin aire a la derecha.
         * Acá eso además hace falta: son los 2,5 mm que le permiten al
         * importe entrar en un solo renglón.
         *
         * La firma sí conserva su margen, como en el papel.
         */
        td.columna-importes { padding-right: 0 !important; }

        /* Los dos importes enmarcados de la derecha, con su rótulo al lado. */
        .cuadro-derecha { margin-top: 4mm; }
        .cuadro-derecha td {
            border: 0.3mm solid #111 !important;
            padding: 1mm 1.2mm !important;
        }
        .cuadro-derecha .rotulo { border: none !important; padding-left: 0 !important; }

        .caja {
            border: 0.3mm solid #111;
            min-height: 4.8mm;
            padding: 0.8mm 2mm;
            text-align: center;
            font-weight: bold;
        }

        .cuadro-importe {
            border: 0.35mm solid #111;
            padding: 1.2mm 2mm;
            /*
             * **El cuerpo sale de medir el peor importe contra la casilla,
             * no de cuánto se quiere destacar.**
             *
             * La columna derecha es el 29% del marco y la casilla queda
             * en 29,9 mm útiles. A 11,5 pt no entraba ni el importe del
             * formulario del área —$ 2.892.402,00 necesita 34,3— y el
             * número se partía dejando el «$» solo en un renglón.
             *
             * A 9 pt ese importe ocupa 26,8 y entra holgado; el tope es
             * $ 99.999.999,00 con 29,0. Los anchos salen de medirlos con
             * las métricas de la fuente, no de estimarlos.
             *
             * En el papel el número tampoco es grande: se destaca por la
             * negrita y el recuadro, que es lo que se conserva acá.
             */
            font-size: 9pt;
            font-weight: bold;
            text-align: right;
        }

        .grilla-depositos {
            margin: 6mm 0 0 1%;
            width: 98%;
            table-layout: fixed;
        }
        .grilla-depositos td, .grilla-depositos th {
            border: 0.25mm solid #111;
            /*
             * El relleno de costado se mide, no se elige: a 1,2 mm los
             * cuatro pares se comían 9,6 de los 85,6 mm que da la franja
             * —más de una columna entera— y era eso, y no el tamaño de la
             * letra, lo que dejaba sin lugar a los datos.
             */
            padding: 1mm 0.8mm;
            font-size: 7pt;
            /*
             * **Nada puede salirse de su casilla.**
             *
             * Con `table-layout: fixed` una columna no se ensancha para
             * alojar lo que no entra: el texto se derrama sobre la de al
             * lado y los dos quedan encimados e ilegibles. Pasó con el
             * número de cuenta, que son quince dígitos sin un solo espacio
             * donde cortar, montado sobre el importe.
             *
             * Las columnas de abajo están medidas para que el peor dato
             * real entre holgado. Esto es la red: si alguna vez no entra
             * —una cuenta más larga, un importe de ocho cifras— el dato se
             * parte adentro de su casilla. Una celda de dos renglones se
             * ve fea y se lee; dos números superpuestos no se leen.
             */
            overflow-wrap: anywhere;
            word-break: break-all;
        }
        .grilla-depositos th {
            font-weight: normal;
            text-align: center;
        }
        .grilla-depositos .importe { text-align: right; }
        .grilla-depositos .vacia td { height: 5.3mm; }
        .grilla-depositos .total td { border: none; }
        .grilla-depositos .total .importe { border: 0.25mm solid #111; }

        .firma {
            border-top: 0.25mm dotted #111;
            padding-top: 1mm;
            text-align: center;
            font-size: 8pt;
        }

        .obs {
            height: 7mm;
            font-size: 7.5pt;
            vertical-align: middle;
        }
    </style>
</head>
<body>
<div class="titulo-pagina">ORDEN DE PAGO</div>
<table class="marco">
    {{-- El número con su serie: 0030/00000003, igual que los dos recibos. --}}
    <tr>
        <td colspan="2" class="numero-doc">{{ $numeroImpreso }}</td>
    </tr>
    <tr>
        <td colspan="2" class="fecha-doc">{{ $orden->order_date->format('j/n/Y') }}</td>
    </tr>

    {{-- ─── Franja 1: el beneficiario · el banco donde está el dinero ─ --}}
    <tr>
        <td class="sin-borde-derecho" style="width: 71%; height: 65mm;">
            <table class="interna campos">
                <tr>
                    <td class="rotulo">BENEFICIARIO</td>
                    <td class="valor">{{ $orden->beneficiary_name_snapshot }}</td>
                </tr>
                <tr>
                    <td class="rotulo">DNI/ CUIT</td>
                    <td class="valor">{{ $orden->beneficiary_document_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">DOMICILIO</td>
                    <td class="valor">{{ $orden->beneficiary_address_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">TELEFONO:</td>
                    <td class="valor">{{ $orden->beneficiary_phone_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">CHEQUE N°</td>
                    <td class="valor">{{ $orden->cheque_number_snapshot ?? '' }}</td>
                </tr>
            </table>

            {{--
                Las cuentas del organismo, una debajo de la otra con su
                casilla, y la cruz en la que tiene el dinero. La marca la
                pone el sistema: sabe de dónde vino cada peso de esta Orden.
            --}}
            <table class="interna" style="margin-top: 1mm;">
                @foreach ($extra->cuentas as $i => $cuenta)
                    <tr>
                        <td class="rotulo" style="width: 29mm; white-space: nowrap;">
                            {{ $i === 0 ? 'CTA N°' : '' }}
                        </td>
                        <td class="valor" style="width: 40mm; padding: 0.6mm 0 !important;">
                            {{ $cuenta['accountNumber'] ?? $cuenta['label'] }}
                        </td>
                        <td style="width: 23mm; padding: 0.6mm 0 !important;">
                            <div class="caja">{{ $cuenta['marked'] ? 'XXX' : '&nbsp;' }}</div>
                        </td>
                        {{-- Absorbe lo que sobra: sin esto la casilla se iba al borde. --}}
                        <td></td>
                    </tr>
                @endforeach
            </table>
        </td>

        <td class="sin-borde-izquierdo pegado-abajo columna-importes" style="width: 29%;">
            <div class="datos-banco">
            <table class="interna campos">
                <tr>
                    <td class="rotulo" style="width: 22mm;">BANCO</td>
                    <td class="valor">{{ $extra->banco ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo" style="width: 22mm;">FECHA</td>
                    <td class="valor">{{ $extra->fechaBanco ?? '' }}</td>
                </tr>
            </table>

            {{--
                El importe, con el rótulo afuera y el valor adentro: es
                como está en el papel, alineado contra el margen.

                **«FECHA INI.» va acá al lado pero en la franja de abajo.**
                En el papel las dos casillas se leen juntas, y sin embargo
                la línea que separa las franjas pasa entre ellas — por eso
                esa línea no cruza esta columna.
            --}}
            <table class="interna cuadro-derecha" style="margin-top: 19.8mm;">
                <tr>
                    <td class="rotulo" style="width: 19mm;">IMPORTE</td>
                    <td class="cuadro-importe sin-base">$ {{ $importe }}</td>
                </tr>
            </table>
            </div>
        </td>
    </tr>

    {{-- ─── Franja 2: el expediente · el recibo y la firma ──────────── --}}
    <tr>
        <td class="sin-borde-derecho" style="height: 90mm;">
            <table class="interna campos">
                <tr>
                    <td class="rotulo">EXPEDIENTE N°</td>
                    <td class="valor">{{ $orden->expediente_number_snapshot }}</td>
                </tr>
                <tr>
                    <td class="rotulo">EMPRESA</td>
                    <td class="valor">{{ $orden->employer_name_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">DOMICILIO</td>
                    <td class="valor">{{ $orden->employer_address_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">TELEFONO:</td>
                    <td class="valor">{{ $orden->employer_phone_snapshot ?? '' }}</td>
                </tr>
                <tr>
                    <td class="rotulo">DNI/CUIT</td>
                    <td class="valor">{{ $orden->employer_tax_identifier_snapshot ?? '' }}</td>
                </tr>
            </table>

            {{--
                La justificación que el área le da al organismo: «esta plata
                vino de acá». Congelada al emitir, nunca recalculada.
            --}}
            <table class="grilla-depositos">
                <tr>
                    {{--
                        Los anchos salen de medir el peor dato de cada
                        columna contra el PDF, no de repartir a ojo:
                        importes de decenas de millones, fechas de dos
                        dígitos en el mes, ocho cifras de cuenta y doce de
                        operación entran los cuatro sin partirse.

                        Quien sobraba era la primera —su rótulo ya va en
                        dos renglones y el número de operación es corto—,
                        así que de ahí salieron los milímetros que le
                        faltaban a «IMPORTE», que es la que crece sola con
                        la inflación.

                        El día que parezca que hay que ensanchar alguna,
                        revisar primero qué se está metiendo adentro y
                        medirlo sobre el PDF.

                        El rótulo va en dos renglones: de una sola línea se
                        desborda sobre «FECHA» —en el formulario del área
                        también, pero eso es un defecto del papel, no algo
                        para copiar—.
                    --}}
                    <th style="width: 25%;">DEPOSITO U<br>OPERACIÓN N°</th>
                    <th style="width: 22%;">FECHA</th>
                    <th style="width: 22%;">CTA. CTE.</th>
                    <th style="width: 31%;">IMPORTE</th>
                </tr>
                @foreach ($extra->depositos as $deposito)
                    <tr>
                        <td class="valor">{{ $deposito->operacion ?? '' }}</td>
                        <td class="valor">{{ $deposito->fecha ?? '' }}</td>
                        <td class="valor">{{ $deposito->cuenta ?? '' }}</td>
                        <td class="valor importe">$ {{ $deposito->importe }}</td>
                    </tr>
                @endforeach

                {{--
                    Los renglones vacíos del formulario. No son decoración:
                    el papel llega con el cuadro completo y el área a veces
                    agrega una línea a mano cuando aparece otro depósito.
                --}}
                @for ($i = count($extra->depositos); $i < 6; $i++)
                    <tr class="vacia"><td></td><td></td><td></td><td></td></tr>
                @endfor

                <tr class="total">
                    <td colspan="3"></td>
                    <td class="valor importe">$ {{ $extra->total }}</td>
                </tr>
            </table>
        </td>

        {{--
            El recibo de ingreso y la firma van en esta franja y no en la
            de arriba: en el papel quedan a la altura del cuadro de
            depósitos, que es lo que respaldan.
        --}}
        <td class="sin-borde-izquierdo pegado-arriba columna-importes">
            {{-- Sigue al importe de la franja de arriba, sin línea en medio. --}}
            <table class="interna cuadro-derecha" style="margin-top: 0;">
                <tr>
                    <td class="rotulo" style="width: 19mm;">FECHA INI.</td>
                    <td class="valor sin-tapa" style="text-align: center;">
                        {{ $orden->custody_start_date_snapshot?->format('j/n/Y') ?? '' }}
                    </td>
                </tr>
            </table>

            <table class="interna cuadro-derecha" style="margin-top: 36mm;">
                <tr>
                    <td class="rotulo" style="width: 19mm;">RECIBO DE<br>INGRESO N°</td>
                    <td class="valor" style="text-align: center;">
                        {{ $orden->income_receipt_number_snapshot }}
                    </td>
                </tr>
            </table>

            {{-- En blanco: la firma el Tesorero cuando corresponde. --}}
            <div class="firma" style="margin: 23mm 2.5mm 0 0;">FIRMA DEL TESORERO</div>
        </td>
    </tr>

    {{--
        El pie del egreso.

        «RECIBO EGRESO N°» lo completa el sistema en cuanto el recibo
        existe, y sale vacío hasta entonces: al imprimir la Orden para
        remitirla, el egreso todavía no se validó y no hay número que
        poner.

        «REGISTRO» y «FECHA» **se siguen firmando a mano**, y no es un
        pendiente: son renglones de firma —una línea punteada con su
        rótulo abajo—, no casillas de datos. Llenarlos desde el sistema
        sería imprimir la firma de alguien.

        **Esta franja no se parte al medio.** En el papel, «REGISTRO» y
        «FECHA» comparten el mismo espacio abierto: no hay línea vertical
        entre ellos, como sí la hay más arriba. Son dos firmas del mismo
        acto, no dos cuadros distintos.

        Por eso va en una sola celda y no en dos con el borde anulado: sin
        división que dibujar no hay nada que cancelar, y las dos firmas
        pueden compartir un renglón.
    --}}
    <tr>
        <td colspan="2" style="height: 44mm;">
            {{--
                La casilla va pegada al rótulo, no tirada al medio del
                formulario, y para eso el rótulo lleva ancho propio: sin
                declararlo, la tabla le da el sobrante y se estira.

                Los 32 mm son los 28,4 que mide «RECIBO EGRESO N°» a 8,2 pt
                más los 3 de separación. Medido con las métricas de la
                fuente; si cambia el rótulo o el cuerpo, se vuelve a medir.

                Lo que no sirve es empujar con un relleno al 100%: dompdf le
                deja los 45 mm a la casilla y el navegador reparte
                proporcionalmente entre las tres, así que la casilla salía
                de un tamaño en pantalla y de otro en el papel.
            --}}
            <table class="interna">
                <tr>
                    <td class="rotulo" style="width: 32mm; white-space: nowrap;">
                        RECIBO EGRESO N°
                    </td>
                    <td style="width: 45mm;">
                        <div class="caja">{{ $reciboDeEgreso ?? '' }}&nbsp;</div>
                    </td>
                    <td></td>
                </tr>
            </table>

            {{--
                Las dos firmas en la misma fila, que es lo que las deja al
                mismo nivel. Apiladas en celdas distintas cada una contaba
                su margen desde el techo de su celda, y como de este lado
                está la casilla del recibo arriba, «REGISTRO» quedaba cuatro
                milímetros más abajo que «FECHA».
            --}}
            <table class="interna" style="margin-top: 27mm;">
                <tr>
                    <td style="width: 71%;">
                        <div class="firma" style="margin: 0 12mm;">REGISTRO</div>
                    </td>
                    <td style="width: 29%;">
                        <div class="firma" style="margin: 0 10mm;">FECHA</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    <tr>
        <td colspan="2" class="obs">
            <span class="rotulo">OBS.</span>
            <span class="valor">{{ $orden->notes ?? '' }}</span>
        </td>
    </tr>
</table>
</body>
</html>
