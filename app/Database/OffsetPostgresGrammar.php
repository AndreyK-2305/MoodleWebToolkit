<?php

namespace App\Database;

use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * Writes dates with their UTC offset. Laravel's default 'Y-m-d H:i:s' drops
 * it, so a timestamptz column read a local wall clock (America/Bogota) as if
 * it were UTC and stored the instant five hours off; comparisons in queries
 * shifted the same way. With the offset, PostgreSQL stores the real instant
 * whichever zone the Carbon value is in.
 */
class OffsetPostgresGrammar extends PostgresGrammar
{
    public function getDateFormat()
    {
        return 'Y-m-d H:i:sP';
    }
}
