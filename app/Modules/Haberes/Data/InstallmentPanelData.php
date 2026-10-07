<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Modules\Haberes\Enums\InstallmentStage;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuota vista desde afuera de su ficha: de quién es, cuánto y en qué
 * punto del circuito está.
 *
 * Es lo que muestra el panel lateral cuando se la nombra desde otra
 * pantalla —la cartera de cheques, lo reservado del sistema anterior—, sin
 * tener que ir hasta el haber para saber de qué se trata.
 */
#[TypeScript]
final class InstallmentPanelData extends Data
{
    public function __construct(
        public ReceiptSubjectData $subject,
        /** Cuántas cuotas tiene el haber: «cuota 2 de 3». */
        public int $installmentsTotal,
        /** @var numeric-string */
        public string $expectedAmount,
        /** @var numeric-string */
        public string $fundedAmount,
        /** Si lo financiado se reservó del sistema anterior. */
        public bool $fundedFromLegacy,
        /** `cash`, `cheque` o `bank`: cómo se espera que entre. */
        public string $expectedMedium,
        public ?string $dueDate,
        public InstallmentStage $stage,
    ) {}
}
