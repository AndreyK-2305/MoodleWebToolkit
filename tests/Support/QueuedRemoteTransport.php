<?php

namespace Tests\Support;

use App\Domain\Remote\Contracts\RemoteTransport;
use App\Domain\Remote\DTOs\RemoteTarget;
use App\Domain\Remote\DTOs\TransportResult;
use App\Domain\Remote\RemoteAgent;
use App\Domain\Remote\RemoteTransportResolver;
use Closure;
use Illuminate\Contracts\Foundation\Application;

/** Scripted transport: returns queued results in order and records each script. */
final class QueuedRemoteTransport implements RemoteTransport
{
    /** @var list<TransportResult|Closure(string): TransportResult> */
    private array $results = [];

    /** @var list<string> */
    public array $scripts = [];

    public static function bind(Application $app): self
    {
        $transport = new self;
        $app->instance(RemoteTransportResolver::class, new class($transport) extends RemoteTransportResolver
        {
            public function __construct(private readonly RemoteTransport $transport) {}

            public function for(RemoteTarget $target): RemoteTransport
            {
                return $this->transport;
            }
        });

        return $transport;
    }

    /** @param TransportResult|Closure(string): TransportResult $result */
    public function queue(TransportResult|Closure $result): void
    {
        $this->results[] = $result;
    }

    /** @param array<string, mixed> $data */
    public function ok(string $command, array $data): void
    {
        $this->queue(new TransportResult(TransportResult::COMPLETED, 0, $this->document(true, $command, $data), ''));
    }

    /**
     * Answers with data built from the script, e.g. to echo the operation UUID.
     *
     * @param  Closure(string): array<string, mixed>  $data
     */
    public function okWith(string $command, Closure $data): void
    {
        $this->queue(fn (string $script): TransportResult => new TransportResult(TransportResult::COMPLETED, 0, $this->document(true, $command, $data($script)), ''));
    }

    /** The first UUID argument of an agent call script. */
    public static function operationUuid(string $script): string
    {
        // escapeshellarg quotes with ' on POSIX and " on Windows.
        preg_match('/[\'"](launch|status|tail|cancel)[\'"] [\'"]([0-9a-f-]{36})[\'"]/', $script, $matches);

        return $matches[2] ?? '';
    }

    public function error(string $command, string $code, int $exitCode = 2): void
    {
        $this->queue(new TransportResult(TransportResult::COMPLETED, $exitCode, $this->document(false, $command, ['code' => $code]), ''));
    }

    public function unreachable(string $code = 'ssm_instance_not_managed'): void
    {
        $this->queue(new TransportResult(TransportResult::UNREACHABLE, null, '', 'raw remote text', $code));
    }

    public function key(): string
    {
        return 'queued';
    }

    public function run(RemoteTarget $target, string $script, int $timeoutSeconds): TransportResult
    {
        $this->scripts[] = $script;

        $result = array_shift($this->results) ?? new TransportResult(TransportResult::UNREACHABLE, null, '', '', 'no_queued_result');

        return $result instanceof Closure ? $result($script) : $result;
    }

    /** @return list<string> Agent subcommands sent so far, in order. */
    public function subcommands(): array
    {
        return array_map(function (string $script): string {
            preg_match('/"\$agent" [\'"]([a-z]+)[\'"]/', $script, $matches);

            return $matches[1] ?? 'install';
        }, $this->scripts);
    }

    public function pending(): int
    {
        return count($this->results);
    }

    /** @param array<string, mixed> $payload */
    private function document(bool $ok, string $command, array $payload): string
    {
        $document = ['schema' => RemoteAgent::SCHEMA, 'ok' => $ok, 'agent_version' => app(RemoteAgent::class)->distribution()['version'], 'command' => $command];
        $document[$ok ? 'data' : 'error'] = $payload;

        return json_encode($document, JSON_THROW_ON_ERROR)."\n";
    }
}
