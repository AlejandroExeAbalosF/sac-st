<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** El recibo de ingreso de una cuota, tal como lo muestra su tarjeta. */
#[TypeScript]
final class InstallmentReceiptData extends Data
{
    /**
     * Las relaciones que `fromModel()` lee.
     *
     * Viven con el DTO y no con cada consulta porque el que las necesita
     * es el: cada pantalla que arme uno tiene que haberlas cargado, y
     * tenerlas listadas en un solo lugar es lo que evita que agregar un
     * campo aca reviente una pantalla de alla. Con `preventLazyLoading`
     * encendido eso no es una consulta de mas, es un 500.
     *
     * @var list<string>
     */
    public const RELATIONS = ['issuedBy:id,name', 'voidedBy:id,name'];

    public function __construct(
        public int $id,
        /** El identificador: siempre el del sistema. */
        public string $formattedNumber,
        /** El del papel, cuando salió del talonario. */
        public ?string $talonarioNumber,
        /** Cuál de los dos encabeza el papel, tal como se emitió. */
        public bool $printsTalonarioNumber,
        /** La aclaración impresa al pie, congelada el día de la emisión. */
        public ?string $signedByName,
        public ?string $signedByTitle,
        /** @var numeric-string */
        public string $amount,
        public string $issueDate,
        public ReceiptStatus $status,
        public ReceiptIssueMode $issueMode,
        public ?string $issuedByName,
        public ?string $voidReason,
        public ?string $voidedAt,
        public ?string $voidedByName,
    ) {}

    public static function fromModel(Receipt $receipt): self
    {
        return new self(
            id: $receipt->id,
            formattedNumber: $receipt->formatted_number,
            talonarioNumber: $receipt->talonario_number,
            printsTalonarioNumber: $receipt->prints_talonario_number,
            signedByName: $receipt->signed_by_name_snapshot,
            signedByTitle: $receipt->signed_by_title_snapshot,
            amount: $receipt->amount,
            issueDate: $receipt->issue_date->format('Y-m-d'),
            status: $receipt->status,
            issueMode: $receipt->issue_mode,
            issuedByName: $receipt->issuedBy?->name,
            voidReason: $receipt->void_reason,
            voidedAt: $receipt->voided_at?->format('Y-m-d'),
            voidedByName: $receipt->voidedBy?->name,
        );
    }
}
