<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use App\Domain\Workspaces\ExecutionWorkspaceManager;
use App\Models\Execution;
use RuntimeException;

/** @phpstan-import-type LabMoodleProfile from LabMoodleProfiles */
final class EphemeralMoodleConfiguration
{
    public function __construct(
        private readonly SecretProvider $secrets,
        private readonly ExecutionWorkspaceManager $workspaces,
    ) {}

    /**
     * @template T
     *
     * @param  LabMoodleProfile  $profile
     * @param  callable(string): T  $consumer
     * @return T
     */
    public function consume(Execution $execution, array $profile, callable $consumer): mixed
    {
        $path = $this->workspaces->resolve($execution, 'input', 'moodle-runtime.php');
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Ya existe material efímero; se requiere reconciliar antes de reintentar.');
        }

        return $this->secrets->consume($profile['credential_reference'], $profile['credential_version'], function (string $value) use ($profile, $path, $consumer): mixed {
            $previous = umask(0077);
            $handle = @fopen($path, 'xb');
            umask($previous);
            if ($handle === false) {
                throw new RuntimeException('No se pudo materializar la configuración privada de Moodle.');
            }
            try {
                $configuration = [
                    'dbtype' => 'pgsql', 'dblibrary' => 'native', 'dbhost' => $profile['db_host'],
                    'dbname' => $profile['db_name'], 'dbuser' => $profile['db_user'], 'dbpass' => $value,
                    'prefix' => $profile['db_prefix'], 'dboptions' => ['dbport' => $profile['db_port'], 'dbpersist' => false],
                    'wwwroot' => $profile['base_url'], 'dataroot' => $profile['data'], 'dirroot' => $profile['code'],
                    'admin' => 'admin', 'directorypermissions' => 0700,
                ];
                $php = "<?php\nunset(\$CFG);\n\$CFG = (object) ".var_export($configuration, true).";\nrequire_once(\$CFG->dirroot.'/lib/setup.php');\n";
                if (! chmod($path, 0600) || fwrite($handle, $php) !== strlen($php) || ! fflush($handle)) {
                    throw new RuntimeException('No se pudo fijar la configuración efímera privada.');
                }
                fclose($handle);
                $handle = null;

                return $consumer($path);
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                // Abrupt SIGKILL requires conservative reconciliation before cleanup;
                // this finally covers every normal return, exception and handled signal.
                $this->remove($path);
                unset($php, $configuration, $value);
            }
        });
    }

    private function remove(string $path): void
    {
        if (is_link($path) || (is_file($path) && ! unlink($path))) {
            throw new RuntimeException('No se pudo retirar el material efímero; el workspace permanece bloqueado.');
        }
    }
}
