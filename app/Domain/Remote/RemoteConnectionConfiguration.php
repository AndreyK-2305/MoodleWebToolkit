<?php

namespace App\Domain\Remote;

use App\Enums\ConnectionType;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalizes the non-secret part of a remote connection.
 * Unknown keys are rejected so that no free-form value reaches a transport.
 */
final class RemoteConnectionConfiguration
{
    public const SCHEMA = 'remote-connection.v1';

    private const INSTANCE_ID = '/^(i|mi)-[0-9a-f]{8,17}$/D';

    private const REGION = '/^[a-z]{2}(-gov)?-[a-z]+-[1-9]$/D';

    private const HOSTNAME = '/^(?=.{1,253}$)([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)(\.[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/D';

    private const USER = '/^[a-z_][a-z0-9_-]{0,31}$/D';

    private const HOST_KEY = '/^(ssh-ed25519|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521|ssh-rsa) [A-Za-z0-9+\/]{40,1024}={0,3}$/D';

    private const SECRET_REFERENCE = '/^[a-z][a-z0-9-]{2,63}$/D';

    private const SECRET_VERSION = '/^[1-9][0-9]{0,8}$/D';

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|bool>
     */
    public function normalize(ConnectionType $type, array $input): array
    {
        $allowed = match ($type) {
            ConnectionType::AWS_SSM => ['schema', 'instance_id', 'region'],
            ConnectionType::SSH => ['schema', 'host', 'port', 'user', 'host_key', 'secret_version', 'use_sudo'],
        };
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['configuration' => 'La configuración contiene campos no permitidos para este tipo de conexión.']);
        }
        if (isset($input['schema']) && $input['schema'] !== self::SCHEMA) {
            throw ValidationException::withMessages(['configuration' => 'La versión de la configuración de conexión no es compatible.']);
        }

        return match ($type) {
            ConnectionType::AWS_SSM => $this->ssm($input),
            ConnectionType::SSH => $this->ssh($input),
        };
    }

    public function assertSecretReference(ConnectionType $type, ?string $reference): void
    {
        if ($type === ConnectionType::AWS_SSM) {
            if ($reference !== null) {
                throw ValidationException::withMessages(['secret_reference' => 'SSM usa el rol IAM del servidor web; no admite una credencial almacenada.']);
            }

            return;
        }
        if ($reference === null || preg_match(self::SECRET_REFERENCE, $reference) !== 1) {
            throw ValidationException::withMessages(['secret_reference' => 'SSH requiere una referencia válida a la llave privada.']);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    private function ssm(array $input): array
    {
        $instance = $this->string($input, 'instance_id');
        $region = $this->string($input, 'region');
        $errors = [];
        if ($instance === null || preg_match(self::INSTANCE_ID, $instance) !== 1) {
            $errors['instance_id'] = 'Indique un ID de instancia administrada válido, por ejemplo i-0123456789abcdef0.';
        }
        if ($region === null || preg_match(self::REGION, $region) !== 1) {
            $errors['region'] = 'Indique una región AWS válida, por ejemplo us-east-2.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['schema' => self::SCHEMA, 'instance_id' => (string) $instance, 'region' => (string) $region];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|int|bool>
     */
    private function ssh(array $input): array
    {
        $host = $this->string($input, 'host');
        $host = $host === null ? null : mb_strtolower($host);
        $user = $this->string($input, 'user');
        $hostKey = $this->string($input, 'host_key');
        $version = $this->string($input, 'secret_version') ?? (is_int($input['secret_version'] ?? null) ? (string) $input['secret_version'] : null);
        $port = $input['port'] ?? 22;
        if (is_string($port) && ctype_digit($port)) {
            $port = (int) $port;
        }
        $useSudo = $input['use_sudo'] ?? false;
        $errors = [];
        if ($host === null || (filter_var($host, FILTER_VALIDATE_IP) === false && preg_match(self::HOSTNAME, $host) !== 1)) {
            $errors['host'] = 'Indique un nombre de host o una dirección IP, sin esquema, usuario ni ruta.';
        }
        if (! is_int($port) || $port < 1 || $port > 65535) {
            $errors['port'] = 'El puerto SSH debe estar entre 1 y 65535.';
        }
        if ($user === null || preg_match(self::USER, $user) !== 1) {
            $errors['user'] = 'Indique un usuario Linux válido.';
        }
        if ($hostKey === null || preg_match(self::HOST_KEY, $hostKey) !== 1) {
            $errors['host_key'] = 'Indique la llave pública del host (tipo y valor base64) para fijarla; no se aceptan llaves desconocidas.';
        }
        if ($version === null || preg_match(self::SECRET_VERSION, $version) !== 1) {
            $errors['secret_version'] = 'Indique la versión de la referencia de la llave privada.';
        }
        if (! is_bool($useSudo)) {
            $errors['use_sudo'] = 'El uso de sudo debe ser verdadero o falso.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'schema' => self::SCHEMA,
            'host' => (string) $host,
            'port' => (int) $port,
            'user' => (string) $user,
            'host_key' => (string) $hostKey,
            'secret_version' => (string) $version,
            'use_sudo' => (bool) $useSudo,
        ];
    }

    /** @param array<string, mixed> $input */
    private function string(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
