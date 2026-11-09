<!doctype html><html lang="es"><meta charset="utf-8"><style>
@page {margin:14mm} body {font:11pt Helvetica,sans-serif;color:#151515} h1 {border:1pt solid;text-align:center;font-size:15pt;padding:9pt;margin:0 0 10pt} table {border-collapse:collapse;width:100%} .panel {width:50%;border:1pt solid;vertical-align:top;padding:12pt}.heading {background:#ededed;border:0.7pt solid;text-align:center;padding:5pt;font-weight:bold}.medium{text-align:center;font-size:10pt;margin:10pt}.label{font-size:9pt;width:61%;padding:9pt 4pt 9pt 0}.amount{width:39%;text-align:right;white-space:nowrap;border-bottom:0.6pt solid #777}.total td{font-weight:bold}.sign{width:80%;margin:40pt auto 2pt;text-align:center;border-top:1pt dotted;padding-top:5pt;font-size:8pt}
</style><body><h1>PLANILLA DE RENDICIÓN DE CAJA DEL DÍA &nbsp; {{ $date }}</h1><table>
@foreach(array_chunk($panels, 2) as $pair)<tr>
@foreach($pair as $panel)<td class="panel"><div class="heading">{{ $panel['title'] }}</div>
@if(!$panel['summary'])<div class="medium">EFECTIVO</div>@endif
<table>@foreach($panel['rows'] as [$label, $amount])<tr class="{{ $loop->last ? 'total' : '' }}"><td class="label">{{ $label }}</td><td class="amount">$ {{ $amount }}</td></tr>@endforeach</table>
@if($panel['summary'])<div class="sign">FIRMA Y SELLO JEFE DEPTO. CONTABLE</div>@endif
</td>@endforeach</tr>@endforeach</table></body></html>
