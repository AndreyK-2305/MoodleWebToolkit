<?php

namespace App\Console\Commands;

use App\Domain\Collector\CollectorPhpRuntime;
use App\Domain\Collector\LabMoodleProfiles;
use App\Domain\Collector\SyntheticMoodleProbe;
use App\Domain\Tools\ToolDistributionVerifier;
use App\Models\AuditLog;
use App\Models\ToolDistribution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class EnableCollectorLaboratory extends Command
{
    protected $signature = 'collector:enable-laboratory {profile=synthetic-moodle}';

    protected $description = 'Autoriza exclusivamente Recolector 7.4.2 para un perfil Moodle sintético verificado';

    public function handle(LabMoodleProfiles $profiles, SyntheticMoodleProbe $probe, CollectorPhpRuntime $runtime, ToolDistributionVerifier $verifier): int
    {
        if (! config('toolkit.features.recolector_742.enabled') || ! config('toolkit.features.local_runner.enabled')) {
            $this->error('La autorización LAB requiere ambas feature flags explícitas.');

            return self::FAILURE;
        }
        try {
            $profileId = (string) $this->argument('profile');
            $profile = $profiles->get($profileId);
            $probe->inspect($profile);
            if (! $runtime->available()) {
                throw new \RuntimeException;
            }
            $distribution = ToolDistribution::query()->where('key', 'moodle-recolector-7.4.2-linux-tree')->sole();
            $verifier->verify($distribution);
            if ($distribution->toolVersion->version !== '7.4.2-linux') {
                throw new \RuntimeException;
            }
            DB::transaction(function () use ($distribution, $profileId): void {
                $distribution->toolVersion->update(['enabled' => true]);
                AuditLog::query()->create(['action' => 'COLLECTOR_LABORATORY_ENABLED', 'payload' => [
                    'profile_id' => $profileId, 'distribution_sha256' => $distribution->distribution_sha256, 'mode' => 'LABORATORY']]);
            });
        } catch (Throwable) {
            $this->error('No se pudo acreditar el perfil sintético, runtime o distribución del laboratorio.');

            return self::FAILURE;
        }
        $this->info('Recolector 7.4.2 autorizado exclusivamente en LABORATORY.');

        return self::SUCCESS;
    }
}
