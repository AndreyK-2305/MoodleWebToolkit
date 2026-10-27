<?php

namespace App\Domain\Aws;

use App\Domain\Remote\S3ClientFactory;
use App\Domain\Remote\SsmClientFactory;
use App\Enums\AwsCredentialMode;
use App\Models\AwsAccount;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells an administrator, in plain words, what the platform can do with an AWS
 * identity: who it is, how many SSM instances it sees online and whether it can
 * reach the package bucket. Results carry AWS error codes, never secrets.
 */
class AwsConnectionCheck
{
    public const TEST_PREFIX = 'mwt/_pruebas/';

    private const MAX_INSTANCE_PAGES = 10;

    public function __construct(
        private readonly StsClientFactory $sts,
        private readonly SsmClientFactory $ssm,
        private readonly S3ClientFactory $s3,
    ) {}

    /**
     * @return array{ok: bool, identity: array{account: string, arn: string}|null, online_instances: int|null, region: string, checks: list<array{label: string, ok: bool, message: string}>}
     */
    public function account(?AwsAccount $account, string $region): array
    {
        $checks = [];
        try {
            $caller = $this->sts->forAccount($region, $account)->getCallerIdentity();
            $identity = ['account' => (string) ($caller['Account'] ?? ''), 'arn' => (string) ($caller['Arn'] ?? '')];
            $checks[] = ['label' => 'Identidad (sts:GetCallerIdentity)', 'ok' => true,
                'message' => 'La plataforma actúa como '.$identity['arn'].' en la cuenta '.$identity['account'].'.'];
        } catch (Throwable $exception) {
            $checks[] = ['label' => 'Identidad (sts:GetCallerIdentity)', 'ok' => false, 'message' => $this->explain($exception, $account, 'sts:GetCallerIdentity')];

            return ['ok' => false, 'identity' => null, 'online_instances' => null, 'region' => $region, 'checks' => $checks];
        }

        $online = null;
        try {
            $client = $this->ssm->forAccount($region, $account);
            $count = 0;
            $token = null;
            $pages = 0;
            do {
                $page = $client->describeInstanceInformation(array_filter([
                    'Filters' => [['Key' => 'PingStatus', 'Values' => ['Online']]],
                    'MaxResults' => 50,
                    'NextToken' => $token,
                ], fn (mixed $value): bool => $value !== null));
                $list = $page['InstanceInformationList'] ?? null;
                $count += is_array($list) ? count($list) : 0;
                $token = is_string($page['NextToken'] ?? null) && $page['NextToken'] !== '' ? $page['NextToken'] : null;
            } while ($token !== null && ++$pages < self::MAX_INSTANCE_PAGES);
            $online = $count;
            $checks[] = ['label' => 'Instancias SSM (ssm:DescribeInstanceInformation)', 'ok' => true, 'message' => match (true) {
                $count === 0 => 'No hay instancias en línea en '.$region.'. Revise la región o que el agente SSM de los servidores esté activo.',
                $count === 1 => 'Hay 1 instancia en línea en '.$region.'.',
                default => 'Hay '.$count.($token !== null ? ' o más' : '').' instancias en línea en '.$region.'.',
            }];
        } catch (Throwable $exception) {
            $checks[] = ['label' => 'Instancias SSM (ssm:DescribeInstanceInformation)', 'ok' => false,
                'message' => $this->explain($exception, $account, 'ssm:DescribeInstanceInformation')];
        }

        return ['ok' => $online !== null, 'identity' => $identity, 'online_instances' => $online, 'region' => $region, 'checks' => $checks];
    }

    /**
     * HeadBucket, then put, head and delete of a small object under mwt/_pruebas/.
     * The test object is removed even when reading it back fails.
     *
     * @return array{ok: bool, checks: list<array{label: string, ok: bool, message: string}>}
     */
    public function storage(string $bucket, string $region, ?AwsAccount $account): array
    {
        $key = self::TEST_PREFIX.now()->format('Ymd\THis').'-'.Str::lower(Str::random(8)).'.txt';
        try {
            $client = $this->s3->forAccount($region, $account);
        } catch (Throwable $exception) {
            return ['ok' => false, 'checks' => [['label' => 'Credenciales', 'ok' => false, 'message' => $this->explain($exception, $account, 's3')]]];
        }

        $checks = [];
        $step = function (string $label, string $action, callable $call) use (&$checks, $account): bool {
            try {
                $call();
                $checks[] = ['label' => $label, 'ok' => true, 'message' => 'Correcto.'];

                return true;
            } catch (Throwable $exception) {
                $checks[] = ['label' => $label, 'ok' => false, 'message' => $this->explain($exception, $account, $action)];

                return false;
            }
        };

        $ok = $step('El bucket existe y es accesible (HeadBucket)', 's3:ListBucket', fn () => $client->headBucket(['Bucket' => $bucket]))
            && $step('Escribir un objeto de prueba (PutObject)', 's3:PutObject', fn () => $client->putObject([
                'Bucket' => $bucket, 'Key' => $key, 'Body' => 'MoodleWebToolkit: objeto de prueba; se puede borrar.', 'ContentType' => 'text/plain',
            ]));
        if ($ok) {
            $read = $step('Leer el objeto de prueba (HeadObject)', 's3:GetObject', fn () => $client->headObject(['Bucket' => $bucket, 'Key' => $key]));
            $deleted = $step('Borrar el objeto de prueba (DeleteObject)', 's3:DeleteObject', fn () => $client->deleteObject(['Bucket' => $bucket, 'Key' => $key]));
            $ok = $read && $deleted;
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    private function explain(Throwable $exception, ?AwsAccount $account, string $action): string
    {
        if ($exception instanceof CredentialsException) {
            return $account?->mode === AwsCredentialMode::ASSUME_ROLE
                ? 'No se pudo asumir el rol. Revise el ARN, la relación de confianza del rol, el External ID y que la identidad base tenga sts:AssumeRole.'
                : 'No se encontraron credenciales de AWS. En «Rol del entorno», el servidor de la plataforma necesita un rol IAM o un perfil configurado.';
        }
        if (! $exception instanceof AwsException) {
            return 'No se pudo contactar con AWS. Revise la conexión de red de la plataforma.';
        }
        $code = (string) ($exception->getAwsErrorCode() ?? '');
        $status = $exception->getStatusCode();

        return match (true) {
            in_array($code, ['InvalidClientTokenId', 'UnrecognizedClientException', 'SignatureDoesNotMatch', 'InvalidAccessKeyId'], true) => 'AWS rechazó las claves de acceso: no existen, están desactivadas o el secreto no corresponde.',
            in_array($code, ['ExpiredToken', 'ExpiredTokenException'], true) => 'Las credenciales temporales expiraron. Vuelva a intentarlo.',
            in_array($code, ['AccessDenied', 'AccessDeniedException'], true) || $status === 403 => 'La identidad no tiene permiso para '.$action.'. Revise la política IAM sugerida en esta página.',
            in_array($code, ['NoSuchBucket', 'NotFound'], true) || $status === 404 => 'El bucket no existe o el nombre está mal escrito.',
            in_array($code, ['PermanentRedirect', 'AuthorizationHeaderMalformed'], true) || $status === 301 => 'El bucket está en otra región. Corrija la región del almacenamiento.',
            $code === 'ThrottlingException' => 'AWS limitó las solicitudes. Espere un momento y vuelva a intentarlo.',
            default => 'AWS respondió con un error'.($code !== '' ? ' ('.$code.')' : ($status !== null ? ' (HTTP '.$status.')' : '')).'.',
        };
    }
}
