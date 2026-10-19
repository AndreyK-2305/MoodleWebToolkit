<?php

namespace App\Domain\Remote;

use Aws\Ssm\SsmClient;

/**
 * Builds SSM clients from the default AWS credential chain (instance role,
 * environment or profile). The application never stores static AWS keys.
 */
class SsmClientFactory
{
    public function make(string $region): SsmClient
    {
        return new SsmClient([
            'region' => $region,
            'version' => '2014-11-06',
            'retries' => ['mode' => 'standard', 'max_attempts' => 3],
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
        ]);
    }
}
