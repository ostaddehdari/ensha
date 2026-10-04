<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php database-config.php APP_DIR OUTPUT_JSON\n");
    exit(2);
}

$appDir = realpath($argv[1]);
$output = $argv[2];

if ($appDir === false || ! is_file($appDir.'/vendor/autoload.php') || ! is_file($appDir.'/bootstrap/app.php')) {
    fwrite(STDERR, "Laravel bootstrap files were not found.\n");
    exit(2);
}

require $appDir.'/vendor/autoload.php';
$app = require $appDir.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connectionName = (string) config('database.default');
$connection = (array) config('database.connections.'.$connectionName, []);
$payload = [
    'driver' => (string) ($connection['driver'] ?? ''),
    'host' => (string) ($connection['host'] ?? '127.0.0.1'),
    'port' => (string) ($connection['port'] ?? ''),
    'database' => (string) ($connection['database'] ?? ''),
    'username' => (string) ($connection['username'] ?? ''),
    'password' => (string) ($connection['password'] ?? ''),
    'unix_socket' => (string) ($connection['unix_socket'] ?? ''),
];

if (file_put_contents($output, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
    fwrite(STDERR, "Could not write database configuration.\n");
    exit(1);
}

chmod($output, 0600);
