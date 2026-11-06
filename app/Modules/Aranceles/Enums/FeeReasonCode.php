<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Enums;

use App\Modules\Shared\Support\ReasonCode;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * El motivo de un acto de Aranceles, elegido de una lista para poder contarlo.
 * Las listas no salen del relevamiento: son un punto de partida para ajustar con
 * el área.
 */
#[TypeScript]
enum FeeReasonCode: string implements ReasonCode
{
    case Rounding = 'rounding';
    case Exception = 'exception';
    case DataEntryError = 'data_entry_error';
    case Duplicate = 'duplicate';
    case WrongPayer = 'wrong_payer';
    case WrongCuit = 'wrong_cuit';
    case WrongWording = 'wrong_wording';
    case WrongType = 'wrong_type';
    case WrongQuantity = 'wrong_quantity';
    case WrongAmount = 'wrong_amount';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Rounding => 'Redondeo',
            self::Exception => 'Excepción autorizada',
            self::DataEntryError => 'Error de carga',
            self::Duplicate => 'Cargado dos veces',
            self::WrongPayer => 'Nombre equivocado',
            self::WrongCuit => 'CUIT o DNI equivocado',
            self::WrongWording => 'Error de redacción',
            self::WrongType => 'Tipo de arancel equivocado',
            self::WrongQuantity => 'Cantidad equivocada',
            self::WrongAmount => 'Importe equivocado',
            self::Other => 'Otro',
        };
    }
}
