<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use App\Modules\Ledger\Models\PeriodClosing;
use App\Support\Money\Decimal;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un cierre, con las tres columnas de la planilla.
 *
 * Se manda el cuadro entero —apertura, movimientos y saldo final por
 * columna— y no solo los totales, porque es lo que el área compara contra
 * el papel: quien revisa un cierre no quiere el resultado, quiere ver de
 * dónde salió.
 */
#[TypeScript]
final class PeriodClosingListItemData extends Data
{
    public function __construct(
        public int $id,
        public string $periodType,
        public string $periodTypeLabel,
        public string $periodFrom,
        public string $periodTo,
        public string $currency,
        /** @var numeric-string */
        public string $openingCash,
        /** @var numeric-string */
        public string $openingCheques,
        /** @var numeric-string */
        public string $openingBankDeposits,
        /** @var numeric-string */
        public string $receivedCash,
        /** @var numeric-string */
        public string $disbursedCash,
        /** @var numeric-string */
        public string $depositedToBankCash,
        /** @var numeric-string */
        public string $closingCash,
        /** @var numeric-string */
        public string $closingCheques,
        /** @var numeric-string */
        public string $closingBankDeposits,
        /** @var numeric-string */
        public string $totalUnassigned,
        public string $status,
        public string $statusLabel,
        public ?string $closedBy,
        public ?string $closedAt,
        public ?string $reopenReason,
        public ?string $reopenedBy,
        /** El adjunto de la planilla, si ya se emitió. */
        public ?int $sheetAttachmentId,
        /**
         * Con qué versión del generador se dibujó la planilla guardada.
         *
         * Nula mientras no se haya emitido, y también en las que se
         * emitieron antes de que el sistema empezara a marcarlas: en los
         * dos casos la pantalla no puede afirmar que esté al día.
         */
        public ?string $sheetTemplateVersion,
        /**
         * Las planillas emitidas, de la última a la primera.
         *
         * Vacío mientras la relación no venga cargada: la lista de
         * cierres la pide, el calendario no la necesita.
         *
         * @var list<array{id: int, issuedAt: string, issuedBy: string|null, templateVersion: string|null, current: bool}>
         */
        public array $sheetHistory,
        /**
         * Si el saldo congelado ya no coincide con el libro.
         *
         * Un cierre se calcula del libro y se guarda; si después entra un
         * movimiento con fecha anterior, el guardado queda mintiendo. Hoy
         * eso no puede volver a pasar --el asiento se rechaza-- pero los
         * datos anteriores a esa regla pueden traerlo, y un cierre
         * desactualizado se ve idéntico a uno sano.
         *
         * Nulo cuando no se comprobó: la pantalla no puede afirmar que
         * esté al día sin haber mirado.
         */
        public ?bool $matchesLedger,
    ) {}

    public static function fromModel(PeriodClosing $closing, ?string $ledgerCash = null): self
    {
        return new self(
            id: (int) $closing->id,
            periodType: $closing->period_type->value,
            periodTypeLabel: $closing->period_type->label(),
            periodFrom: $closing->period_from->toDateString(),
            periodTo: $closing->period_to->toDateString(),
            currency: $closing->currency->value,
            openingCash: $closing->opening_cash,
            openingCheques: $closing->opening_cheques,
            openingBankDeposits: $closing->opening_bank_deposits,
            receivedCash: $closing->received_cash,
            disbursedCash: $closing->disbursed_cash,
            depositedToBankCash: $closing->deposited_to_bank_cash,
            closingCash: $closing->closing_cash,
            closingCheques: $closing->closing_cheques,
            closingBankDeposits: $closing->closing_bank_deposits,
            totalUnassigned: $closing->total_unassigned,
            status: $closing->status->value,
            statusLabel: $closing->status->label(),
            closedBy: $closing->relationLoaded('closedBy') ? $closing->closedBy?->name : null,
            closedAt: $closing->closed_at?->toIso8601String(),
            reopenReason: $closing->reopen_reason,
            reopenedBy: $closing->relationLoaded('reopenedBy') ? $closing->reopenedBy?->name : null,
            sheetAttachmentId: $closing->sheet_attachment_id,
            sheetTemplateVersion: $closing->relationLoaded('sheetAttachment')
                ? $closing->sheetAttachment?->template_version
                : null,
            sheetHistory: self::history($closing),
            matchesLedger: $ledgerCash === null
                ? null
                : Decimal::equals($closing->closing_cash, $ledgerCash),
        );
    }

    /**
     * Las planillas emitidas por este cierre, listas para la pantalla.
     *
     * @return list<array{id: int, issuedAt: string, issuedBy: string|null, templateVersion: string|null, current: bool}>
     */
    private static function history(PeriodClosing $closing): array
    {
        if (! $closing->relationLoaded('sheets')) {
            return [];
        }

        $historial = [];

        foreach ($closing->sheets as $planilla) {
            $historial[] = [
                'id' => (int) $planilla->id,
                'issuedAt' => $planilla->created_at->toIso8601String(),
                'issuedBy' => $planilla->relationLoaded('uploader')
                    ? $planilla->uploader?->name
                    : null,
                'templateVersion' => $planilla->template_version,
                'current' => (int) $planilla->id === (int) $closing->sheet_attachment_id,
            ];
        }

        return $historial;
    }
}
