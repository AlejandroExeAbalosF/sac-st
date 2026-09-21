<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\Seeder;

/**
 * Las cajas del organismo — §9.1 del DER.
 *
 * Idempotente y fuera de las migraciones, igual que los permisos y las
 * series: una caja es un dato, no un cambio de esquema.
 *
 * Van las tres desde ahora aunque solo Haberes tenga circuito. Es lo que
 * permite que el día que se construya Aranceles no haya que tocar el
 * motor: ya existe la caja a la que sus eventos van a pertenecer.
 *
 * **`is_active` no se pisa al reseedear.** Si el área desactivó una caja
 * fue por algo, y volver a encenderla desde un seeder sería decidir por
 * ellos.
 */
class CashBoxSeeder extends Seeder
{
    /**
     * @var list<array{code: string, name: string, allows_income: bool, allows_expense: bool}>
     */
    private const CAJAS = [
        [
            'code' => 'haberes',
            'name' => 'Haberes en consignación',
            'allows_income' => true,
            'allows_expense' => true,
        ],
        [
            // Recauda para el Estado: lo que entra no vuelve a salir por
            // esta caja, se rinde. Por eso no admite egresos.
            'code' => 'aranceles',
            'name' => 'Aranceles',
            'allows_income' => true,
            'allows_expense' => false,
        ],
        [
            'code' => 'multas',
            'name' => 'Multas',
            'allows_income' => true,
            'allows_expense' => false,
        ],
    ];

    public function run(): void
    {
        foreach (self::CAJAS as $caja) {
            $existente = CashBox::query()->where('code', $caja['code'])->first();

            if ($existente !== null) {
                $existente->forceFill([
                    'name' => $caja['name'],
                    'allows_income' => $caja['allows_income'],
                    'allows_expense' => $caja['allows_expense'],
                ])->save();

                continue;
            }

            CashBox::query()->create([...$caja, 'is_active' => true]);
        }
    }
}
