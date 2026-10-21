<?php

namespace App\Database;

use Illuminate\Database\PostgresConnection;

/** PostgreSQL connection whose query grammar keeps the offset of every date. */
class OffsetPostgresConnection extends PostgresConnection
{
    protected function getDefaultQueryGrammar()
    {
        return new OffsetPostgresGrammar($this);
    }
}
