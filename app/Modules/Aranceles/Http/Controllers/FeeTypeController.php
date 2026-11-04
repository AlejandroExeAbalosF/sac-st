<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Aranceles\Actions\AddFeeTypePrice;
use App\Modules\Aranceles\Actions\CreateFeeType;
use App\Modules\Aranceles\Actions\UpdateFeeType;
use App\Modules\Aranceles\Actions\VoidFeeTypePrice;
use App\Modules\Aranceles\Data\FeeTypeData;
use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Http\Requests\SaveFeeTypeRequest;
use App\Modules\Aranceles\Http\Requests\StoreFeeTypePriceRequest;
use App\Modules\Aranceles\Http\Requests\VoidFeeTypePriceRequest;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** El catálogo de aranceles: qué se cobra y cuánto vale desde cada fecha. */
final class FeeTypeController extends Controller
{
    public function index(Request $request): Response
    {
        $hoy = BusinessDate::today();

        $tipos = FeeType::query()
            ->with(['prices' => fn ($query) => $query
                ->with('creator:id,name')
                ->orderByDesc('valid_from')
                ->orderByDesc('id')])
            ->orderBy('code')
            ->get()
            ->map(fn (FeeType $tipo): FeeTypeData => FeeTypeData::fromModel($tipo, $hoy))
            ->values()
            ->all();

        return Inertia::render('aranceles/tipos', [
            'feeTypes' => $tipos,
            'canManage' => $request->user()?->can('aranceles.catalogo') ?? false,
            'nextCode' => (int) FeeType::query()->max('code') + 1,
        ]);
    }

    public function store(SaveFeeTypeRequest $request, CreateFeeType $crear): RedirectResponse
    {
        /** @var array{code: int|string, decreeLetter: ?string, name: string, calculationMode: string, unitLabel: ?string, validFrom: string, value: string, legalReference: ?string} $datos */
        $datos = $request->validated();

        $tipo = $crear->handle(
            [
                'code' => (int) $datos['code'],
                'decreeLetter' => $datos['decreeLetter'],
                'name' => $datos['name'],
                'calculationMode' => FeeCalculationMode::from($datos['calculationMode']),
                'unitLabel' => $datos['unitLabel'],
            ],
            CarbonImmutable::parse($datos['validFrom']),
            $datos['value'],
            $datos['legalReference'],
            $request->user()?->id,
        );

        return to_route('aranceles.tipos.index')->with('status', "Tipo de arancel {$tipo->code} registrado.");
    }

    public function update(SaveFeeTypeRequest $request, FeeType $feeType, UpdateFeeType $corregir): RedirectResponse
    {
        /** @var array{code: int|string, decreeLetter: ?string, name: string, unitLabel: ?string, isActive: bool|int|string} $datos */
        $datos = $request->validated();

        $corregir->handle($feeType, [
            'code' => (int) $datos['code'],
            'decreeLetter' => $datos['decreeLetter'],
            'name' => $datos['name'],
            'unitLabel' => $datos['unitLabel'],
            'isActive' => (bool) $datos['isActive'],
        ], $request->user()?->id);

        return to_route('aranceles.tipos.index')->with('status', "Tipo de arancel {$datos['code']} actualizado.");
    }

    public function storePrice(StoreFeeTypePriceRequest $request, FeeType $feeType, AddFeeTypePrice $agregar): RedirectResponse
    {
        /** @var array{validFrom: string, value: string, legalReference: ?string} $datos */
        $datos = $request->validated();

        $agregar->handle(
            $feeType,
            CarbonImmutable::parse($datos['validFrom']),
            $datos['value'],
            $datos['legalReference'],
            $request->user()?->id,
        );

        return to_route('aranceles.tipos.index')->with('status', 'Precio agregado.');
    }

    public function voidPrice(VoidFeeTypePriceRequest $request, FeeTypePrice $feeTypePrice, VoidFeeTypePrice $anular): RedirectResponse
    {
        /** @var array{reason: string} $datos */
        $datos = $request->validated();

        $anular->handle($feeTypePrice, $datos['reason'], $request->user()?->id);

        return to_route('aranceles.tipos.index')->with('status', 'Precio anulado.');
    }
}
