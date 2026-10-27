<?php

namespace App\Domain\Remote;

use App\Domain\Aws\AwsCredentials;
use App\Models\AwsAccount;
use Aws\Ssm\SsmClient;

/**
 * Builds SSM clients with the identity an administrator configured: the account
 * a connection selects, else the default account, else the default AWS chain
 * (instance role, environment or profile).
 */
class SsmClientFactory
{
    public function __construct(private readonly AwsCredentials $credentials) {}

    public function make(string $region): SsmClient
    {
        return $this->forAccount($region, $this->credentials->resolve(null));
    }

    /** The client for the account a connection selected by its public id. */
    public function forConnection(string $region, ?string $accountUuid): SsmClient
    {
        return $accountUuid === null ? $this->make($region) : $this->forAccount($region, $this->credentials->resolve($accountUuid));
    }

    public function forAccount(string $region, ?AwsAccount $account): SsmClient
    {
        // The SDK's default retries are kept on purpose: the "standard" retry mode
        // kept retrying a missing credential chain for minutes instead of failing.
        return new SsmClient([
            'region' => $region,
            'version' => '2014-11-06',
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
            ...$this->credentials->clientOptions($account, $region),
        ]);
    }
}
