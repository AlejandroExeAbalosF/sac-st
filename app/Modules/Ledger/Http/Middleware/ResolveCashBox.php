<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Middleware;

use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBoxModules;
use App\Modules\Shared\Models\CashBox;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * La caja de la pantalla sale de la ruta: `->middleware('caja:multas')`.
 *
 * Las pantallas de caja son de Ledger y valen para cualquier caja, así que
 * la caja no puede ser una constante del controlador ni un campo del
 * formulario: la declara la ruta, y cada familia de rutas —`/caja/*` para
 * Haberes, `/multas/caja/*` para Multas— lleva la suya. **No hay caja por
 * defecto**: una caja sin módulo que la opere da 404, en vez de caer en
 * silencio en Haberes.
 *
 * Y lo que llega por la dirección tiene que ser de esa caja. Un arqueo o un
 * cierre de otra caja da 404, con el mismo criterio que recibos y
 * traslados: pedirlo por la URL de la caja equivocada no es un permiso
 * distinto, es una dirección que no existe.
 */
final class ResolveCashBox
{
    public const ATTRIBUTE = 'cashBox';

    public function __construct(private readonly CashBoxModules $modules) {}

    public function handle(Request $request, Closure $next, string $code): Response
    {
        abort_unless($this->modules->has($code), 404);

        $caja = CashBox::query()->active()->where('code', $code)->firstOrFail();

        foreach (['cashCount', 'closing'] as $parametro) {
            $modelo = $request->route($parametro);

            if (($modelo instanceof CashCount || $modelo instanceof PeriodClosing)
                && (int) $modelo->cash_box_id !== (int) $caja->id) {
                abort(404);
            }
        }

        $request->attributes->set(self::ATTRIBUTE, $caja);

        return $next($request);
    }
}
