<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Los archivos de adjuntos eliminados que no se pudieron borrar del disco
 * —o que quedaron pendientes porque el proceso se cortó después del
 * commit— se vuelven a intentar. En producción lo corre el servicio
 * `scheduler` de `deploy/compose.yml`.
 */
Schedule::command('attachments:sweep-deletions')->hourly()->withoutOverlapping();
