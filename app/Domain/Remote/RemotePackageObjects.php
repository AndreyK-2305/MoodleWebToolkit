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

    public const DOWNLOAD_SECONDS = 900;

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

    /**
     * Confirms a server's upload probe with the platform's own identity: the object exists
     * under mwt/_pruebas/ with the size and SHA-256 the server reported. It is then deleted
     * when the platform's identity may do so; a probe left behind is a few bytes.
     *
     * @return array{confirmed: bool, deleted: bool, error_code: string|null}
     */
    public function confirmProbe(string $region, string $bucket, string $key, string $sha256, int $size): array
    {
        if (preg_match('#^mwt/_pruebas/[a-z0-9][a-z0-9-]{0,62}/[0-9a-f]{16}$#D', $key) !== 1) {
            return ['confirmed' => false, 'deleted' => false, 'error_code' => 'probe_key_invalid'];
        }
        $client = $this->clients->make($region);
        try {
            $head = $client->headObject(['Bucket' => $bucket, 'Key' => $key]);
        } catch (AwsException $exception) {
            return ['confirmed' => false, 'deleted' => false, 'error_code' => 'storage_head_'.strtolower((string) preg_replace('/[^A-Za-z]/', '', $exception->getAwsErrorCode() ?? 'error'))];
        }
        $metadata = $head['Metadata'] ?? [];
        $confirmed = (int) ($head['ContentLength'] ?? -1) === $size && is_array($metadata) && ($metadata['mwt-sha256'] ?? null) === $sha256;
        try {
            $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $deleted = true;
        } catch (AwsException) {
            $deleted = false;
        }

        return ['confirmed' => $confirmed, 'deleted' => $deleted, 'error_code' => $confirmed ? null : 'storage_probe_mismatch'];
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

    /**
     * A short-lived URL to the verified package. The object is checked again
     * first, so a link is never issued for a package that changed in S3.
     */
    public function downloadUrl(Artifact $artifact): string
    {
        $location = $this->location($artifact);
        $this->verifyPackage($location['region'], $location['bucket'], $location['key'], (string) $artifact->sha256, (int) $artifact->size);
        $client = $this->clients->make($location['region']);
        $command = $client->getCommand('GetObject', ['Bucket' => $location['bucket'], 'Key' => $location['key'],
            'ResponseContentDisposition' => 'attachment; filename="'.str_replace('"', '', (string) $artifact->filename).'"']);

        return (string) $client->createPresignedRequest($command, '+'.self::DOWNLOAD_SECONDS.' seconds')->getUri();
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
