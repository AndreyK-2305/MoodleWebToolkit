import { describe, expect, it } from 'vitest';
import {
    TOP,
    addCategory,
    childrenOf,
    courseCount,
    initialMap,
    memberKey,
    merge,
    move,
    normalizeName,
    pendingSuggestions,
    pilotNode,
    remove,
    rename,
    serializeMap,
    split,
    validateMap,
} from './category-map';
import type {
    CategoryMapDetails,
    MapState,
    Operation,
    SourceCategory,
} from './category-map';

const PRE = 'pregrado-2026-10-24-directo';
const POS = 'posgrado-2026-10-24-directo';

const category = (
    source: string,
    id: number,
    parent: number,
    name: string,
    courses: string[] = [],
): SourceCategory => ({
    source,
    id,
    parent_id: parent,
    name,
    depth: parent === 0 ? 1 : 2,
    course_count: courses.length,
    courses: courses.map((shortname, index) => ({
        id: id * 10 + index,
        shortname,
        fullname: shortname,
    })),
});

/** The synthetic laboratory: «Categorías esperadas» in LAB-ORIGENES-SINTETICOS.md. */
const details: CategoryMapDetails = {
    proposal_sha256: 'a'.repeat(64),
    map_sources: [
        { source: POS, alias: 'POS', name: 'Posgrado' },
        { source: PRE, alias: 'PRE', name: 'Pregrado' },
    ],
    existing: [
        { id: 1, parent_id: 0, name: 'Categoría 1', depth: 1, course_count: 0 },
    ],
    categories: [
        category(POS, 1, 0, 'Category 1'),
        category(POS, 7, 0, 'Ingeniería', ['ING-101', 'ING-501']),
        category(POS, 8, 0, 'Ciencias basicas'),
        category(POS, 9, 8, 'Estadística', ['EST-501', 'EST-502']),
        category(POS, 10, 0, 'Educación', ['EDU-501', 'EDU-502']),
        category(PRE, 1, 0, 'Category 1'),
        category(PRE, 2, 0, 'Ingeniería', ['ING-101', 'ING-201']),
        category(PRE, 3, 0, 'Ciencias Básicas'),
        category(PRE, 4, 3, 'Matemáticas', ['MAT-101', 'MAT-102']),
        category(PRE, 5, 0, 'Humanidades', ['HUM-101', 'HUM-102']),
    ],
    suggestions: [
        {
            kind: 'same',
            score: 1,
            a: { source: POS, id: 1 },
            b: { source: PRE, id: 1 },
        },
        {
            kind: 'same',
            score: 1,
            a: { source: POS, id: 7 },
            b: { source: PRE, id: 2 },
        },
        {
            kind: 'same',
            score: 1,
            a: { source: POS, id: 8 },
            b: { source: PRE, id: 3 },
        },
    ],
    pilot: { source: POS, source_category_id: 10, shortname: 'EDU-501' },
    map_issues: [],
    map_complete: true,
    previous_map: null,
};

const apply = (operation: Operation): MapState => {
    if ('error' in operation) {
        throw new Error(operation.error);
    }

    return operation.state;
};

describe('normalizeName', () => {
    it('ignores case, accents, punctuation and spaces', () => {
        expect(normalizeName('  Ciencias   Básicas ')).toBe(
            normalizeName('ciencias basicas'),
        );
        expect(normalizeName('Ingeniería (sede)')).toBe('ingenieria sede');
    });
});

describe('initialMap', () => {
    it('starts from the unified tree with the existing categories', () => {
        const state = initialMap(details);
        expect(childrenOf(state, TOP).map((node) => node.name)).toEqual([
            'Categoría 1',
            'Category 1',
            'Category 1',
            'Ciencias basicas',
            'Ciencias Básicas',
            'Educación',
            'Humanidades',
            'Ingeniería',
            'Ingeniería',
        ]);
        expect(state.assignments[memberKey(PRE, 4)]).toBe('PRE-4');
        expect(state.nodes.find((node) => node.key === 'PRE-4')?.parent).toBe(
            'PRE-3',
        );
    });

    it('flags the duplicates the proposal keeps as they are', () => {
        const codes = validateMap(details, initialMap(details)).map(
            (issue) => issue.code,
        );
        expect(
            codes.filter((code) => code === 'SIBLING_DUPLICATE'),
        ).toHaveLength(6);
    });

    it('resumes the previous answer and places what it lacks', () => {
        const state = initialMap({
            ...details,
            previous_map: {
                nodes: [
                    { key: 'E-1', existing_category_id: 1 },
                    { key: 'ING', name: 'Ingeniería', parent: TOP },
                ],
                assignments: [
                    { source: PRE, source_category_id: 2, node: 'ING' },
                    { source: POS, source_category_id: 7, node: 'ING' },
                    { source: PRE, source_category_id: 1, node: 'E-1' },
                ],
            },
        });
        expect(state.assignments[memberKey(POS, 7)]).toBe('ING');
        expect(state.assignments[memberKey(PRE, 5)]).toBe('PRE-5-N');
        expect(
            validateMap(details, state).filter(
                (issue) => issue.code === 'UNMAPPED',
            ),
        ).toEqual([]);
    });
});

describe('the laboratory map', () => {
    const drawn = () => {
        let state = initialMap(details);
        for (const suggestion of pendingSuggestions(details, state)) {
            state = apply(merge(state, suggestion.from, suggestion.into));
        }
        // The two «Category 1» go into the existing «Categoría 1».
        state = apply(merge(state, findBy(state, 'Category 1'), 'E-1'));
        // Merging keeps the name with accents, the one the second source uses.
        expect(
            state.nodes.some((node) => node.name === 'Ciencias basicas'),
        ).toBe(false);

        return apply(
            rename(state, findBy(state, 'Humanidades'), 'Humanidades y Artes'),
        );
    };
    const findBy = (state: MapState, name: string) =>
        state.nodes.find(
            (node) => node.name === name && node.existing === null,
        )!.key;

    it('merges what the suggestions propose and validates', () => {
        const state = drawn();
        expect(validateMap(details, state)).toEqual([]);
        expect(pendingSuggestions(details, state)).toEqual([]);
        const sciences = findBy(state, 'Ciencias Básicas');
        expect(childrenOf(state, sciences).map((node) => node.name)).toEqual([
            'Estadística',
            'Matemáticas',
        ]);
        expect(courseCount(details, state, findBy(state, 'Ingeniería'))).toBe(
            4,
        );
        expect(pilotNode(details, state)).toBe('POS-10');
    });

    it('serializes the used categories and every assignment', () => {
        const map = serializeMap(drawn());
        expect(map.assignments).toHaveLength(10);
        expect(map.nodes).toContainEqual({
            key: 'E-1',
            existing_category_id: 1,
        });
        expect(map.nodes.filter((node) => 'name' in node)).toHaveLength(6);
        expect(new Set(map.assignments.map((item) => item.node)).size).toBe(7);
    });

    it('splits a merged category back and removes an empty one', () => {
        let state = drawn();
        const engineering = findBy(state, 'Ingeniería');
        state = apply(
            split(
                state,
                engineering,
                memberKey(PRE, 2),
                'Ingeniería de pregrado',
            ),
        );
        expect(state.assignments[memberKey(PRE, 2)]).not.toBe(engineering);
        expect(validateMap(details, state)).toEqual([]);
        state = apply(addCategory(state, TOP, 'Vacía'));
        const empty = findBy(state, 'Vacía');
        expect(
            serializeMap(state).nodes.some((node) => node.key === empty),
        ).toBe(false);
        state = apply(remove(state, empty));
        expect(state.nodes.some((node) => node.key === empty)).toBe(false);
    });
});

describe('operations refuse what the Consolidator would reject', () => {
    const state = initialMap(details);

    it('moves without cycles and keeps existing categories fixed', () => {
        expect(move(state, 'PRE-3', 'PRE-4')).toHaveProperty('error');
        expect(move(state, 'E-1', 'PRE-3')).toHaveProperty('error');
        expect(merge(state, 'E-1', 'PRE-3')).toHaveProperty('error');
        expect(merge(state, 'PRE-3', 'PRE-4')).toHaveProperty('error');
        const moved = apply(move(state, 'PRE-5', 'E-1'));
        expect(moved.nodes.find((node) => node.key === 'PRE-5')?.parent).toBe(
            'E-1',
        );
    });

    it('reports empty names and homonyms of existing categories', () => {
        const unnamed = apply(rename(state, 'PRE-5', '   '));
        expect(
            validateMap(details, unnamed).map((issue) => issue.code),
        ).toContain('NAME_EMPTY');
        const homonym = apply(rename(state, 'PRE-5', 'categoría 1'));
        expect(
            validateMap(details, homonym).map((issue) => issue.code),
        ).toContain('EXISTING_HOMONYM');
    });

    it('reports a source category without destination', () => {
        const lost: MapState = {
            ...state,
            assignments: { ...state.assignments, [memberKey(PRE, 5)]: 'NADA' },
        };
        expect(validateMap(details, lost).map((issue) => issue.code)).toContain(
            'UNMAPPED',
        );
    });
});
