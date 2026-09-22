<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('AUDIT_LOG_ENABLED', true),
    'slow_ms' => (int) env('AUDIT_LOG_SLOW_MS', 1000),
    'exclude' => ['up'],
];
