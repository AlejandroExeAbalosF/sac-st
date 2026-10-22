<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Controllers\Concerns;

use App\Modules\Ledger\Enums\CashBoxAbility;
use App\Modules\Ledger\Http\Middleware\ResolveCashBox;
use App\Modules\Ledger\Support\CashBoxModule;
use App\Modules\Ledger\Support\CashBoxModules;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Http\Request;

/**
 * La caja que resolvió la ruta, y lo que cada caja permite.
 *
 * Sin el middleware `caja:<código>` la pantalla no tiene caja: da 404 en
 * vez de suponer una.
 */
trait ResolvesCashBox
{
    protected function cashBox(Request $request): CashBox
    {
        $caja = $request->attributes->get(ResolveCashBox::ATTRIBUTE);

        abort_unless($caja instanceof CashBox, 404);

        return $caja;
    }

    protected function cashBoxModule(Request $request): CashBoxModule
    {
        return app(CashBoxModules::class)->for($this->cashBox($request)->code);
    }

    /**
     * Si quien mira puede hacer ese acto **en esta caja**. Los botones de la
     * pantalla se encienden con esto: con el permiso de otra caja, el botón
     * llevaría a un 403.
     */
    protected function canOnCashBox(Request $request, CashBoxAbility $ability): bool
    {
        return $request->user()?->can($this->cashBoxModule($request)->permission($ability)) ?? false;
    }
}
