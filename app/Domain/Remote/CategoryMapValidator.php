<?php

namespace App\Domain\Remote;

use Normalizer;

/**
 * The destination category map the administrator drew, checked in the platform
 * with the same rules as the Consolidator's own validator (v8-category-map.php),
 * so a broken map is refused before anything travels to the destination. The
 * Consolidator validates it again when it resumes.
 *
 * A node is a new category (name and parent: '@top' or another node's key) or
 * a category that already exists on the destination (existing_category_id).
 * Every source category of the proposal is assigned to exactly one node.
 */
class CategoryMapValidator
{
    public const TOP = '@top';

    private const KEY = '/^[A-Za-z0-9_-]{1,64}$/D';

    /**
     * @param  array<string, mixed>  $details  the CATEGORY_MAP intervention: categories and existing
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array{code: string, node: ?string, message: string}>
     */
    public function validate(array $details, array $nodes, array $assignments): array
    {
        $issues = [];
        $issue = function (string $code, ?string $node, string $message) use (&$issues): void {
            if (count($issues) < 200) {
                $issues[] = ['code' => $code, 'node' => $node, 'message' => $message];
            }
        };
        $existing = [];
        foreach (is_array($details['existing'] ?? null) ? $details['existing'] : [] as $category) {
            if (is_array($category) && is_int($category['id'] ?? null)) {
                $existing[$category['id']] = $category;
            }
        }

        $map = [];
        $existingUsed = [];
        foreach ($nodes as $node) {
            $key = is_string($node['key'] ?? null) ? $node['key'] : '';
            if (preg_match(self::KEY, $key) !== 1) {
                $issue('NODE_KEY_INVALID', null, 'Una categoría tiene una clave inválida.');

                continue;
            }
            if (isset($map[$key])) {
                $issue('NODE_DUPLICATE', $key, 'La clave de la categoría se repite.');

                continue;
            }
            $existingId = $node['existing_category_id'] ?? null;
            if ($existingId !== null) {
                if (! is_int($existingId) || ! isset($existing[$existingId])) {
                    $issue('EXISTING_MISSING', $key, 'La categoría existente ya no está en el destino.');

                    continue;
                }
                if (isset($existingUsed[$existingId])) {
                    $issue('EXISTING_DUPLICATE', $key, 'La misma categoría existente aparece dos veces.');

                    continue;
                }
                $existingUsed[$existingId] = $key;
                $map[$key] = ['name' => (string) $existing[$existingId]['name'], 'parent' => '', 'existing' => $existingId];

                continue;
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', is_string($node['name'] ?? null) ? $node['name'] : ''));
            if ($name === '') {
                $issue('NAME_EMPTY', $key, 'La categoría no tiene nombre.');
            } elseif (mb_strlen($name) > 255) {
                $issue('NAME_TOO_LONG', $key, 'El nombre supera los 255 caracteres.');
            }
            $map[$key] = ['name' => $name, 'parent' => is_string($node['parent'] ?? null) ? $node['parent'] : '', 'existing' => null];
        }
        if ($map === []) {
            $issue('NODES_EMPTY', null, 'El mapa no contiene categorías.');
        }
        foreach ($map as $key => $node) {
            if ($node['existing'] === null && $node['parent'] !== self::TOP && ! isset($map[$node['parent']])) {
                $issue('PARENT_MISSING', $key, 'La categoría padre no existe en el mapa.');
            }
        }
        foreach (array_keys($map) as $key) {
            $seen = [];
            $current = (string) $key;
            while (isset($map[$current]) && $map[$current]['existing'] === null) {
                if (isset($seen[$current])) {
                    $issue('CYCLE', (string) $key, 'La categoría queda dentro de sí misma.');

                    break;
                }
                $seen[$current] = true;
                $current = $map[$current]['parent'];
            }
        }

        $expected = [];
        foreach (is_array($details['categories'] ?? null) ? $details['categories'] : [] as $category) {
            if (is_array($category) && is_string($category['source'] ?? null) && is_int($category['id'] ?? null)) {
                $expected[$category['source'].'|'.$category['id']] = $category;
            }
        }
        $assigned = [];
        foreach ($assignments as $assignment) {
            $member = (is_string($assignment['source'] ?? null) ? $assignment['source'] : '').'|'
                .(is_int($assignment['source_category_id'] ?? null) ? $assignment['source_category_id'] : 0);
            $node = is_string($assignment['node'] ?? null) ? $assignment['node'] : '';
            if (! isset($expected[$member])) {
                $issue('ASSIGNMENT_UNKNOWN', null, 'Una asignación nombra una categoría de origen que no existe.');

                continue;
            }
            if (isset($assigned[$member])) {
                $issue('ASSIGNMENT_DUPLICATE', $node, 'Una categoría de origen está asignada dos veces.');

                continue;
            }
            if (! isset($map[$node])) {
                $issue('ASSIGNMENT_NODE_MISSING', null, 'Una asignación apunta a una categoría inexistente.');

                continue;
            }
            $assigned[$member] = $node;
        }
        foreach ($expected as $member => $category) {
            if (! isset($assigned[$member])) {
                $issue('UNMAPPED', null, 'La categoría «'.($category['name'] ?? '').'» de '.explode('|', $member)[0].' no tiene destino.');
            }
        }

        $siblings = [];
        foreach ($map as $key => $node) {
            if ($node['existing'] === null && $node['name'] !== '') {
                $siblings[$node['parent'].'|'.self::normalize($node['name'])][] = (string) $key;
            }
        }
        foreach ($siblings as $keys) {
            if (count($keys) > 1) {
                foreach ($keys as $key) {
                    $issue('SIBLING_DUPLICATE', $key, 'Hay otra categoría con el mismo nombre en el mismo lugar; únalas o cambie el nombre.');
                }
            }
        }
        // A new category beside an existing one with the same name, at the top or inside an existing category.
        foreach ($map as $key => $node) {
            if ($node['existing'] !== null || $node['name'] === '') {
                continue;
            }
            $parentId = $node['parent'] === self::TOP ? 0 : ($map[$node['parent']]['existing'] ?? null);
            if ($parentId === null) {
                continue;
            }
            foreach ($existing as $category) {
                if ((int) ($category['parent_id'] ?? -1) === $parentId
                    && mb_strtolower(trim((string) ($category['name'] ?? ''))) === mb_strtolower($node['name'])) {
                    $issue('EXISTING_HOMONYM', (string) $key, 'El destino ya tiene una categoría «'.$category['name'].'» en ese lugar; únala con ella o cambie el nombre.');
                }
            }
        }

        return $issues;
    }

    /** Comparable name: lower case, without accents or punctuation, spaces collapsed. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $value = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
            }
        } else {
            $value = strtr($value, ['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
                'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
                'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n', 'ç' => 'c']);
        }
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
