<?php

namespace App\Domain\Remote;

use App\Exceptions\ToolOperationBlocked;
use App\Models\Artifact;
use Aws\Exception\AwsException;

/**
 * Reads and verifies source package objects in S3 with the platform's own
 * identity. The source server can only write; it never vouches for itself.
 */
class RemotePackageObjects
{
    private const MAX_DOCUMENT_BYTES = 1048576;

    public function __construct(private readonly S3ClientFactory $clients) {}

    /**
     * Verifies the package object: size, the SHA-256 written as metadata and,
     * when S3 holds a full-object SHA-256, that checksum too.
     *
     * @return array{size: int, checksum_verification: string}
     */
    public function verifyPackage(string $region, string $bucket, string $key, string $sha256, int $size): array
    {
        try {
            $head = $this->clients->make($region)->headObject(['Bucket' => $bucket, 'Key' => $key, 'ChecksumMode' => 'ENABLED']);
        } catch (AwsException $exception) {
            throw new ToolOperationBlocked('El paquete no está disponible en S3 ('.($exception->getAwsErrorCode() ?? 'error').').');
        }
        $metadata = $head['Metadata'] ?? [];
        if ((int) ($head['ContentLength'] ?? -1) !== $size || ! is_array($metadata) || ($metadata['mwt-sha256'] ?? null) !== $sha256) {
            throw new ToolOperationBlocked('El objeto en S3 no coincide con el tamaño o el SHA-256 del paquete.');
        }
        $checksum = $head['ChecksumSHA256'] ?? null;
        $type = $head['ChecksumType'] ?? null;
        $verification = 'METADATA_AND_SIZE';
        if (is_string($checksum) && $type === 'FULL_OBJECT') {
            if (! hash_equals(base64_encode((string) hex2bin($sha256)), $checksum)) {
                throw new ToolOperationBlocked('La suma SHA-256 calculada por S3 no coincide con el paquete.');
            }
            $verification = 'S3_FULL_OBJECT_SHA256';
        } elseif (is_string($checksum)) {
            // Multipart uploads carry a composite checksum of parts, validated by S3 per part.
            $verification = 'S3_COMPOSITE_SHA256';
        }

        return ['size' => $size, 'checksum_verification' => $verification];
    }

    /** Downloads a small document and checks it against the hash the server reported. */
    public function document(string $region, string $bucket, string $key, string $sha256): string
    {
        try {
            $object = $this->clients->make($region)->getObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $exception) {
            throw new ToolOperationBlocked('No se pudo leer un documento del paquete en S3 ('.($exception->getAwsErrorCode() ?? 'error').').');
        }
        if ((int) ($object['ContentLength'] ?? 0) > self::MAX_DOCUMENT_BYTES) {
            throw new ToolOperationBlocked('Un documento del paquete excede el límite de lectura.');
        }
        $body = (string) $object['Body'];
        if (strlen($body) > self::MAX_DOCUMENT_BYTES || ! hash_equals($sha256, hash('sha256', $body))) {
            throw new ToolOperationBlocked('Un documento del paquete en S3 no coincide con su hash.');
        }

        return $body;
    }

    /** @return array{region: string, bucket: string, key: string} */
    public function location(Artifact $artifact): array
    {
        $s3 = $artifact->metadata['s3'] ?? null;
        if ($artifact->disk !== 's3' || ! is_array($s3) || ! is_string($s3['region'] ?? null) || ! is_string($s3['bucket'] ?? null)
            || ! is_string($s3['key'] ?? null) || $artifact->path !== $s3['bucket'].'/'.$s3['key']) {
            throw new ToolOperationBlocked('El artefacto no tiene una ubicación S3 coherente.');
        }

        return ['region' => $s3['region'], 'bucket' => $s3['bucket'], 'key' => $s3['key']];
    }
}
