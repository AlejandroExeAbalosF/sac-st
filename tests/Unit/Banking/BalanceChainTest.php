<?php

declare(strict_types=1);

namespace Tests\Unit\Banking;

use App\Modules\Banking\Enums\ParseStatus;
use App\Modules\Banking\Enums\SourceFormat;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Support\BalanceChain;
use App\Modules\Banking\Support\ParsedRow;
use App\Modules\Banking\Support\ParsedStatement;
use PHPUnit\Framework\TestCase;

class BalanceChainTest extends TestCase
{
    public function test_una_cadena_con_saldos_ausentes_es_no_verificable(): void
    {
        $statement = new ParsedStatement(SourceFormat::MacroOnlineCsv, [
            new ParsedRow(
                rowNumber: 1,
                raw: [],
                status: ParseStatus::Warning,
                transactionDate: '2026-08-01',
                amount: '100.00',
                direction: TransactionDirection::Credit,
                balanceAfter: null,
            ),
            new ParsedRow(
                rowNumber: 2,
                raw: [],
                status: ParseStatus::Valid,
                transactionDate: '2026-08-02',
                amount: '50.00',
                direction: TransactionDirection::Credit,
                balanceAfter: '150.00',
            ),
        ]);

        $chain = BalanceChain::verify($statement);

        $this->assertNull($chain->isValid);
        $this->assertNull($chain->failure);
    }
}
