<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Data;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Un cobro en el listado: quién paga y el recibo son los del recibo vigente. */
#[TypeScript]
final readonly class FeeChargeListItemData
{
    /**
     * @param  list<FeeChargeLineData>  $lines
     * @param  list<FeeChargeFundingData>  $fundings
     */
    public function __construct(
        public int $id,
        public string $payerName,
        public ?string $payerDocument,
        public FeeChargeMedium $medium,
        public FeeChargeStatus $status,
        public string $requestedOn,
        public ?string $paidOn,
        /** Con qué tarifa se calcula: la corrección conserva la del cobro original. */
        public string $pricingDate,
        public string $total,
        /** Lo vinculado: en un pendiente, un ingreso parcial. */
        public string $funded,
        public string $missing,
        public array $lines,
        public array $fundings,
        public ?int $receiptId,
        public ?string $receiptNumber,
        public ?string $talonarioNumber,
        /** La foto de la hoja del talonario: sigue a la vista si después se reemplaza el recibo. */
        public ?int $talonarioPhotoId,
        /** El último comprobante del depósito: cambiarlo agrega otro y el anterior queda. */
        public ?FeeChargeProofData $depositProof,
        public ?string $voidReason,
        /** Si algo de su efectivo viajó en un depósito vigente. */
        public bool $deposited,
    ) {}
}
