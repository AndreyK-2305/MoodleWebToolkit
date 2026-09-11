<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Exceptions\ArtifactIntegrityException;

final readonly class ArtifactStreamVerifier
{
    public function __construct(private ArtifactStorage $storage) {}

    public function verify(StoredArtifact $artifact): void
    {
        $stream = $this->storage->readStream($artifact->path);
        $hash = hash_init('sha256');
        $size = 0;

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read();
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
        } finally {
            $stream->close();
        }

        if ($size !== $artifact->size || ! hash_equals($artifact->checksum, hash_final($hash))) {
            throw new ArtifactIntegrityException('El archivo fue alterado y su uso fue bloqueado.');
        }
    }
}
