<?php

declare(strict_types=1);

/*
 * Consulta el estado de la base para `setup.sh`.
 *
 * Existe porque el script no puede armar el DSN por su cuenta: la conexión
 * sale de mezclar el `.env` con las variables que inyecta compose, y sólo
 * Laravel sabe cómo queda esa mezcla. Un `new PDO` con `getenv()` en el
 * medio se desincroniza en cuanto una de las dos puntas cambia —ya pasó, el
 * día que el nombre de la base dejó de venir por entorno—.
 *
 *   php docker/db-state.php ping    imprime el nombre de la base si conecta
 *   php docker/db-state.php users   imprime cuántos usuarios hay
 *
 * Sale con código distinto de cero si no puede responder.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $connection = $app->make('db')->connection();

    echo match ($argv[1] ?? 'ping') {
        'ping' => $connection->getPdo() ? $connection->getDatabaseName() : '',
        'users' => (string) $connection->table('users')->count(),
        default => throw new InvalidArgumentException('Modo desconocido: '.($argv[1] ?? '')),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage().PHP_EOL);

    exit(1);
}
