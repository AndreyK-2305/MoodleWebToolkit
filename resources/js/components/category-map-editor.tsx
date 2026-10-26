import {
    DndContext,
    PointerSensor,
    useDraggable,
    useDroppable,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import {
    ChevronDown,
    ChevronRight,
    FolderTree,
    GripVertical,
    Lock,
    Undo2,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    TOP,
    addCategory,
    childrenOf,
    courseCount,
    coursesOf,
    findNode,
    initialMap,
    isWithin,
    memberKey,
    membersOf,
    merge,
    move,
    pathOf,
    pendingSuggestions,
    emptyCategories,
    generalCategory,
    pilotNode,
    remove,
    rename,
    serializeMap,
    split,
    validateMap,
} from '@/lib/category-map';
import type {
    CategoryMapDetails,
    MapState,
    Operation,
    SubmittedAssignment,
    SubmittedNode,
} from '@/lib/category-map';

export type CategoryMapAnswer = {
    nodes: SubmittedNode[];
    assignments: SubmittedAssignment[];
    operations: string[];
    confirm: boolean;
};

type Row = { key: string; level: number; hasChildren: boolean };

/**
 * The destination category map before anything is written: the sources' trees
 * on one side, the consolidated Moodle on the other. Categories are merged,
 * renamed, moved (by dragging or with «Mover a…») and split; the map is
 * approved only when it passes the Consolidator's rules. Everything works
 * with the keyboard: the tree follows the WAI-ARIA tree pattern and every
 * change is announced.
 */
export function CategoryMapEditor({
    details,
    disabled,
    processing,
    errors,
    onSubmit,
}: {
    details: CategoryMapDetails;
    disabled: boolean;
    processing: boolean;
    errors: Record<string, string>;
    onSubmit: (answer: CategoryMapAnswer) => void;
}) {
    const [state, setState] = useState<MapState>(() => initialMap(details));
    const [history, setHistory] = useState<{ state: MapState; log: string }[]>(
        [],
    );
    const [selected, setSelected] = useState<string | null>(null);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const [announcement, setAnnouncement] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const treeRef = useRef<HTMLUListElement>(null);
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    );

    const issues = useMemo(() => validateMap(details, state), [details, state]);
    const suggestions = useMemo(
        () => pendingSuggestions(details, state),
        [details, state],
    );
    const empties = useMemo(
        () => emptyCategories(details, state),
        [details, state],
    );
    const general = generalCategory(state);
    const rows = useMemo(() => {
        const list: Row[] = [];
        const walk = (parent: string, level: number) => {
            for (const node of childrenOf(state, parent)) {
                const children = childrenOf(state, node.key);
                list.push({
                    key: node.key,
                    level,
                    hasChildren: children.length > 0,
                });
                if (!collapsed.has(node.key)) {
                    walk(node.key, level + 1);
                }
            }
        };
        walk(TOP, 1);

        return list;
    }, [state, collapsed]);
    const pilot = pilotNode(details, state);
    const focused = selected ?? rows[0]?.key ?? null;

    const apply = (operation: Operation) => {
        if ('error' in operation) {
            setAnnouncement(operation.error);

            return false;
        }
        setHistory((previous) => [...previous, { state, log: operation.log }]);
        setState(operation.state);
        setAnnouncement(`${operation.log}.`);

        return true;
    };
    const undo = () => {
        const last = history[history.length - 1];
        if (!last) {
            return;
        }
        setHistory(history.slice(0, -1));
        setState(last.state);
        setAnnouncement(`Deshecho: ${last.log}.`);
    };
    const focusRow = (key: string) => {
        setSelected(key);
        requestAnimationFrame(() =>
            treeRef.current
                ?.querySelector<HTMLElement>(`[data-node="${key}"]`)
                ?.focus(),
        );
    };
    const toggle = (key: string, open?: boolean) =>
        setCollapsed((previous) => {
            const next = new Set(previous);
            const shouldOpen = open ?? next.has(key);
            if (shouldOpen) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });

    const onTreeKeyDown = (event: KeyboardEvent<HTMLUListElement>) => {
        const index = rows.findIndex((row) => row.key === focused);
        const row = rows[index];
        if (!row) {
            return;
        }
        const node = findNode(state, row.key);
        const handled = () => {
            event.preventDefault();
            event.stopPropagation();
        };
        switch (event.key) {
            case 'ArrowDown':
                handled();
                if (rows[index + 1]) {
                    focusRow(rows[index + 1].key);
                }
                break;
            case 'ArrowUp':
                handled();
                if (rows[index - 1]) {
                    focusRow(rows[index - 1].key);
                }
                break;
            case 'Home':
                handled();
                focusRow(rows[0].key);
                break;
            case 'End':
                handled();
                focusRow(rows[rows.length - 1].key);
                break;
            case 'ArrowRight':
                handled();
                if (row.hasChildren && collapsed.has(row.key)) {
                    toggle(row.key, true);
                } else if (row.hasChildren && rows[index + 1]) {
                    focusRow(rows[index + 1].key);
                }
                break;
            case 'ArrowLeft':
                handled();
                if (row.hasChildren && !collapsed.has(row.key)) {
                    toggle(row.key, false);
                } else if (node && node.parent !== TOP) {
                    focusRow(node.parent);
                }
                break;
            case 'F2':
            case 'Enter':
                handled();
                document.getElementById('category-map-name')?.focus();
                break;
            case 'z':
                if (event.ctrlKey || event.metaKey) {
                    handled();
                    undo();
                }
                break;
        }
    };

    const onDragEnd = (event: DragEndEvent) => {
        const key = String(event.active.id);
        const target = event.over ? String(event.over.id) : null;
        if (target === null || target === key) {
            return;
        }
        if (apply(move(state, key, target === 'drop-top' ? TOP : target))) {
            setSelected(key);
        }
    };

    const submit = () => {
        const map = serializeMap(state);
        onSubmit({
            ...map,
            operations: history.map((entry) => entry.log),
            confirm: confirmed,
        });
    };

    const node = focused ? findNode(state, focused) : undefined;

    return (
        <div className="space-y-4">
            <p className="sr-only" role="status" aria-live="polite">
                {announcement}
            </p>
            {(details.map_issues ?? []).length > 0 && (
                <div className="rounded-md border border-red-300 bg-red-50 p-3 text-xs dark:border-red-900 dark:bg-red-950/30">
                    <p className="font-medium">
                        El Consolidador no aceptó el mapa anterior:
                    </p>
                    <ul className="list-disc pl-4">
                        {(details.map_issues ?? []).map((issue, index) => (
                            <li key={index}>{issue.message}</li>
                        ))}
                    </ul>
                </div>
            )}
            {suggestions.length > 0 && (
                <section aria-labelledby="category-map-suggestions">
                    <h4
                        id="category-map-suggestions"
                        className="text-xs font-medium"
                    >
                        Sugerencias de unión ({suggestions.length})
                    </h4>
                    <ul className="mt-1 space-y-1">
                        {suggestions.map((suggestion) => (
                            <li
                                key={`${suggestion.from}+${suggestion.into}`}
                                className="flex flex-wrap items-center gap-2 text-xs"
                            >
                                <Badge
                                    variant={
                                        suggestion.kind === 'same'
                                            ? 'default'
                                            : 'secondary'
                                    }
                                >
                                    {suggestion.kind === 'same'
                                        ? 'Coinciden'
                                        : `Parecidas (${Math.round(suggestion.score * 100)} %)`}
                                </Badge>
                                <span>
                                    «{pathOf(state, suggestion.from)}» y «
                                    {pathOf(state, suggestion.into)}»
                                </span>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={disabled}
                                    onClick={() => {
                                        if (
                                            apply(
                                                merge(
                                                    state,
                                                    suggestion.from,
                                                    suggestion.into,
                                                ),
                                            )
                                        ) {
                                            setSelected(suggestion.into);
                                        }
                                    }}
                                >
                                    Unir
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {empties.length > 0 && (
                <section aria-labelledby="category-map-empty">
                    <h4 id="category-map-empty" className="text-xs font-medium">
                        Categorías sin cursos ({empties.length})
                    </h4>
                    <p className="text-muted-foreground text-xs">
                        No reciben ningún curso: se crearían vacías en el
                        destino. Puede dejarlas así o unirlas con la categoría
                        general.
                    </p>
                    <ul className="mt-1 space-y-1">
                        {empties.map((empty) => (
                            <li
                                key={empty.key}
                                className="flex flex-wrap items-center gap-2 text-xs"
                            >
                                <span>«{pathOf(state, empty.key)}»</span>
                                {general && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={disabled}
                                        onClick={() => {
                                            if (
                                                apply(
                                                    merge(
                                                        state,
                                                        empty.key,
                                                        general.key,
                                                    ),
                                                )
                                            ) {
                                                setSelected(general.key);
                                            }
                                        }}
                                    >
                                        Unir con «{general.name}»
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                <section aria-labelledby="category-map-before">
                    <h4
                        id="category-map-before"
                        className="text-xs font-medium"
                    >
                        Antes: categorías de cada origen
                    </h4>
                    <SourceTrees
                        details={details}
                        state={state}
                        highlighted={focused}
                        onPick={(member) => {
                            const target = state.assignments[member];
                            if (target) {
                                focusRow(target);
                            }
                        }}
                    />
                </section>

                <section aria-labelledby="category-map-after">
                    <div className="flex items-center justify-between gap-2">
                        <h4
                            id="category-map-after"
                            className="text-xs font-medium"
                        >
                            Después: Moodle consolidado
                        </h4>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={disabled || history.length === 0}
                            onClick={undo}
                        >
                            <Undo2 /> Deshacer
                        </Button>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        Arrastre una categoría sobre otra para colocarla dentro,
                        o use las acciones de la derecha. Con el teclado:
                        flechas para recorrer, Intro para editar, Ctrl+Z para
                        deshacer.
                    </p>
                    <DndContext
                        sensors={sensors}
                        onDragEnd={onDragEnd}
                        accessibility={{
                            screenReaderInstructions: {
                                draggable:
                                    'Use las acciones de la categoría para moverla con el teclado.',
                            },
                        }}
                    >
                        <TopDropZone />
                        <ul
                            ref={treeRef}
                            role="tree"
                            aria-labelledby="category-map-after"
                            className="bg-background mt-1 max-h-[28rem] overflow-auto rounded-md border p-1"
                            onKeyDown={onTreeKeyDown}
                        >
                            {rows.map((row) => (
                                <TreeRow
                                    key={row.key}
                                    row={row}
                                    state={state}
                                    details={details}
                                    focused={row.key === focused}
                                    expanded={!collapsed.has(row.key)}
                                    pilot={row.key === pilot}
                                    invalid={issues.some(
                                        (issue) =>
                                            issue.node === row.key ||
                                            issue.nodes?.includes(row.key),
                                    )}
                                    disabled={disabled}
                                    onSelect={() => setSelected(row.key)}
                                    onToggle={() => toggle(row.key)}
                                />
                            ))}
                        </ul>
                    </DndContext>
                </section>
            </div>

            {node && (
                <NodeActions
                    key={node.key}
                    details={details}
                    state={state}
                    nodeKey={node.key}
                    disabled={disabled}
                    apply={apply}
                    select={focusRow}
                />
            )}

            <section aria-labelledby="category-map-check" className="space-y-2">
                <h4 id="category-map-check" className="text-xs font-medium">
                    Revisión antes de aprobar
                </h4>
                {pilot && (
                    <p className="text-xs">
                        El curso piloto {details.pilot?.shortname} se restaurará
                        en «{pathOf(state, pilot)}».
                    </p>
                )}
                {issues.length > 0 ? (
                    <ul className="space-y-1 text-xs text-red-700 dark:text-red-400">
                        {issues.slice(0, 20).map((issue, index) => (
                            <li key={index}>
                                {issue.node ? (
                                    <button
                                        type="button"
                                        className="text-left underline"
                                        onClick={() => focusRow(issue.node!)}
                                    >
                                        {issue.message}
                                    </button>
                                ) : (
                                    issue.message
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-xs text-emerald-700 dark:text-emerald-400">
                        El mapa cumple las reglas del Consolidador.
                    </p>
                )}
                <label className="flex gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={confirmed}
                        onChange={(event) => setConfirmed(event.target.checked)}
                    />
                    Revisé el árbol: las categorías se crearán así en el destino
                </label>
                <InputError
                    message={
                        errors.category_map ??
                        errors.nodes ??
                        errors.assignments ??
                        errors.confirm ??
                        errors.conflict
                    }
                />
                <Button
                    disabled={
                        disabled ||
                        processing ||
                        !confirmed ||
                        issues.length > 0 ||
                        !details.proposal_sha256
                    }
                    onClick={submit}
                >
                    Aprobar mapa y continuar
                </Button>
            </section>
        </div>
    );
}

function TopDropZone() {
    const { setNodeRef, isOver } = useDroppable({ id: 'drop-top' });

    return (
        <div
            ref={setNodeRef}
            className={`text-muted-foreground mt-1 rounded-md border border-dashed px-2 py-1 text-xs ${isOver ? 'border-primary bg-primary/10' : ''}`}
        >
            Suelte aquí para dejarla en la raíz de Moodle
        </div>
    );
}

function TreeRow({
    row,
    state,
    details,
    focused,
    expanded,
    pilot,
    invalid,
    disabled,
    onSelect,
    onToggle,
}: {
    row: Row;
    state: MapState;
    details: CategoryMapDetails;
    focused: boolean;
    expanded: boolean;
    pilot: boolean;
    invalid: boolean;
    disabled: boolean;
    onSelect: () => void;
    onToggle: () => void;
}) {
    const node = findNode(state, row.key)!;
    const locked = node.existing !== null;
    const drag = useDraggable({ id: row.key, disabled: disabled || locked });
    const drop = useDroppable({ id: row.key });
    const members = membersOf(state, row.key).length;
    const courses = courseCount(details, state, row.key);

    return (
        <li
            ref={drop.setNodeRef}
            role="treeitem"
            data-node={row.key}
            aria-level={row.level}
            aria-selected={focused}
            aria-expanded={row.hasChildren ? expanded : undefined}
            tabIndex={focused ? 0 : -1}
            onClick={onSelect}
            onFocus={onSelect}
            className={`flex cursor-default items-center gap-1 rounded px-1 py-0.5 text-sm outline-none focus-visible:ring-2 ${focused ? 'bg-accent' : ''} ${drop.isOver ? 'ring-primary ring-2' : ''} ${drag.isDragging ? 'opacity-50' : ''}`}
            style={{ paddingLeft: `${(row.level - 1) * 1.25 + 0.25}rem` }}
        >
            {row.hasChildren ? (
                <span
                    aria-hidden="true"
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggle();
                    }}
                >
                    {expanded ? (
                        <ChevronDown className="size-4" />
                    ) : (
                        <ChevronRight className="size-4" />
                    )}
                </span>
            ) : (
                <span className="size-4" aria-hidden="true" />
            )}
            {!locked && (
                <span
                    ref={drag.setNodeRef}
                    {...drag.listeners}
                    aria-hidden="true"
                    className="text-muted-foreground cursor-grab"
                >
                    <GripVertical className="size-4" />
                </span>
            )}
            {locked && (
                <Lock
                    className="text-muted-foreground size-3"
                    aria-hidden="true"
                />
            )}
            <span className={node.name.trim() === '' ? 'italic' : ''}>
                {node.name.trim() === '' ? 'Sin nombre' : node.name}
            </span>
            <span className="text-muted-foreground text-xs">
                {locked && ' · ya existe en el destino'}
                {members > 1 && ` · une ${members} categorías`}
                {` · ${courses} ${courses === 1 ? 'curso' : 'cursos'}`}
            </span>
            {pilot && <Badge variant="secondary">Piloto</Badge>}
            {invalid && (
                <span className="text-xs text-red-700 dark:text-red-400">
                    · revisar
                </span>
            )}
        </li>
    );
}

function SourceTrees({
    details,
    state,
    highlighted,
    onPick,
}: {
    details: CategoryMapDetails;
    state: MapState;
    highlighted: string | null;
    onPick: (member: string) => void;
}) {
    const sources = details.map_sources ?? [];
    const categories = details.categories ?? [];
    const render = (
        source: string,
        parent: number,
        level: number,
    ): ReactNode[] =>
        categories
            .filter(
                (item) => item.source === source && item.parent_id === parent,
            )
            .map((item) => {
                const member = memberKey(item.source, item.id);
                const target = state.assignments[member];
                const active = target !== undefined && target === highlighted;

                return (
                    <li key={member}>
                        <button
                            type="button"
                            onClick={() => onPick(member)}
                            className={`w-full rounded px-1 py-0.5 text-left text-sm ${active ? 'bg-accent font-medium' : ''}`}
                            style={{ paddingLeft: `${level * 1.25}rem` }}
                        >
                            {item.name}
                            <span className="text-muted-foreground text-xs">
                                {` · ${item.course_count} ${item.course_count === 1 ? 'curso' : 'cursos'}`}
                                {target && ` → ${pathOf(state, target)}`}
                            </span>
                        </button>
                        <ul>{render(source, item.id, level + 1)}</ul>
                    </li>
                );
            });

    return (
        <div className="bg-background mt-1 max-h-[31rem] space-y-2 overflow-auto rounded-md border p-2">
            {sources.map((source) => (
                <div key={source.source}>
                    <p className="flex items-center gap-1 text-xs font-medium">
                        <FolderTree className="size-3" aria-hidden="true" />
                        {source.name}
                        <span className="text-muted-foreground font-mono">
                            {source.source}
                        </span>
                    </p>
                    <ul>{render(source.source, 0, 0)}</ul>
                </div>
            ))}
        </div>
    );
}

function NodeActions({
    details,
    state,
    nodeKey,
    disabled,
    apply,
    select,
}: {
    details: CategoryMapDetails;
    state: MapState;
    nodeKey: string;
    disabled: boolean;
    apply: (operation: Operation) => boolean;
    select: (key: string) => void;
}) {
    const node = findNode(state, nodeKey)!;
    const locked = node.existing !== null;
    const [name, setName] = useState(node.name);
    const [child, setChild] = useState('');
    const [mergeTarget, setMergeTarget] = useState('');
    const [parentTarget, setParentTarget] = useState(node.parent);
    const members = membersOf(state, nodeKey);
    const courses = coursesOf(details, state, nodeKey);
    const others = state.nodes
        .filter((item) => item.key !== nodeKey)
        .map((item) => ({ key: item.key, path: pathOf(state, item.key) }))
        .sort((left, right) => left.path.localeCompare(right.path, 'es'));
    const parents = others.filter(
        (item) => !isWithin(state, item.key, nodeKey),
    );
    const categoryOf = (member: string) =>
        (details.categories ?? []).find(
            (item) => memberKey(item.source, item.id) === member,
        );

    return (
        <section
            aria-labelledby="category-map-actions"
            className="bg-background space-y-3 rounded-md border p-3"
        >
            <h4 id="category-map-actions" className="text-sm font-medium">
                «{pathOf(state, nodeKey)}»
                {locked && (
                    <span className="text-muted-foreground text-xs font-normal">
                        {' '}
                        · ya existe en el destino: conserva su nombre y lugar, y
                        puede recibir otras categorías
                    </span>
                )}
            </h4>
            {!locked && (
                <div className="grid gap-3 md:grid-cols-2">
                    <form
                        className="space-y-1"
                        onSubmit={(event) => {
                            event.preventDefault();
                            apply(rename(state, nodeKey, name));
                        }}
                    >
                        <label
                            htmlFor="category-map-name"
                            className="text-xs font-medium"
                        >
                            Nombre
                        </label>
                        <div className="flex gap-2">
                            <Input
                                id="category-map-name"
                                value={name}
                                maxLength={255}
                                disabled={disabled}
                                onChange={(event) =>
                                    setName(event.target.value)
                                }
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        setName(node.name);
                                        select(nodeKey);
                                    }
                                }}
                            />
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={disabled}
                            >
                                Renombrar
                            </Button>
                        </div>
                    </form>
                    <div className="space-y-1">
                        <label
                            htmlFor="category-map-parent"
                            className="text-xs font-medium"
                        >
                            Mover a…
                        </label>
                        <div className="flex gap-2">
                            <select
                                id="category-map-parent"
                                className="border-input bg-background w-full rounded-md border px-2 py-1 text-sm"
                                value={parentTarget}
                                disabled={disabled}
                                onChange={(event) =>
                                    setParentTarget(event.target.value)
                                }
                            >
                                <option value={TOP}>Raíz de Moodle</option>
                                {parents.map((item) => (
                                    <option key={item.key} value={item.key}>
                                        {item.path}
                                    </option>
                                ))}
                            </select>
                            <Button
                                variant="outline"
                                disabled={
                                    disabled || parentTarget === node.parent
                                }
                                onClick={() => {
                                    if (
                                        apply(
                                            move(state, nodeKey, parentTarget),
                                        )
                                    ) {
                                        select(nodeKey);
                                    }
                                }}
                            >
                                Mover
                            </Button>
                        </div>
                    </div>
                    <div className="space-y-1">
                        <label
                            htmlFor="category-map-merge"
                            className="text-xs font-medium"
                        >
                            Unir con…
                        </label>
                        <div className="flex gap-2">
                            <select
                                id="category-map-merge"
                                className="border-input bg-background w-full rounded-md border px-2 py-1 text-sm"
                                value={mergeTarget}
                                disabled={disabled}
                                onChange={(event) =>
                                    setMergeTarget(event.target.value)
                                }
                            >
                                <option value="">Elija una categoría</option>
                                {parents.map((item) => (
                                    <option key={item.key} value={item.key}>
                                        {item.path}
                                    </option>
                                ))}
                            </select>
                            <Button
                                variant="outline"
                                disabled={disabled || mergeTarget === ''}
                                onClick={() => {
                                    if (
                                        apply(
                                            merge(state, nodeKey, mergeTarget),
                                        )
                                    ) {
                                        select(mergeTarget);
                                    }
                                }}
                            >
                                Unir
                            </Button>
                        </div>
                        <p className="text-muted-foreground text-xs">
                            Sus cursos y subcategorías pasan a la categoría
                            elegida, que conserva su nombre.
                        </p>
                    </div>
                    {members.length === 0 && (
                        <div className="space-y-1">
                            <p className="text-xs font-medium">
                                Categoría sin origen
                            </p>
                            <Button
                                variant="outline"
                                disabled={disabled}
                                onClick={() => {
                                    const parent = node.parent;
                                    if (
                                        apply(remove(state, nodeKey)) &&
                                        parent !== TOP
                                    ) {
                                        select(parent);
                                    }
                                }}
                            >
                                Quitar categoría
                            </Button>
                        </div>
                    )}
                </div>
            )}
            <form
                className="space-y-1"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (
                        child.trim() !== '' &&
                        apply(addCategory(state, nodeKey, child))
                    ) {
                        setChild('');
                    }
                }}
            >
                <label
                    htmlFor="category-map-child"
                    className="text-xs font-medium"
                >
                    Nueva subcategoría
                </label>
                <div className="flex gap-2">
                    <Input
                        id="category-map-child"
                        value={child}
                        maxLength={255}
                        disabled={disabled}
                        onChange={(event) => setChild(event.target.value)}
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={disabled || child.trim() === ''}
                    >
                        Crear
                    </Button>
                </div>
            </form>
            {members.length > 0 && (
                <div>
                    <p className="text-xs font-medium">
                        Categorías de origen que llegan aquí
                    </p>
                    <ul className="space-y-1 text-xs">
                        {members.map((member) => {
                            const category = categoryOf(member);

                            return (
                                <li
                                    key={member}
                                    className="flex items-center gap-2"
                                >
                                    <span>
                                        «{category?.name}»{' '}
                                        <span className="text-muted-foreground font-mono">
                                            {category?.source}
                                        </span>
                                    </span>
                                    {(members.length > 1 || locked) &&
                                        category && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={disabled}
                                                onClick={() =>
                                                    apply(
                                                        split(
                                                            state,
                                                            nodeKey,
                                                            member,
                                                            category.name,
                                                        ),
                                                    )
                                                }
                                            >
                                                Separar
                                            </Button>
                                        )}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
            {courses.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-xs font-medium">
                        Cursos ({courseCount(details, state, nodeKey)})
                    </summary>
                    <ul className="mt-1 max-h-48 overflow-auto text-xs">
                        {courses.map(({ source, course }) => (
                            <li key={`${source}|${course.id}`}>
                                <span className="font-mono">
                                    {course.shortname}
                                </span>{' '}
                                {course.fullname !== course.shortname &&
                                    course.fullname}{' '}
                                <span className="text-muted-foreground">
                                    ({source})
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </section>
    );
}
