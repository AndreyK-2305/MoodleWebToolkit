<?php

namespace App\Console\Commands;

use App\Domain\Remote\InstallRemoteAgent;
use App\Domain\Remote\RemoteServerConnections;
use App\Domain\Remote\TestRemoteConnection;
use App\Enums\ConnectionType;
use App\Models\Server;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ManageRemoteConnection extends Command
{
    protected $signature = 'remote:connection
        {action : configure, install-agent o test}
        {server : UUID del servidor registrado en el proyecto}
        {--type= : aws-ssm o ssh}
        {--instance-id= : ID de la instancia administrada por SSM}
        {--region= : Región AWS de la instancia}
        {--host= : Host SSH}
        {--port=22 : Puerto SSH}
        {--user= : Usuario SSH}
        {--host-key= : Llave pública del host, por ejemplo "ssh-ed25519 AAAA..."}
        {--secret-reference= : Referencia a la llave privada SSH}
        {--secret-version= : Versión de la referencia de la llave}
        {--sudo : Ejecutar el agente con sudo -n por SSH}';

    protected $description = 'Configura, instala el agente o prueba la conexión remota de un servidor.';

    public function handle(RemoteServerConnections $connections, InstallRemoteAgent $installer, TestRemoteConnection $tester): int
    {
        $server = Server::query()->where('uuid', (string) $this->argument('server'))->first();
        if ($server === null) {
            $this->error('No existe un servidor con ese UUID.');

            return self::FAILURE;
        }

        return match ((string) $this->argument('action')) {
            'configure' => $this->saveConnection($connections, $server),
            'install-agent' => $this->report($installer->handle($server, null)),
            'test' => $this->test($tester, $server),
            default => $this->invalidAction(),
        };
    }

    private function saveConnection(RemoteServerConnections $connections, Server $server): int
    {
        $type = ConnectionType::tryFrom((string) $this->option('type'));
        if ($type === null) {
            $this->error('Indique --type=aws-ssm o --type=ssh.');

            return self::FAILURE;
        }
        $input = $type === ConnectionType::AWS_SSM
            ? ['instance_id' => $this->option('instance-id'), 'region' => $this->option('region')]
            : ['host' => $this->option('host'), 'port' => $this->option('port'), 'user' => $this->option('user'),
                'host_key' => $this->option('host-key'), 'secret_version' => $this->option('secret-version'),
                'use_sudo' => (bool) $this->option('sudo')];
        $reference = $this->option('secret-reference');
        try {
            $connection = $connections->configure($server, $type, $input, is_string($reference) && $reference !== '' ? $reference : null, null);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info('Conexión '.$type->label().' guardada con estado '.$connection->status->value.'.');

        return self::SUCCESS;
    }

    private function test(TestRemoteConnection $tester, Server $server): int
    {
        $result = $tester->handle($server, null);
        $this->table(['Comprobación', 'Resultado', 'Detalle'], array_map(
            fn (array $check): array => [$check['key'], $check['result'], $check['message']], $result['checks'],
        ));
        $this->line('Estado de la conexión: '.$result['status']);

        return $result['status'] === 'VALID' ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{installed: bool, agent_version: string, agent_sha256: string, error_code: string|null} $result */
    private function report(array $result): int
    {
        if ($result['installed']) {
            $this->info('Agente '.$result['agent_version'].' instalado y verificado ('.$result['agent_sha256'].').');

            return self::SUCCESS;
        }
        $this->error('No se instaló el agente. Código: '.$result['error_code']);

        return self::FAILURE;
    }

    private function invalidAction(): int
    {
        $this->error('Acción no válida. Use configure, install-agent o test.');

        return self::FAILURE;
    }
}
