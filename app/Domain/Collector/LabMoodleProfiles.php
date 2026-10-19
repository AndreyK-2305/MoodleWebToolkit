<?php

namespace App\Domain\Collector;

use App\Domain\Artifacts\SensitiveValueRedactor;
use RuntimeException;

/**
 * @phpstan-type LabMoodleProfile array{
 *   name: string, root: string, code: string, data: string, base_url: string,
 *   db_host: string, db_port: int, db_name: string, db_user: string, db_prefix: string,
 *   credential_reference: string, credential_version: string, source_id: string, moodle_series: string
 * }
 */
final class LabMoodleProfiles
{
    /** @return LabMoodleProfile */
    public function get(string $key): array
    {
        $profiles = config('collector.profiles', []);
        $profile = is_array($profiles) ? ($profiles[$key] ?? null) : null;
        $keys = ['name', 'root', 'code', 'data', 'base_url', 'db_host', 'db_port', 'db_name', 'db_user', 'db_prefix', 'credential_reference', 'credential_version', 'source_id', 'moodle_series'];
        if (! is_array($profile) || array_diff(array_keys($profile), $keys) !== [] || array_diff($keys, array_keys($profile)) !== []) {
            throw new RuntimeException('El perfil de Moodle LAB no está autorizado o está incompleto.');
        }
        foreach ($profile as $name => $value) {
            if ($name === 'db_port') {
                if (! is_int($value) || $value < 1 || $value > 65535) {
                    throw new RuntimeException('El puerto del perfil LAB no es válido.');
                }
            } elseif (! is_string($value) || $value === '' || strlen($value) > 512 || str_contains($value, "\0")
                || app(SensitiveValueRedactor::class)->redactString($value) !== $value
            ) {
                throw new RuntimeException('El perfil LAB contiene un valor inválido o sensible.');
            }
        }
        foreach (['db_host' => '/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,119}$/D', 'db_name' => '/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D',
            'db_user' => '/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D', 'db_prefix' => '/^[a-z][a-z0-9_]{0,19}$/D',
            'source_id' => '/^[a-z][a-z0-9_-]{0,62}$/D', 'credential_reference' => '/^[a-z][a-z0-9-]{2,63}$/D',
            'credential_version' => '/^[1-9][0-9]{0,8}$/D', 'moodle_series' => '/^4\.5$/D',
        ] as $name => $pattern) {
            if (preg_match($pattern, (string) $profile[$name]) !== 1) {
                throw new RuntimeException('La identidad o versión del perfil LAB no es válida.');
            }
        }
        $url = parse_url((string) $profile['base_url']);
        if (! is_array($url) || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || ! str_ends_with($url['host'] ?? '', '.test') || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
        ) {
            throw new RuntimeException('El origen LAB requiere una URL sintética sin credenciales.');
        }
        $root = rtrim((string) $profile['root'], '/');
        if (! str_starts_with($root, '/') || preg_match('#(^|/)\.\.?(/|$)#', $root) === 1) {
            throw new RuntimeException('El ámbito del perfil LAB no es seguro.');
        }
        foreach (['root', 'code', 'data'] as $name) {
            $path = (string) $profile[$name];
            if (($path !== $root && ! str_starts_with($path, $root.'/')) || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
                throw new RuntimeException('Una ruta del perfil LAB sale del ámbito autorizado.');
            }
            $cursor = '';
            foreach (explode('/', ltrim($path, '/')) as $segment) {
                $cursor .= '/'.$segment;
                if (is_link($cursor)) {
                    throw new RuntimeException('Una ruta del perfil LAB contiene un enlace simbólico.');
                }
            }
        }

        return [
            'name' => (string) $profile['name'], 'root' => (string) $profile['root'],
            'code' => (string) $profile['code'], 'data' => (string) $profile['data'], 'base_url' => (string) $profile['base_url'],
            'db_host' => (string) $profile['db_host'], 'db_port' => (int) $profile['db_port'], 'db_name' => (string) $profile['db_name'],
            'db_user' => (string) $profile['db_user'], 'db_prefix' => (string) $profile['db_prefix'],
            'credential_reference' => (string) $profile['credential_reference'], 'credential_version' => (string) $profile['credential_version'],
            'source_id' => (string) $profile['source_id'], 'moodle_series' => (string) $profile['moodle_series'],
        ];
    }

    public function fingerprint(string $key): string
    {
        $profile = $this->get($key);
        ksort($profile, SORT_STRING);

        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<array{id: string, name: string}> */
    public function choices(): array
    {
        $choices = [];
        foreach (array_keys((array) config('collector.profiles', [])) as $key) {
            try {
                $profile = $this->get((string) $key);
                $choices[] = ['id' => (string) $key, 'name' => $profile['name']];
            } catch (RuntimeException) {
                // Misconfigured profiles cannot be selected by the UI.
            }
        }

        return $choices;
    }
}
