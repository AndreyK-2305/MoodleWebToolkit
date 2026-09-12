<?php

namespace App\Domain\Artifacts;

use App\Domain\Artifacts\Contracts\ArtifactStorage;
use App\Domain\Artifacts\DTOs\StoredArtifact;
use App\Domain\Artifacts\Streams\ArtifactReadStream;
use App\Exceptions\ArtifactIntegrityException;

final readonly class ArtifactStreamVerifier
{
    public function __construct(private ArtifactStorage $storage) {}

    public function verify(StoredArtifact $artifact): void
    {
        $stream = $this->storage->readStream($artifact->path);

        try {
            $this->verifyStream($stream, $artifact);
        } finally {
            $stream->close();
        }
    }

    public function verifyStream(ArtifactReadStream $stream, StoredArtifact $artifact, bool $rewindAfter = false): void
    {
        $hash = hash_init('sha256');
        $size = 0;

        while (! $stream->eof()) {
            $chunk = $stream->read();
            $size += strlen($chunk);
            hash_update($hash, $chunk);
        }

        if ($size !== $artifact->size || ! hash_equals($artifact->checksum, hash_final($hash))) {
            throw new ArtifactIntegrityException('El archivo fue alterado y su uso fue bloqueado.');
        }

        if ($rewindAfter) {
            $stream->rewind();
        }
    }
}
