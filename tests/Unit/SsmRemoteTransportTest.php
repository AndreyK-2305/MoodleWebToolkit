<?php

namespace Tests\Unit;

use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\SsmClientFactory;
use App\Domain\Remote\Transports\SsmRemoteTransport;
use App\Enums\ConnectionType;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\Exception\SsmException;
use Aws\Ssm\SsmClient;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class SsmRemoteTransportTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $commands = [];

    public function test_script_is_sent_encoded_and_invocation_output_is_returned(): void
    {
        $transport = $this->transport([
            new Result(['Command' => ['CommandId' => '11111111-1111-4111-8111-111111111111']]),
            fn (CommandInterface $command) => new SsmException('pending', $command, ['code' => 'InvocationDoesNotExist']),
            new Result(['Status' => 'InProgress']),
            new Result(['Status' => 'Success', 'ResponseCode' => 0, 'StandardOutputContent' => "{\"ok\":true}\n", 'StandardErrorContent' => '']),
        ]);

        $result = $transport->run($this->target(), "echo 'hola'; id", 30);

        $this->assertSame(TransportResult::COMPLETED, $result->status);
        $this->assertSame(0, $result->exitCode);
        $this->assertSame("{\"ok\":true}\n", $result->stdout);
        $send = $this->commands[0];
        $this->assertSame('SendCommand', $send->getName());
        $this->assertSame(['i-0123456789abcdef0'], $send['InstanceIds']);
        $this->assertSame('AWS-RunShellScript', $send['DocumentName']);
        $this->assertSame(['30'], $send['Parameters']['executionTimeout']);
        $command = $send['Parameters']['commands'][0];
        $this->assertSame('printf %s '.escapeshellarg(base64_encode("echo 'hola'; id")).' | base64 -d | bash', $command);
        $this->assertSame('GetCommandInvocation', $this->commands[1]->getName());
    }

    public function test_failed_scripts_return_their_exit_code(): void
    {
        $result = $this->transport([
            new Result(['Command' => ['CommandId' => '22222222-2222-4222-8222-222222222222']]),
            new Result(['Status' => 'Failed', 'ResponseCode' => 127, 'StandardOutputContent' => 'x', 'StandardErrorContent' => '']),
        ])->run($this->target(), 'exit 127', 30);

        $this->assertSame(TransportResult::COMPLETED, $result->status);
        $this->assertSame(127, $result->exitCode);
    }

    public function test_unmanaged_instances_are_unreachable_with_a_closed_code(): void
    {
        $result = $this->transport([
            fn (CommandInterface $command) => new SsmException('Instances [i-...] not in a valid state', $command, ['code' => 'InvalidInstanceId']),
        ])->run($this->target(), 'true', 30);

        $this->assertSame(TransportResult::UNREACHABLE, $result->status);
        $this->assertSame('ssm_instance_not_managed', $result->errorCode);
        $this->assertSame('', $result->stderr);
    }

    public function test_remote_timeout_leaves_the_state_unknown(): void
    {
        $result = $this->transport([
            new Result(['Command' => ['CommandId' => '33333333-3333-4333-8333-333333333333']]),
            new Result(['Status' => 'TimedOut', 'StandardOutputContent' => '', 'StandardErrorContent' => '']),
        ])->run($this->target(), 'sleep 999', 30);

        $this->assertSame(TransportResult::TIMED_OUT, $result->status);
        $this->assertNull($result->exitCode);
    }

    /** @param list<mixed> $queue */
    private function transport(array $queue): SsmRemoteTransport
    {
        $handler = new MockHandler($queue);
        $recorder = function (CommandInterface $command, RequestInterface $request) use ($handler) {
            $this->commands[] = $command;

            return $handler($command, $request);
        };
        $client = new SsmClient([
            'region' => 'us-east-1', 'version' => '2014-11-06', 'handler' => $recorder,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
        ]);
        $factory = new class($client) extends SsmClientFactory
        {
            public function __construct(private readonly SsmClient $client) {}

            public function make(string $region): SsmClient
            {
                return $this->client;
            }
        };

        return new SsmRemoteTransport($factory, static function (int $milliseconds): void {});
    }

    private function target(): RemoteTarget
    {
        return new RemoteTarget(ConnectionType::AWS_SSM, '00000000-0000-4000-8000-000000000001', instanceId: 'i-0123456789abcdef0', region: 'us-east-1');
    }
}
