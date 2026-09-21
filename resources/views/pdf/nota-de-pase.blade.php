{{--
    La nota de Pase que acompaña a la Orden hacia el organismo superior.

    Es una nota, no un formulario: párrafos corridos sobre papel con
    membrete. El texto lo confirmó el área y es fijo; lo que cambia son el
    destinatario, la referencia del expediente, el beneficiario, el importe
    y la foja donde el CBU está informado.

    **La firma va en blanco** para que la firme quien corresponda, y el
    **membrete queda reservado** hasta que el área entregue el archivo del
    logotipo: es identidad institucional, y una imitación en un documento
    que va a otro organismo es peor que un espacio vacío.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Pase — Orden de Pago {{ $orden->formatted_number }}</title>
    <style>
        @page { margin: 20mm 22mm; size: 210mm 297mm; }

        body {
            /*
             * **La nota se compone sobre 166 mm en los dos lados.**
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
             */
            width: 166mm;
            margin: 0 auto;
            /*
             * El papel es blanco y hay que decirlo: el navegador que
             * muestra la vista previa pintaba el lienzo negro en modo
             * oscuro, con la tinta encima.
             */
            background: #fff;
            font-family: DejaVu Sans, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #111;
        }

        .membrete { height: 22mm; margin-bottom: 6mm; }
        .membrete-reservado {
            height: 20mm;
            border: 0.3mm dashed #bbb;
            color: #bbb;
            font-size: 7pt;
            text-align: center;
            padding-top: 8mm;
        }

        .fecha { text-align: right; margin-bottom: 10mm; }

        .destino { font-weight: bold; margin-bottom: 8mm; }
        .destino .organismo { text-decoration: underline; }

        .referencia { text-align: right; font-weight: bold; margin-bottom: 8mm; }

        p { margin: 0 0 3mm 0; text-align: justify; text-indent: 12mm; }

        .resaltado { font-weight: bold; }

        .pie-firma {
            margin-top: 30mm;
            width: 70mm;
            border-top: 0.25mm solid #111;
            padding-top: 1.5mm;
            text-align: center;
            font-size: 9pt;
            color: #555;
        }
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

<div class="fecha">Salta, {{ $fechaLarga }}</div>

<div class="destino">
    Pase de Contable<br>
    <span class="organismo">A {{ $pase->destination }}</span>
</div>

<div class="referencia">
    Ref. EXPTE. {{ $orden->expediente_canonical_snapshot ?? $orden->expediente_number_snapshot }}
    @if ($orden->expediente_subject_snapshot !== null)
        <br>{{ $orden->expediente_subject_snapshot }}
    @endif
</div>

<p>
    Por medio de la presente, se solicita a esa Administración, la transferencia, desde la
    Cuenta Haberes en Consignación de esta Secretaría a
    @if ($orden->cbu_folio_snapshot !== null)
        {{-- Tal como lo redacta el área: la cuenta vive en el expediente. --}}
        la <span class="resaltado">CBU informada en fs. {{ $orden->cbu_folio_snapshot }} del
        Expte. de referencia</span>,
    @else
        la <span class="resaltado">CBU {{ $orden->beneficiary_cbu_snapshot }}</span>,
    @endif
    {{--
        El documento y la coma que lo sigue van pegados al nombre: Blade
        conserva los saltos de línea, y partirlo dejaba un espacio flotando
        antes de la coma.

        El importe se escribe dos veces, en números y en letras, tal como
        lo redacta el área: «$ 2.892.402,00 (pesos dos millones ochocientos
        noventa y dos mil cuatrocientos dos)». La palabra «pesos» va acá y
        no en `AmountInWords`, que devuelve el número en letras y lo usan
        también los comprobantes, que rotulan la moneda distinto.
    --}}
    y a favor de {{ $orden->beneficiary_name_snapshot }}@if ($orden->beneficiary_document_snapshot !== null) DNI {{ $orden->beneficiary_document_snapshot }}@endif, por el importe de
    <span class="resaltado">$ {{ $importe }}</span> (pesos {{ $importeEnLetras }}).
</p>

<p>
    Se adjunta documentación sin foliatura porque la misma, una vez firmada por el trabajador,
    será devuelta en esa condición al empleador.
</p>

<p>
    Se solicita que, una vez efectuada la transferencia, se remita, en carácter de urgente, el
    Expte., al Dpto. Contable de esta Secretaría, adjuntando constancia de la transferencia y
    Recibo de Pago, a los efectos de continuar con el procedimiento administrativo
    correspondiente.
</p>

@if ($pase->notes !== null)
    <p>{{ $pase->notes }}</p>
@endif

<p>Sin más, sirva la presente de atenta nota.</p>

{{-- En blanco a pedido del área: la firma quien corresponda. --}}
<div class="pie-firma">Firma y sello</div>

</body>
</html>
