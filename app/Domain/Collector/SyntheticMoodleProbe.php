<?php

namespace App\Domain\Collector;

use App\Domain\Collector\Contracts\SecretProvider;
use PDO;
use RuntimeException;

/** @phpstan-import-type LabMoodleProfile from LabMoodleProfiles */
class SyntheticMoodleProbe
{
    public function __construct(private readonly SecretProvider $secrets) {}

    /**
     * @param  LabMoodleProfile  $profile
     * @return array{courses: int, users: int, oauth: int, database_version: string}
     */
    public function inspect(array $profile): array
    {
        $markerPath = $profile['root'].'/.moodle-toolkit-synthetic-lab.json';
        if (! is_file($markerPath) || is_link($markerPath) || filesize($markerPath) > 4096) {
            throw new RuntimeException('El origen no acredita ser el Moodle sintético autorizado.');
        }
        $marker = json_decode((string) file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($marker) || ($marker['schema_version'] ?? null) !== 'synthetic-moodle.v1'
            || ! is_string($marker['fixture_id'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $marker['fixture_id']) !== 1) {
            throw new RuntimeException('La identidad del laboratorio no es válida.');
        }

        return $this->secrets->consume($profile['credential_reference'], $profile['credential_version'], function (string $value) use ($profile, $marker): array {
            $pdo = new PDO('pgsql:host='.$profile['db_host'].';port='.$profile['db_port'].';dbname='.$profile['db_name'].';connect_timeout=3', $profile['db_user'], $value, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $prefix = $profile['db_prefix'];
            $query = $pdo->prepare('SELECT value FROM '.$prefix.'config WHERE name = :name');
            $query->execute(['name' => 'local_toolkit_fixture_id']);
            if ($query->fetchColumn() !== $marker['fixture_id']) {
                throw new RuntimeException('La base de datos no corresponde al laboratorio autorizado.');
            }
            $query->execute(['name' => 'version']);
            $version = $query->fetchColumn();
            if (! str_starts_with($version, '20241007')) {
                throw new RuntimeException('La base de datos no corresponde a Moodle 4.5.');
            }
            $courses = $this->count($pdo, 'SELECT COUNT(*) FROM '.$prefix.'course WHERE id > 1');
            $users = $this->count($pdo, 'SELECT COUNT(*) FROM '.$prefix.'user WHERE deleted = 0');
            $oauth = $this->count($pdo, "SELECT COUNT(*) FROM {$prefix}oauth2_issuer WHERE baseurl = 'http://oauth-lab.test'");
            if ($courses < 1 || $courses > 3 || $users > 15 || $oauth < 1) {
                throw new RuntimeException('El laboratorio requiere 1–3 cursos, pocos usuarios y OAuth sintético.');
            }

            return ['courses' => $courses, 'users' => $users, 'oauth' => $oauth, 'database_version' => $version];
        });
    }

    private function count(PDO $pdo, string $sql): int
    {
        $query = $pdo->query($sql);
        if ($query === false) {
            throw new RuntimeException('No se pudo observar el laboratorio.');
        }

        return (int) $query->fetchColumn();
    }
}
