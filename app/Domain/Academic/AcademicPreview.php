<?php

namespace App\Domain\Academic;

use App\Enums\ProjectType;
use App\Models\AcademicSnapshot;
use App\Models\Execution;
use LogicException;

class AcademicPreview
{
    public const SCHEMA_VERSION = 1;

    public function ensureSnapshot(Execution $execution): AcademicSnapshot
    {
        $execution->loadMissing('project');
        $nodes = $this->baseNodes($execution->project->type);
        $this->assertUniqueIdentifiers($nodes);

        return $execution->academicSnapshot()->firstOrCreate([], [
            'project_type' => $execution->project->type,
            'schema_version' => self::SCHEMA_VERSION,
            'fingerprint' => $this->fingerprint($nodes, 0),
            'tree' => $nodes,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function state(Execution $execution): array
    {
        $snapshot = $this->ensureSnapshot($execution);
        /** @var list<array<string, mixed>> $nodes */
        $nodes = $snapshot->tree;

        foreach ($execution->academicProposals()->orderBy('version')->get() as $proposal) {
            $nodes = $this->apply($nodes, $proposal->operation, $proposal->node_id, $proposal->new_value);
        }

        $this->assertUniqueIdentifiers($nodes);

        return $nodes;
    }

    /** @param list<array<string, mixed>> $nodes */
    public function fingerprint(array $nodes, int $version): string
    {
        usort($nodes, static fn (array $left, array $right): int => strcmp((string) $left['id'], (string) $right['id']));

        return hash('sha256', json_encode($this->canonicalize([
            'schema_version' => self::SCHEMA_VERSION,
            'proposal_version' => $version,
            'nodes' => $nodes,
        ]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>  $value
     * @return list<array<string, mixed>>
     */
    public function apply(array $nodes, string $operation, string $nodeId, array $value): array
    {
        foreach ($nodes as &$node) {
            if ($node['id'] !== $nodeId) {
                continue;
            }

            if (in_array($operation, ['RENAME_CATEGORY', 'CHANGE_VISIBLE_NAME'], true)) {
                $node['name'] = $value['name'];
            } else {
                $node['parent_id'] = $value['parent_id'];
            }

            break;
        }
        unset($node);

        return $nodes;
    }

    /** @return list<array<string, mixed>> */
    public function hierarchicalState(Execution $execution): array
    {
        $current = $this->state($execution);
        $snapshot = $this->ensureSnapshot($execution);
        /** @var list<array<string, mixed>> $base */
        $base = $snapshot->tree;
        $baseById = collect($base)->keyBy('id');
        $currentById = collect($current)->keyBy('id');

        $decorate = function (array $node) use ($baseById, $currentById): array {
            $original = $baseById->get($node['id'], $node);
            $currentLocation = $this->location((string) $node['id'], $baseById->all());
            $proposedLocation = $this->location((string) $node['id'], $currentById->all());

            return [
                ...$node,
                'current_name' => $original['name'],
                'current_parent_id' => $original['parent_id'],
                'current_location' => $currentLocation,
                'proposed_location' => $currentLocation === $proposedLocation ? null : $proposedLocation,
                'name_changed' => $original['name'] !== $node['name'],
            ];
        };

        $buildCategories = function (?string $parentId) use (&$buildCategories, $current, $decorate): array {
            return collect($current)
                ->filter(fn (array $node): bool => $node['type'] === 'category' && $node['parent_id'] === $parentId)
                ->sortBy('id')
                ->map(function (array $category) use (&$buildCategories, $current, $decorate): array {
                    $decorated = $decorate($category);
                    $decorated['categories'] = $buildCategories((string) $category['id']);
                    $decorated['courses'] = collect($current)
                        ->filter(fn (array $node): bool => $node['type'] === 'course' && $node['parent_id'] === $category['id'])
                        ->sortBy('id')
                        ->map($decorate)
                        ->values()
                        ->all();

                    return $decorated;
                })
                ->values()
                ->all();
        };

        return array_values($buildCategories(null));
    }

    /** @return list<array<string, mixed>> */
    private function baseNodes(ProjectType $type): array
    {
        return match ($type) {
            ProjectType::COLLECT => [
                $this->category('cat:collection-root', null, 'Copia estructurada'),
                $this->category('cat:collection-academic', 'cat:collection-root', 'Oferta académica recolectada'),
                $this->category('cat:collection-archive', 'cat:collection-root', 'Cursos archivados'),
                $this->course('course:collection-101', 'cat:collection-academic', 'REC-101', 'Fundamentos recolectados'),
                $this->course('course:collection-archive-1', 'cat:collection-archive', 'REC-ARC-1', 'Curso histórico recolectado'),
            ],
            ProjectType::CONSOLIDATE => [
                $this->category('cat:consolidated-root', null, 'Campus consolidado'),
                $this->category('cat:source-a', 'cat:consolidated-root', 'Origen Andino'),
                $this->category('cat:source-a-engineering', 'cat:source-a', 'Ingeniería'),
                $this->category('cat:source-b', 'cat:consolidated-root', 'Origen Caribe'),
                $this->course('course:source-a-101', 'cat:source-a-engineering', 'AND-101', 'Introducción a sistemas'),
                $this->course('course:source-b-205', 'cat:source-b', 'CAR-205', 'Gestión de proyectos'),
            ],
            ProjectType::INTEGRATE => [
                $this->category('cat:destination-root', null, 'Campus consolidado existente'),
                $this->category('cat:destination-existing', 'cat:destination-root', 'Oferta vigente'),
                $this->category('cat:incoming', 'cat:destination-root', 'Nueva incorporación'),
                $this->course('course:destination-100', 'cat:destination-existing', 'VIG-100', 'Curso ya consolidado'),
                $this->course('course:incoming-310', 'cat:incoming', 'INC-310', 'Curso incremental propuesto'),
            ],
        };
    }

    /** @return array<string, mixed> */
    private function category(string $id, ?string $parentId, string $name): array
    {
        return ['id' => $id, 'type' => 'category', 'parent_id' => $parentId, 'short_name' => null, 'name' => $name];
    }

    /** @return array<string, mixed> */
    private function course(string $id, string $parentId, string $shortName, string $name): array
    {
        return ['id' => $id, 'type' => 'course', 'parent_id' => $parentId, 'short_name' => $shortName, 'name' => $name];
    }

    /** @param list<array<string, mixed>> $nodes */
    private function assertUniqueIdentifiers(array $nodes): void
    {
        $ids = array_column($nodes, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new LogicException('La previsualización académica contiene identificadores duplicados.');
        }
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function location(string $nodeId, array $nodes): string
    {
        $parts = [];
        $cursor = $nodes[$nodeId] ?? null;
        $seen = [];

        while (is_array($cursor)) {
            if (isset($seen[$cursor['id']])) {
                throw new LogicException('La estructura académica contiene un ciclo.');
            }

            $seen[$cursor['id']] = true;
            array_unshift($parts, $cursor['name']);
            $cursor = $cursor['parent_id'] === null ? null : ($nodes[$cursor['parent_id']] ?? null);
        }

        return implode(' / ', $parts);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
