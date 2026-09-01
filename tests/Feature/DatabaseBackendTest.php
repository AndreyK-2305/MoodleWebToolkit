<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseBackendTest extends TestCase
{
    public function test_the_suite_uses_the_dedicated_postgresql_database(): void
    {
        $connection = DB::connection();
        $server = DB::selectOne(
            'select current_database() as database, version() as version',
        );

        $this->assertSame('pgsql', $connection->getDriverName());
        $this->assertSame('moodle_toolkit_testing', $server->database);
        $this->assertStringContainsString('PostgreSQL', $server->version);
    }
}
