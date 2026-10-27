<?php

namespace App\Domain\Remote\Transports;

use App\Domain\Remote\Contracts\RemoteTransport;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\SsmClientFactory;
use App\Enums\ConnectionType;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Closure;
use InvalidArgumentException;

/**
 * Sends one bounded AWS-RunShellScript command and waits for its invocation.
 * The command channel is released as soon as the script returns; detached
 * work keeps running on the instance and is observed by later calls.
 */
final class SsmRemoteTransport implements RemoteTransport
{
    private const TERMINAL = ['Success', 'Failed', 'Cancelled', 'TimedOut'];

    /** @var Closure(int): void */
    private Closure $sleeper;

    /** @param (Closure(int): void)|null $sleeper */
    public function __construct(private readonly SsmClientFactory $clients, ?Closure $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function key(): string
    {
        return ConnectionType::AWS_SSM->value;
    }

    public function run(RemoteTarget $target, string $script, int $timeoutSeconds): TransportResult
    {
        if ($target->type !== ConnectionType::AWS_SSM || $target->instanceId === null || $target->region === null) {
            throw new InvalidArgumentException('El destino no es una instancia administrada por SSM.');
        }
        $timeoutSeconds = max(5, min($timeoutSeconds, 600));
        try {
            $client = $this->clients->forConnection($target->region, $target->awsAccount);
        } catch (CredentialsException) {
            return new TransportResult(TransportResult::UNREACHABLE, null, '', '', 'aws_credentials_unavailable');
        }
        // AWS-RunShellScript uses sh; decode the backend-built script and hand it to bash.
        // The base64 alphabet has no quote characters, so single quotes are sufficient.
        $command = "printf %s '".base64_encode($script)."' | base64 -d | bash";

        try {
            $sent = $client->sendCommand([
                'InstanceIds' => [$target->instanceId],
                'DocumentName' => (string) config('toolkit.remote.ssm.document', 'AWS-RunShellScript'),
                'Comment' => 'MoodleWebToolkit '.$target->serverUuid,
                'TimeoutSeconds' => 30,
                'Parameters' => [
                    'commands' => [$command],
                    'executionTimeout' => [(string) $timeoutSeconds],
                ],
            ]);
        } catch (CredentialsException) {
            return new TransportResult(TransportResult::UNREACHABLE, null, '', '', 'aws_credentials_unavailable');
        } catch (AwsException $exception) {
            return new TransportResult(TransportResult::UNREACHABLE, null, '', '', $this->errorCode($exception));
        }
        $commandId = $sent['Command']['CommandId'] ?? null;
        if (! is_string($commandId) || $commandId === '') {
            return new TransportResult(TransportResult::UNREACHABLE, null, '', '', 'ssm_command_not_accepted');
        }

        $deadline = hrtime(true) + ($timeoutSeconds + 15) * 1_000_000_000;
        $interval = max(200, (int) config('toolkit.remote.ssm.poll_interval_milliseconds', 1000));
        do {
            ($this->sleeper)($interval);
            try {
                $invocation = $client->getCommandInvocation(['CommandId' => $commandId, 'InstanceId' => $target->instanceId]);
            } catch (AwsException $exception) {
                if ($exception->getAwsErrorCode() === 'InvocationDoesNotExist') {
                    continue;
                }

                return new TransportResult(TransportResult::UNREACHABLE, null, '', '', $this->errorCode($exception));
            }
            $status = (string) ($invocation['Status'] ?? '');
            if (in_array($status, self::TERMINAL, true)) {
                $stdout = (string) ($invocation['StandardOutputContent'] ?? '');
                $stderr = (string) ($invocation['StandardErrorContent'] ?? '');
                if ($status === 'TimedOut') {
                    return new TransportResult(TransportResult::TIMED_OUT, null, $stdout, $stderr, 'ssm_execution_timed_out');
                }
                if ($status === 'Cancelled') {
                    return new TransportResult(TransportResult::UNREACHABLE, null, $stdout, $stderr, 'ssm_command_cancelled');
                }
                $code = $invocation['ResponseCode'] ?? null;

                return new TransportResult(TransportResult::COMPLETED, is_int($code) ? $code : null, $stdout, $stderr);
            }
        } while (hrtime(true) < $deadline);

        try {
            $client->cancelCommand(['CommandId' => $commandId, 'InstanceIds' => [$target->instanceId]]);
        } catch (AwsException) {
            // The invocation remains unknown; the caller reconciles later.
        }

        return new TransportResult(TransportResult::TIMED_OUT, null, '', '', 'ssm_invocation_deadline');
    }

    private function errorCode(AwsException $exception): string
    {
        return match ($exception->getAwsErrorCode()) {
            'InvalidInstanceId' => 'ssm_instance_not_managed',
            'AccessDeniedException' => 'ssm_access_denied',
            'ThrottlingException' => 'ssm_throttled',
            'InvalidDocument' => 'ssm_invalid_document',
            default => 'ssm_request_failed',
        };
    }
}
