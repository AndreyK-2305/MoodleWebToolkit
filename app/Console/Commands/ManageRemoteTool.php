<?php

namespace App\Console\Commands;

use App\Domain\Remote\CheckRemoteSource;
use App\Domain\Remote\RemoteToolDeployer;
use App\Models\Server;
use App\Models\ToolDistribution;
use Illuminate\Console\Command;

class ManageRemoteTool extends Command
{
    protected $signature = 'remote:tool
        {action : deploy, status o source-check}
        {server : UUID del servidor registrado en el proyecto}
        {--distribution=moodle-recolector-7.4.2-linux-tree : Clave de la distribución del catálogo}
        {--source= : Perfil de origen definido en el servidor (source-check)}';

    protected $description = 'Despliega una herramienta verificada en un servidor remoto o comprueba un perfil de origen.';

    public function handle(RemoteToolDeployer $deployer, CheckRemoteSource $sources): int
    {
        $server = Server::query()->where('uuid', (string) $this->argument('server'))->first();
        if ($server === null) {
            $this->error('No existe un servidor con ese UUID.');

            return self::FAILURE;
        }
        $action = (string) $this->argument('action');
        if ($action === 'source-check') {
            $result = $sources->handle($server, (string) $this->option('source'), null);
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['ready'] ? self::SUCCESS : self::FAILURE;
        }
        $distribution = ToolDistribution::query()->where('key', (string) $this->option('distribution'))->first();
        if ($distribution === null) {
            $this->error('La distribución no existe en el catálogo.');

            return self::FAILURE;
        }

        return match ($action) {
            'deploy' => $this->report($deployer->deploy($server, $distribution, null)),
            'status' => $this->report($deployer->status($server, $distribution)),
            default => $this->invalid(),
        };
    }

    /** @param array<string, mixed> $result */
    private function report(array $result): int
    {
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $ok = ($result['installed'] ?? null) === true || ($result['matches'] ?? null) === true;

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function invalid(): int
    {
        $this->error('Acción no válida. Use deploy, status o source-check.');

        return self::FAILURE;
    }
}
