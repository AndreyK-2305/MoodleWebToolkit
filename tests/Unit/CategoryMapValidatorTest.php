<?php

namespace Tests\Unit;

use App\Domain\Remote\CategoryMapValidator;
use App\Domain\Remote\OperatorText;
use PHPUnit\Framework\TestCase;

/** The synthetic laboratory's map, checked with the Consolidator's rules before it travels. */
class CategoryMapValidatorTest extends TestCase
{
    private const PRE = 'pregrado-2026-10-24-directo';

    private const POS = 'posgrado-2026-10-24-directo';

    /** @return array<string, mixed> */
    private function details(): array
    {
        $category = fn (string $source, int $id, int $parent, string $name): array => ['source' => $source, 'id' => $id, 'parent_id' => $parent,
            'name' => $name, 'depth' => $parent === 0 ? 1 : 2, 'course_count' => 2, 'courses' => []];

        return ['existing' => [['id' => 1, 'parent_id' => 0, 'name' => 'Categoría 1', 'depth' => 1, 'course_count' => 0]],
            'categories' => [$category(self::PRE, 2, 0, 'Ingeniería'), $category(self::PRE, 3, 0, 'Ciencias Básicas'),
                $category(self::PRE, 4, 3, 'Matemáticas'), $category(self::POS, 7, 0, 'Ingeniería'), $category(self::POS, 8, 0, 'Ciencias basicas'),
                $category(self::POS, 9, 8, 'Estadística'), $category(self::POS, 1, 0, 'Category 1')]];
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} */
    private function map(): array
    {
        return [[
            ['key' => 'E-1', 'existing_category_id' => 1],
            ['key' => 'ING', 'name' => 'Ingeniería', 'parent' => '@top'],
            ['key' => 'CB', 'name' => 'Ciencias Básicas', 'parent' => '@top'],
            ['key' => 'MAT', 'name' => 'Matemáticas', 'parent' => 'CB'],
            ['key' => 'EST', 'name' => 'Estadística', 'parent' => 'CB'],
        ], [
            ['source' => self::PRE, 'source_category_id' => 2, 'node' => 'ING'], ['source' => self::POS, 'source_category_id' => 7, 'node' => 'ING'],
            ['source' => self::PRE, 'source_category_id' => 3, 'node' => 'CB'], ['source' => self::POS, 'source_category_id' => 8, 'node' => 'CB'],
            ['source' => self::PRE, 'source_category_id' => 4, 'node' => 'MAT'], ['source' => self::POS, 'source_category_id' => 9, 'node' => 'EST'],
            ['source' => self::POS, 'source_category_id' => 1, 'node' => 'E-1'],
        ]];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $assignments
     * @return list<string>
     */
    private function codes(array $nodes, array $assignments): array
    {
        return array_column((new CategoryMapValidator)->validate($this->details(), $nodes, $assignments), 'code');
    }

    public function test_the_laboratory_map_is_valid(): void
    {
        [$nodes, $assignments] = $this->map();

        $this->assertSame([], $this->codes($nodes, $assignments));
        $this->assertSame(CategoryMapValidator::normalize('Ciencias Básicas'), CategoryMapValidator::normalize(' ciencias  basicas'));
    }

    public function test_every_rule_of_the_consolidator_is_checked(): void
    {
        [$nodes, $assignments] = $this->map();

        $cycle = $nodes;
        $cycle[2]['parent'] = 'MAT';
        $this->assertContains('CYCLE', $this->codes($cycle, $assignments));

        $empty = $nodes;
        $empty[1]['name'] = '  ';
        $this->assertContains('NAME_EMPTY', $this->codes($empty, $assignments));

        $this->assertContains('UNMAPPED', $this->codes($nodes, array_slice($assignments, 1)));
        $this->assertContains('ASSIGNMENT_DUPLICATE', $this->codes($nodes, [...$assignments, $assignments[0]]));
        $this->assertContains('ASSIGNMENT_UNKNOWN', $this->codes($nodes, [...$assignments, ['source' => self::PRE, 'source_category_id' => 99, 'node' => 'ING']]));

        $siblings = [...$nodes, ['key' => 'CB2', 'name' => 'ciencias basicas', 'parent' => '@top']];
        $split = $assignments;
        $split[3]['node'] = 'CB2';
        $this->assertContains('SIBLING_DUPLICATE', $this->codes($siblings, $split));

        $homonym = $nodes;
        $homonym[0] = ['key' => 'E-1', 'name' => 'Categoría 1', 'parent' => '@top'];
        $this->assertContains('EXISTING_HOMONYM', $this->codes($homonym, $assignments));

        $orphan = $nodes;
        $orphan[3]['parent'] = 'NADA';
        $this->assertContains('PARENT_MISSING', $this->codes($orphan, $assignments));

        $gone = $nodes;
        $gone[0]['existing_category_id'] = 5;
        $this->assertContains('EXISTING_MISSING', $this->codes($gone, $assignments));
    }

    public function test_the_consolidator_stop_reads_plainly(): void
    {
        $this->assertStringStartsWith('Revise en esta página cómo quedarán las categorías',
            (string) OperatorText::forWebUser('V8_CATEGORY_MAP_REQUIRED: CATEGORY_MAP_PROPOSAL_READY; WAITING_USER_ACTION. Revise exports/category-map/proposal.json.'));
    }
}
