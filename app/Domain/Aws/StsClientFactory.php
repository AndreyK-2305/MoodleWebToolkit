<?php

namespace App\Domain\Aws;

use App\Models\AwsAccount;
use Aws\Sts\StsClient;

/** STS clients used only to tell the administrator who the platform is. */
class StsClientFactory
{
    public function __construct(private readonly AwsCredentials $credentials) {}

    public function forAccount(string $region, ?AwsAccount $account): StsClient
    {
        return new StsClient([
            'region' => $region,
            'version' => '2011-06-15',
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
            ...$this->credentials->clientOptions($account, $region),
        ]);
    }
}
