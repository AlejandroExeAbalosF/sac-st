<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

final class AuditJsonFormatter extends JsonFormatter
{
    public function format(LogRecord $record): string
    {
        return json_encode(
            ['channel' => 'audit', ...$record->context],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n";
    }
}
