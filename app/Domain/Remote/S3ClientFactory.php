<?php

namespace App\Domain\Remote;

use Aws\S3\S3Client;

/** S3 clients from the default AWS credential chain; no static keys in the application. */
class S3ClientFactory
{
    public function make(string $region): S3Client
    {
        return new S3Client([
            'region' => $region,
            'version' => '2006-03-01',
            'http' => ['connect_timeout' => 10, 'timeout' => 30],
        ]);
    }
}
