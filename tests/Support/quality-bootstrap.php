<?php

use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// CLI only; never registered as a web route or a production Artisan command.
if (PHP_SAPI !== 'cli' || ! app()->environment('testing')
    || getenv('QUALITY_HARNESS') !== '1'
    || config('database.connections.pgsql.database') !== 'moodle_toolkit_e2e') {
    fwrite(STDERR, "Quality harness requires the isolated E2E database.\n");
    exit(2);
}

return $app;
