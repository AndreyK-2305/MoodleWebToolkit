<?php

namespace App\Domain\Remote;

use App\Domain\Aws\AwsCredentials;
use App\Models\AwsAccount;
use Aws\S3\S3Client;

/** S3 clients with the storage's account, else the default account, else the default AWS chain. */
class S3ClientFactory
{
    public function __construct(private readonly AwsCredentials $credentials) {}

    public function make(string $region): S3Client
    {
        return $this->forAccount($region, $this->credentials->forStorage());
    }

    public function forAccount(string $region, ?AwsAccount $account): S3Client
    {
        return new S3Client([
            'region' => $region,
            'version' => '2006-03-01',
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
            ...$this->credentials->clientOptions($account, $region),
        ]);
    }
}
