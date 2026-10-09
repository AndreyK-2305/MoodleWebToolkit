<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use RuntimeException;

/** @phpstan-import-type LabMoodleProfile from LabMoodleProfiles */
final class MoodleConfigurationMaterializer
{
    public function __construct(private readonly SecretProvider $secrets, private readonly string $workspaceRoot) {}

    /**
     * @template T
     *
     * @param  LabMoodleProfile  $profile
     * @param  callable(string): T  $consumer
     * @return T
     */
    public function consume(string $path, array $profile, callable $consumer): mixed
    {
        $root = realpath($this->workspaceRoot);
        if ($root === false || $root !== $this->workspaceRoot || ! str_starts_with($path, $root.'/')
            || preg_match('#^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}/input/moodle-runtime\.php$#D', substr($path, strlen($root) + 1)) !== 1) {
            throw new RuntimeException('La configuración efímera debe pertenecer al input de un workspace autorizado.');
        }
        $cursor = $root;
        foreach (explode('/', substr($path, strlen($root) + 1)) as $segment) {
            $cursor .= '/'.$segment;
            if (is_link($cursor)) {
                throw new RuntimeException('La configuración efímera no acepta rutas enlazadas.');
            }
        }
        if (file_exists($path)) {
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
