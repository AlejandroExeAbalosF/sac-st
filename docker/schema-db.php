<?php

declare(strict_types=1);

/*
 * Crea o descarta una base auxiliar para comparar esquemas.
 *
 *   php docker/schema-db.php crear  <nombre>
 *   php docker/schema-db.php borrar <nombre>
 *
 * Usa la conexion que ya tiene configurada la aplicacion y se conecta a
 * `postgres` para operar sobre la base pedida. Solo acepta nombres que
 * empiecen con `sacst_schema_`, para que un error de tipeo no pueda
 * alcanzar ni a `sacst_dev` ni a `sacst_test`.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$modo = $argv[1] ?? '';
$nombre = $argv[2] ?? '';

if (! str_starts_with($nombre, 'sacst_schema_')) {
    fwrite(STDERR, "Solo bases 'sacst_schema_*'. Recibido: '$nombre'".PHP_EOL);
    exit(1);
}

$cfg = config('database.connections.pgsql');
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $cfg['host'], $cfg['port']);
$pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

if ($modo === 'crear') {
    $pdo->exec("DROP DATABASE IF EXISTS \"$nombre\"");
    $pdo->exec("CREATE DATABASE \"$nombre\"");
    echo "creada: $nombre".PHP_EOL;
} elseif ($modo === 'borrar') {
    $pdo->exec("DROP DATABASE IF EXISTS \"$nombre\"");
    echo "borrada: $nombre".PHP_EOL;
} else {
    fwrite(STDERR, 'Modo: crear | borrar'.PHP_EOL);
    exit(1);
}
