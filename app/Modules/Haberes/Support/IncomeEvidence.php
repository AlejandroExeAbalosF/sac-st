<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonInterface;
use LogicException;

/**
 * El recibo de ingreso de una cuota, sea del sistema o de papel.
 *
 * Una cuota cobrada por el circuito tiene su recibo del sistema. Una
 * financiada con plata apartada del sistema anterior tiene el de talonario
 * que se le dio al empleador en su momento, y no lleva otro: la base no
 * deja tener los dos.
 *
 * Para el resto del circuito son lo mismo —la constancia de que el dinero
 * entró—, y por eso se leen igual: la Orden, el egreso y el traslado
 * preguntan si hay recibo, cuánto dice y qué número imprimir, sin saber
 * de qué tipo es.
 */
final readonly class IncomeEvidence
{
    private function __construct(
        public ?Receipt $receipt,
        public ?LegacyDocument $paper,
    ) {}

    public static function system(Receipt $receipt): self
    {
        return new self($receipt, null);
    }

    public static function paper(LegacyDocument $paper): self
    {
        return new self(null, $paper);
    }

    /** El vigente de la cuota, o `null` si todavía no tiene. */
    public static function of(BeneficiaryInstallment $installment): ?self
    {
        $recibo = Receipt::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->where('receipt_type', ReceiptType::Income)
            ->where('status', ReceiptStatus::Issued)
            ->first();

        if ($recibo !== null) {
            return self::system($recibo);
        }

        $papel = LegacyDocument::query()
            ->current()
            ->where('beneficiary_installment_id', $installment->id)
            ->where('kind', LegacyDocumentKind::IncomeReceipt->value)
            ->first();

        return $papel === null ? null : self::paper($papel);
    }

    public function isPaper(): bool
    {
        return $this->paper !== null;
    }

    /** @return numeric-string */
    public function amount(): string
    {
        return $this->receipt->amount ?? $this->papel()->amount;
    }

    public function issuedOn(): CarbonInterface
    {
        return $this->receipt->issue_date ?? $this->papel()->issued_on;
    }

    /** El número del sistema; un papel no tiene. */
    public function systemNumber(): ?string
    {
        return $this->receipt?->formatted_number;
    }

    /** El del talonario, si el papel salió de uno. Un recibo de papel siempre. */
    public function talonarioNumber(): ?string
    {
        return $this->receipt === null ? $this->papel()->number : $this->receipt->talonario_number;
    }

    /** Si el comprobante encabeza con el número del talonario. */
    public function printsTalonario(): bool
    {
        return $this->receipt === null ? true : $this->receipt->prints_talonario_number;
    }

    /**
     * El número que imprime la Orden.
     *
     * Un recibo de papel no tiene número del sistema: imprime el del
     * talonario, que es el único que tiene y el que se busca en el archivo.
     */
    public function numberFor(ReceiptNumberSource $source): string
    {
        return $this->receipt === null
            ? $this->papel()->number
            : $source->numberOf($this->receipt);
    }

    private function papel(): LegacyDocument
    {
        return $this->paper ?? throw new LogicException('El recibo de ingreso no tiene ni recibo del sistema ni papel.');
    }
}
