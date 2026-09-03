<?php

namespace App\Domain\Connections\Contracts;

use App\Domain\Connections\DTOs\ConnectionResult;
use App\Domain\Processes\DTOs\ProcessResult;

interface ConnectionProvider
{
    /** @param array<string, mixed> $configuration */
    public function connect(array $configuration): ConnectionResult;

    public function test(): ConnectionResult;

    /** @param list<string> $command */
    public function execute(array $command): ProcessResult;

    public function upload(string $localPath, string $remotePath): ConnectionResult;

    public function download(string $remotePath, string $localPath): ConnectionResult;

    public function exists(string $path): bool;

    public function disconnect(): void;
}
