<?php

namespace App\Console\Commands;

use App\Domain\Artifacts\FinalizationGarbageCollector;
use Illuminate\Console\Command;

class CleanupFinalizationArtifacts extends Command
{
    protected $signature = 'artifacts:cleanup-finalization {--minimum-age= : Antigüedad mínima en segundos}';

    protected $description = 'Elimina staging expirado y artefactos finales no referenciados de finalizaciones abandonadas';

    public function handle(FinalizationGarbageCollector $collector): int
    {
        $option = $this->option('minimum-age');

        if ($option !== null && filter_var($option, FILTER_VALIDATE_INT) === false) {
            $this->error('La antigüedad mínima debe ser un entero no negativo.');

            return self::FAILURE;
        }

        $minimumAge = $option === null ? null : (int) $option;

        if ($minimumAge !== null && $minimumAge < 0) {
            $this->error('La antigüedad mínima debe ser un entero no negativo.');

            return self::FAILURE;
        }

        $result = $collector->collect($minimumAge);
        $this->info("Archivos eliminados: {$result['deleted_files']}");

        return self::SUCCESS;
    }
}
