/**
 * The destination category map in plain operations: the tree the Consolidator
 * proposed, the changes the administrator makes on it (merge, rename, move,
 * new category, split) and the same validation the Consolidator applies, so
 * the map is approved only when it can be written.
 *
 * A node is a new category (name and parent: '@top' or another node's key) or
 * a category that already exists on the destination (existing). Every source
 * category is assigned to exactly one node; merged categories share a node.
 */

export const TOP = '@top';

export type MapSource = { source: string; alias: string; name: string };

export type MapCourse = { id: number; shortname: string; fullname: string };

export type SourceCategory = {
    source: string;
    id: number;
    parent_id: number;
    name: string;
    depth: number;
    course_count: number;
    courses: MapCourse[];
};

export type ExistingCategory = {
    id: number;
    parent_id: number;
    name: string;
    depth: number;
    course_count: number;
};

export type MapSuggestion = {
    kind: 'same' | 'similar';
    score: number;
    a: { source: string; id: number };
    b: { source: string; id: number };
};

export type MapIssue = { code: string; node: string | null; message: string };

export type CategoryMapDetails = {
    proposal_sha256?: string | null;
    map_sources?: MapSource[];
    existing?: ExistingCategory[];
    categories?: SourceCategory[];
    suggestions?: MapSuggestion[];
    pilot?: {
        source: string;
        source_category_id: number;
        shortname: string;
    } | null;
    map_issues?: MapIssue[];
    map_complete?: boolean;
    previous_map?: {
        nodes: SubmittedNode[];
        assignments: SubmittedAssignment[];
    } | null;
};

export type MapNode = {
    key: string;
    name: string;
    /** '@top' or another node's key; an existing category keeps its own parent. */
    parent: string;
    existing: number | null;
};

export type MapState = {
    nodes: MapNode[];
    /** «source|category id» → node key. */
    assignments: Record<string, string>;
    /** Next number for categories created in the editor. */
    counter: number;
};

export type SubmittedNode =
    | { key: string; existing_category_id: number }
    | { key: string; name: string; parent: string };

export type SubmittedAssignment = {
    source: string;
    source_category_id: number;
    node: string;
};

export type Operation = { state: MapState; log: string } | { error: string };

export const memberKey = (source: string, id: number) => `${source}|${id}`;

/** Lower case, without accents or punctuation, spaces collapsed: «Ciencias Básicas» = «ciencias basicas». */
export function normalizeName(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/\p{Mn}+/gu, '')
        .replace(/[^\p{L}\p{N}]+/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

const existingKey = (id: number) => `E-${id}`;

function sourceKey(sources: MapSource[], source: string, id: number): string {
    const index = sources.findIndex((item) => item.source === source);
    const label = (sources[index]?.alias || `S${index + 1}`).replace(
        /[^A-Za-z0-9_-]/g,
        '',
    );

    return `${label || 'S'}-${id}`;
}

/**
 * The starting map: the previous answer of this consolidation when there is
 * one; otherwise the unified tree, one node per source category, the top
 * categories of every source together at the top of Moodle. The destination's
 * existing categories are always there, so categories can be placed in them.
 */
export function initialMap(details: CategoryMapDetails): MapState {
    const existing = details.existing ?? [];
    const existingNodes: MapNode[] = existing.map((category) => ({
        key: existingKey(category.id),
        name: category.name,
        parent: existingParent(existing, category),
        existing: category.id,
    }));
    const previous = details.previous_map;
    if (previous && previous.nodes.length > 0) {
        const nodes: MapNode[] = [...existingNodes];
        for (const node of previous.nodes) {
            if ('existing_category_id' in node) {
                const found = existing.find(
                    (category) => category.id === node.existing_category_id,
                );
                const position = nodes.findIndex(
                    (item) => item.existing === node.existing_category_id,
                );
                if (found && position >= 0) {
                    nodes[position] = { ...nodes[position], key: node.key };
                }
            } else {
                nodes.push({
                    key: node.key,
                    name: node.name,
                    parent: node.parent,
                    existing: null,
                });
            }
        }
        const assignments: Record<string, string> = {};
        for (const assignment of previous.assignments) {
            assignments[
                memberKey(assignment.source, assignment.source_category_id)
            ] = assignment.node;
        }
        // A category the previous answer did not place (the proposal changed) starts at the top.
        const sources = details.map_sources ?? [];
        for (const category of details.categories ?? []) {
            const member = memberKey(category.source, category.id);
            if (!nodes.some((node) => node.key === assignments[member])) {
                const key = `${sourceKey(sources, category.source, category.id)}-N`;
                nodes.push({
                    key,
                    name: category.name,
                    parent: TOP,
                    existing: null,
                });
                assignments[member] = key;
            }
        }

        return { nodes, assignments, counter: nodes.length + 1 };
    }
    const sources = details.map_sources ?? [];
    const nodes: MapNode[] = [...existingNodes];
    const assignments: Record<string, string> = {};
    for (const category of details.categories ?? []) {
        const key = sourceKey(sources, category.source, category.id);
        nodes.push({
            key,
            name: category.name,
            parent:
                category.parent_id === 0
                    ? TOP
                    : sourceKey(sources, category.source, category.parent_id),
            existing: null,
        });
        assignments[memberKey(category.source, category.id)] = key;
    }

    return { nodes, assignments, counter: 1 };
}

function existingParent(
    existing: ExistingCategory[],
    category: ExistingCategory,
): string {
    return category.parent_id !== 0 &&
        existing.some((item) => item.id === category.parent_id)
        ? existingKey(category.parent_id)
        : TOP;
}

export const findNode = (state: MapState, key: string) =>
    state.nodes.find((node) => node.key === key);

export function childrenOf(state: MapState, parent: string): MapNode[] {
    return state.nodes
        .filter((node) => node.parent === parent)
        .sort(
            (left, right) =>
                Number(right.existing !== null) -
                    Number(left.existing !== null) ||
                left.name.localeCompare(right.name, 'es'),
        );
}

export function membersOf(state: MapState, key: string): string[] {
    return Object.entries(state.assignments)
        .filter(([, node]) => node === key)
        .map(([member]) => member)
        .sort();
}

/** Whether candidate is key itself or lies inside it. */
export function isWithin(
    state: MapState,
    candidate: string,
    key: string,
): boolean {
    const seen = new Set<string>();
    let current: string | undefined = candidate;
    while (current && current !== TOP && !seen.has(current)) {
        if (current === key) {
            return true;
        }
        seen.add(current);
        current = findNode(state, current)?.parent;
    }

    return false;
}

/** The node's path from the top: «Ciencias Básicas › Matemáticas». */
export function pathOf(state: MapState, key: string): string {
    const names: string[] = [];
    const seen = new Set<string>();
    let node = findNode(state, key);
    while (node && !seen.has(node.key)) {
        names.unshift(node.name || 'Sin nombre');
        seen.add(node.key);
        node = node.parent === TOP ? undefined : findNode(state, node.parent);
    }

    return names.join(' › ');
}

export function rename(state: MapState, key: string, name: string): Operation {
    const node = findNode(state, key);
    if (!node || node.existing !== null) {
        return {
            error: 'Las categorías que ya existen en el destino no se renombran aquí.',
        };
    }
    const clean = name.replace(/\s+/g, ' ').trim();
    if (clean === node.name) {
        return { error: 'El nombre no cambió.' };
    }

    return {
        state: {
            ...state,
            nodes: state.nodes.map((item) =>
                item.key === key ? { ...item, name: clean } : item,
            ),
        },
        log: `Renombrar «${node.name}» como «${clean}»`,
    };
}

export function move(state: MapState, key: string, parent: string): Operation {
    const node = findNode(state, key);
    if (!node || node.existing !== null) {
        return {
            error: 'Las categorías que ya existen en el destino no se mueven.',
        };
    }
    if (parent !== TOP && !findNode(state, parent)) {
        return { error: 'La categoría de destino no existe.' };
    }
    if (parent !== TOP && isWithin(state, parent, key)) {
        return { error: 'Una categoría no puede quedar dentro de sí misma.' };
    }
    if (node.parent === parent) {
        return { error: 'La categoría ya está en ese lugar.' };
    }

    return {
        state: {
            ...state,
            nodes: state.nodes.map((item) =>
                item.key === key ? { ...item, parent } : item,
            ),
        },
        log: `Mover «${node.name}» a ${parent === TOP ? 'la raíz de Moodle' : `«${pathOf(state, parent)}»`}`,
    };
}

/**
 * Joins source into target: its source categories and its subcategories pass
 * to target and source disappears. A category that already exists on the
 * destination can receive others but never disappears.
 */
export function merge(
    state: MapState,
    source: string,
    target: string,
): Operation {
    const from = findNode(state, source);
    const into = findNode(state, target);
    if (!from || !into || source === target) {
        return { error: 'Elija dos categorías distintas.' };
    }
    if (from.existing !== null) {
        return {
            error: 'Una categoría que ya existe en el destino solo puede recibir otras.',
        };
    }
    if (isWithin(state, target, source)) {
        return {
            error: 'No se puede unir con una categoría que está dentro de ella; muévala primero.',
        };
    }
    const assignments = Object.fromEntries(
        Object.entries(state.assignments).map(([member, node]) => [
            member,
            node === source ? target : node,
        ]),
    );
    const nodes = state.nodes
        .filter((node) => node.key !== source)
        .map((node) =>
            node.parent === source ? { ...node, parent: target } : node,
        );

    return {
        state: { ...state, nodes, assignments },
        log: `Unir «${pathOf(state, source)}» con «${pathOf(state, target)}»`,
    };
}

export function addCategory(
    state: MapState,
    parent: string,
    name: string,
): Operation {
    if (parent !== TOP && !findNode(state, parent)) {
        return { error: 'La categoría padre no existe.' };
    }
    const clean = name.replace(/\s+/g, ' ').trim();
    let counter = state.counter;
    while (findNode(state, `W-${counter}`)) {
        counter++;
    }

    return {
        state: {
            ...state,
            counter: counter + 1,
            nodes: [
                ...state.nodes,
                { key: `W-${counter}`, name: clean, parent, existing: null },
            ],
        },
        log: `Crear «${clean}» en ${parent === TOP ? 'la raíz de Moodle' : `«${pathOf(state, parent)}»`}`,
    };
}

/** Takes one source category out of a merged node, as its own category beside it. */
export function split(
    state: MapState,
    key: string,
    member: string,
    name: string,
): Operation {
    const node = findNode(state, key);
    if (!node || state.assignments[member] !== key) {
        return { error: 'La categoría de origen no está en esta categoría.' };
    }
    if (node.existing === null && membersOf(state, key).length < 2) {
        return { error: 'Solo se separa una categoría unida con otras.' };
    }
    const created = addCategory(
        state,
        node.existing === null ? node.parent : TOP,
        name,
    );
    if ('error' in created) {
        return created;
    }
    const newKey = created.state.nodes[created.state.nodes.length - 1].key;

    return {
        state: {
            ...created.state,
            assignments: { ...created.state.assignments, [member]: newKey },
        },
        log: `Separar «${name}» de «${pathOf(state, key)}»`,
    };
}

/** Removes a new category with nothing assigned; its subcategories go up one level. */
export function remove(state: MapState, key: string): Operation {
    const node = findNode(state, key);
    if (!node || node.existing !== null) {
        return {
            error: 'Las categorías que ya existen en el destino no se quitan.',
        };
    }
    if (membersOf(state, key).length > 0) {
        return { error: 'Primero mueva o una sus categorías de origen.' };
    }

    return {
        state: {
            ...state,
            nodes: state.nodes
                .filter((item) => item.key !== key)
                .map((item) =>
                    item.parent === key
                        ? { ...item, parent: node.parent }
                        : item,
                ),
        },
        log: `Quitar «${pathOf(state, key)}»`,
    };
}

/** Whether a new category is needed by the map: it has categories assigned or inside it. */
function used(state: MapState, key: string): boolean {
    return (
        membersOf(state, key).length > 0 ||
        state.nodes.some((node) => node.parent === key && used(state, node.key))
    );
}

/**
 * The problems that keep the map from being approved, with the Consolidator's
 * rules: names, cycles, duplicate siblings, unmapped categories and a new
 * category beside an existing one with the same name.
 */
export function validateMap(
    details: CategoryMapDetails,
    state: MapState,
): MapIssue[] {
    const issues: MapIssue[] = [];
    const keys = new Set(state.nodes.map((node) => node.key));
    for (const node of state.nodes) {
        if (node.existing !== null) {
            continue;
        }
        if (node.name.trim() === '') {
            issues.push({
                code: 'NAME_EMPTY',
                node: node.key,
                message: 'Una categoría no tiene nombre.',
            });
        } else if (node.name.length > 255) {
            issues.push({
                code: 'NAME_TOO_LONG',
                node: node.key,
                message: `«${node.name.slice(0, 40)}…» supera los 255 caracteres.`,
            });
        }
        if (node.parent !== TOP && !keys.has(node.parent)) {
            issues.push({
                code: 'PARENT_MISSING',
                node: node.key,
                message: `La categoría padre de «${node.name}» no existe.`,
            });
        } else if (
            node.parent !== TOP &&
            isWithin(state, node.parent, node.key)
        ) {
            issues.push({
                code: 'CYCLE',
                node: node.key,
                message: `«${node.name}» queda dentro de sí misma.`,
            });
        }
    }
    for (const category of details.categories ?? []) {
        const node = state.assignments[memberKey(category.source, category.id)];
        if (!node || !keys.has(node)) {
            issues.push({
                code: 'UNMAPPED',
                node: null,
                message: `«${category.name}» de ${category.source} no tiene destino.`,
            });
        }
    }
    const siblings = new Map<string, MapNode[]>();
    for (const node of state.nodes) {
        if (
            node.existing === null &&
            node.name.trim() !== '' &&
            used(state, node.key)
        ) {
            const group = `${node.parent}|${normalizeName(node.name)}`;
            siblings.set(group, [...(siblings.get(group) ?? []), node]);
        }
    }
    for (const group of siblings.values()) {
        if (group.length > 1) {
            for (const node of group) {
                issues.push({
                    code: 'SIBLING_DUPLICATE',
                    node: node.key,
                    message: `Hay ${group.length} categorías «${node.name}» en ${node.parent === TOP ? 'la raíz' : `«${pathOf(state, node.parent)}»`}: únalas o cambie el nombre.`,
                });
            }
        }
    }
    for (const node of state.nodes) {
        if (
            node.existing !== null ||
            node.name.trim() === '' ||
            !used(state, node.key)
        ) {
            continue;
        }
        const parent =
            node.parent === TOP ? null : findNode(state, node.parent);
        if (node.parent !== TOP && parent?.existing === null) {
            continue;
        }
        const parentId = parent ? parent.existing : 0;
        const homonym = (details.existing ?? []).find(
            (category) =>
                category.parent_id === parentId &&
                category.name.trim().toLowerCase() ===
                    node.name.trim().toLowerCase(),
        );
        if (homonym) {
            issues.push({
                code: 'EXISTING_HOMONYM',
                node: node.key,
                message: `El destino ya tiene «${homonym.name}» en ese lugar: una «${node.name}» con ella o cambie el nombre.`,
            });
        }
    }

    return issues;
}

/** The Consolidator's merge suggestions still pending: both categories sit in different nodes. */
export function pendingSuggestions(
    details: CategoryMapDetails,
    state: MapState,
): (MapSuggestion & { from: string; into: string })[] {
    const seen = new Set<string>();

    return (details.suggestions ?? []).flatMap((suggestion) => {
        const a =
            state.assignments[memberKey(suggestion.a.source, suggestion.a.id)];
        const b =
            state.assignments[memberKey(suggestion.b.source, suggestion.b.id)];
        const pair = [a, b].sort().join('+');
        if (
            !a ||
            !b ||
            a === b ||
            seen.has(pair) ||
            !findNode(state, a) ||
            !findNode(state, b)
        ) {
            return [];
        }
        seen.add(pair);
        const aNode = findNode(state, a)!;
        // Merge into the existing category, else into the first one.
        const [from, into] = aNode.existing !== null ? [b, a] : [a, b];
        if (isWithin(state, into, from) || isWithin(state, from, into)) {
            return [];
        }

        return [{ ...suggestion, from, into }];
    });
}

/** The courses that will land in a node, from its source categories. */
export function coursesOf(
    details: CategoryMapDetails,
    state: MapState,
    key: string,
): { source: string; category: string; course: MapCourse }[] {
    const members = new Set(membersOf(state, key));

    return (details.categories ?? [])
        .filter((category) =>
            members.has(memberKey(category.source, category.id)),
        )
        .flatMap((category) =>
            category.courses.map((course) => ({
                source: category.source,
                category: category.name,
                course,
            })),
        );
}

export function courseCount(
    details: CategoryMapDetails,
    state: MapState,
    key: string,
): number {
    const members = new Set(membersOf(state, key));

    return (details.categories ?? [])
        .filter((category) =>
            members.has(memberKey(category.source, category.id)),
        )
        .reduce((total, category) => total + category.course_count, 0);
}

/** What travels to the platform: the categories in use and every assignment. */
export function serializeMap(state: MapState): {
    nodes: SubmittedNode[];
    assignments: SubmittedAssignment[];
} {
    const needed = new Set<string>();
    for (const node of state.nodes) {
        if (used(state, node.key)) {
            let current: MapNode | undefined = node;
            while (current && !needed.has(current.key)) {
                needed.add(current.key);
                current =
                    current.parent === TOP
                        ? undefined
                        : findNode(state, current.parent);
            }
        }
    }
    const nodes: SubmittedNode[] = state.nodes
        .filter((node) => needed.has(node.key))
        .map((node) =>
            node.existing !== null
                ? { key: node.key, existing_category_id: node.existing }
                : {
                      key: node.key,
                      name: node.name.trim(),
                      parent: node.parent,
                  },
        );
    const assignments = Object.entries(state.assignments)
        .map(([member, node]) => {
            const separator = member.lastIndexOf('|');

            return {
                source: member.slice(0, separator),
                source_category_id: Number(member.slice(separator + 1)),
                node,
            };
        })
        .sort((left, right) =>
            left.source === right.source
                ? left.source_category_id - right.source_category_id
                : left.source.localeCompare(right.source),
        );

    return { nodes, assignments };
}

/** The node the pilot course will land in. */
export function pilotNode(
    details: CategoryMapDetails,
    state: MapState,
): string | null {
    const pilot = details.pilot;

    return pilot
        ? (state.assignments[
              memberKey(pilot.source, pilot.source_category_id)
          ] ?? null)
        : null;
}
