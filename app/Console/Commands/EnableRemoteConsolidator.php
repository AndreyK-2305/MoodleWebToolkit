<?php

namespace App\Console\Commands;

use App\Domain\Remote\CheckRemoteDestination;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Models\AuditLog;
use App\Models\ToolDistribution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Enables the Consolidator V8 version for remote consolidations once its
 * distribution verifies again. The flags stay the operator's explicit choice.
 */
class EnableRemoteConsolidator extends Command
{
    protected $signature = 'consolidator:enable-remote';

    protected $description = 'Autoriza el Consolidador V8 verificado para consolidaciones remotas en laboratorio';

    public function handle(ToolDistributionVerifier $verifier): int
    {
        if (! config('toolkit.features.consolidador_800.enabled') || ! config('toolkit.features.remote_agent.enabled')) {
            $this->error('La autorización requiere las feature flags del Consolidador V8 y del agente remoto.');

            return self::FAILURE;
        }
        try {
            $distribution = ToolDistribution::query()->with('toolVersion')->where('key', CheckRemoteDestination::DISTRIBUTION_KEY)->sole();
            $verified = $verifier->verify($distribution);
            if ($distribution->toolVersion->version !== '8.0.0-linux-rc13' || ! hash_equals($distribution->distribution_sha256, $verified->treeSha256)) {
                throw new \RuntimeException;
            }
            DB::transaction(function () use ($distribution): void {
                $distribution->toolVersion->update(['enabled' => true]);
                AuditLog::query()->create(['action' => 'CONSOLIDATOR_REMOTE_ENABLED', 'payload' => [
                    'distribution_sha256' => $distribution->distribution_sha256, 'mode' => 'REMOTE_CONSOLIDATION']]);
            });
        } catch (Throwable) {
            $this->error('No se pudo verificar la distribución del Consolidador V8.');

            return self::FAILURE;
        }
        $this->info('Consolidador V8 autorizado para consolidaciones remotas en laboratorio.');

        return self::SUCCESS;
    }
}
