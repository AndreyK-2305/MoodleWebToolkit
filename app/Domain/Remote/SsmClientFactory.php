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
        // The SDK's default retries are kept on purpose: the "standard" retry mode
        // kept retrying a missing credential chain for minutes instead of failing.
        return new SsmClient([
            'region' => $region,
            'version' => '2014-11-06',
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
        ]);
    }
}
