<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 22mm 20mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #171717; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #333; padding: 7px 10px; }
        th { font-weight: bold; text-align: center; }
        .heading { font-size: 17px; font-weight: bold; text-align: left; }
        .section { font-size: 15px; font-weight: bold; text-align: left; }
        .date { text-align: center; }
        .number { text-align: right; }
        .quantity { text-align: center; }
        .total { font-weight: bold; }
        .cheques { margin-top: 24px; }
        .signature { width: 45%; margin: 68px 0 0 auto; text-align: center; }
        .signature-line { border-bottom: 1px dotted #333; }
        .signature-label { margin-top: 7px; font-size: 10px; }
        .detail { margin-top: 12px; font-size: 10px; }
    </style>
</head>
<body>
<table>
    <tr><th colspan="2" class="heading">{{ $cashBoxCode }} FECHA</th><th class="date">{{ $date }}</th></tr>
    <tr><th colspan="3" class="section">EFECTIVO</th></tr>
    <tr><th>CANTIDAD</th><th>BILLETES</th><th>{{ $currency->value === 'ARS' ? 'PESOS' : 'DÓLARES' }}</th></tr>
    @foreach ($rows as $row)
        <tr>
            <td class="quantity">{{ $row['quantity'] }}</td>
            <td class="number">{{ $currency->symbol() }} {{ $row['denomination'] }}</td>
            <td class="number">{{ $row['subtotal'] === null ? '' : $currency->symbol().' '.$row['subtotal'] }}</td>
        </tr>
    @endforeach
    <tr class="total"><td colspan="2">TOTAL</td><td class="number">{{ $currency->symbol() }} {{ $total }}</td></tr>
</table>

@if ($difference !== null)
    <p class="detail"><strong>Diferencia contra el libro:</strong> {{ $currency->symbol() }} {{ $difference }}</p>
    @if ($explanation)
        <p class="detail"><strong>Explicación:</strong> {{ $explanation }}</p>
    @endif
@endif

<table class="cheques">
    <tr><th rowspan="4">CHEQUES 3ROS</th><th>CANTIDAD</th><th>TOTAL</th></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
    <tr><td>&nbsp;</td><td>&nbsp;</td></tr>
</table>
<div class="signature">
    <div class="signature-line">&nbsp;</div>
    <div class="signature-label">Firma</div>
</div>
</body>
</html>
