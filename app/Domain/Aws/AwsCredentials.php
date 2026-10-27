<?php

namespace App\Domain\Aws;

use App\Enums\AwsCredentialMode;
use App\Models\AwsAccount;
use App\Models\AwsStorageSettings;
use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Exception\CredentialsException;
use Aws\Sts\StsClient;

/**
 * Resolves which AWS identity a client uses: the account a connection selects,
 * else the default account, else the installation's default SDK chain (instance
 * role, environment or mounted profile), so an unconfigured platform keeps working.
 */
class AwsCredentials
{
    public const DEFAULT_SESSION_NAME = 'MoodleWebToolkit';

    /** @var array<string, callable> Assumed-role providers, memoized per account version and region. */
    private static array $assumed = [];

    public function resolve(?string $accountUuid): ?AwsAccount
    {
        if ($accountUuid === null) {
            return AwsAccount::default();
        }
        $account = AwsAccount::query()->where('uuid', $accountUuid)->first();
        if ($account === null) {
            // Never fall back silently: the connection asked for a specific identity.
            throw new CredentialsException('La cuenta AWS elegida para esta conexión ya no existe.');
        }

        return $account;
    }

    /** The account that verifies packages in the bucket: the storage's own, else the default. */
    public function forStorage(): ?AwsAccount
    {
        return AwsStorageSettings::current()->account ?? AwsAccount::default();
    }

    /**
     * Client options for one account; an empty array leaves the SDK's default chain.
     *
     * @return array{credentials?: Credentials|callable}
     */
    public function clientOptions(?AwsAccount $account, string $region): array
    {
        if ($account === null || $account->mode === AwsCredentialMode::ENVIRONMENT) {
            return [];
        }
        if ($account->mode === AwsCredentialMode::ACCESS_KEYS) {
            if (($account->access_key_id ?? '') === '' || ($account->secret_access_key ?? '') === '') {
                throw new CredentialsException('La cuenta AWS no tiene claves de acceso completas.');
            }

            return ['credentials' => new Credentials((string) $account->access_key_id, (string) $account->secret_access_key)];
        }
        if (($account->role_arn ?? '') === '') {
            throw new CredentialsException('La cuenta AWS no indica el rol que debe asumir.');
        }
        $key = $account->id.':'.($account->updated_at?->getTimestamp() ?? 0).':'.$region;

        return ['credentials' => self::$assumed[$key] ??= CredentialProvider::memoize(CredentialProvider::assumeRole([
            // The role is assumed on top of the installation's own identity.
            'client' => new StsClient(['region' => $region, 'version' => '2011-06-15', 'http' => ['connect_timeout' => 10, 'timeout' => 30]]),
            'assume_role_params' => array_filter([
                'RoleArn' => (string) $account->role_arn,
                'RoleSessionName' => ($account->session_name ?? '') !== '' ? $account->session_name : self::DEFAULT_SESSION_NAME,
                'ExternalId' => ($account->external_id ?? '') !== '' ? $account->external_id : null,
                'DurationSeconds' => 3600,
            ], fn (mixed $value): bool => $value !== null),
        ]))];
    }
}
