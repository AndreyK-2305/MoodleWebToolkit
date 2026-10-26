/**
 * Pure helpers for «¿De dónde salen los orígenes?» in the consolidation card.
 * The backend validates everything again; these only shape the form.
 */

export type SourceMode = 'PROJECT' | 'COLLECT_NOW' | 'MANUAL';

export type CollectionPackage = {
    uuid: string;
    name: string;
    source_id: string;
    sha256: string;
    size: number;
    created_at: string | null;
    older: number;
};

export type CompletedCollection = {
    uuid: string;
    name: string;
    completed_at: string | null;
    packages: CollectionPackage[];
};

export type LinkedSource = {
    name: string;
    instance_id: string;
    region: string;
    source_profile: string;
    package_name: string;
};

/**
 * The mode the card opens with: the saved choice when there is one,
 * otherwise a completed collection when one exists, else collecting now.
 */
export function initialSourceMode({
    linkMode,
    sourceProjectUuid,
    configured,
    collections,
}: {
    linkMode: string | null;
    sourceProjectUuid: string | null;
    configured: boolean;
    collections: number;
}): SourceMode {
    if (linkMode === 'COLLECT_NOW') {
        return 'COLLECT_NOW';
    }
    if (sourceProjectUuid) {
        return 'PROJECT';
    }
    if (configured) {
        return 'MANUAL';
    }

    return collections > 0 ? 'PROJECT' : 'COLLECT_NOW';
}

/** A collection's packages are all pre-selected: one per source, the newest. */
export function preselectedPackages(
    collection: CompletedCollection | undefined,
): string[] {
    return (collection?.packages ?? []).map((item) => item.uuid);
}

/**
 * A package name the Recolector accepts, derived from the source's name:
 * lowercase, starts with a letter, only letters, digits, «-» and «_».
 */
export function packageNameFor(name: string, sequence: number): string {
    const slug = name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/^[^a-z]+/, '')
        .replace(/-+$/, '')
        .slice(0, 64)
        .replace(/-+$/, '');

    return slug === '' ? `origen-${sequence}` : slug;
}

export function blankLinkedSource(
    sequence: number,
    region: string,
): LinkedSource {
    return {
        name: `Origen ${sequence}`,
        instance_id: '',
        region,
        source_profile: '',
        package_name: `origen-${sequence}`,
    };
}

/** The first problem that keeps the «Recolectar ahora» form from being sent, or null. */
export function linkedSourcesProblem(
    sources: LinkedSource[],
    minimum: number,
    maximum: number,
): string | null {
    if (sources.length < minimum) {
        return `Agregue al menos ${minimum} orígenes: una consolidación une varios Moodle.`;
    }
    if (sources.length > maximum) {
        return `Se admiten como máximo ${maximum} orígenes.`;
    }
    const empty = sources.findIndex(
        (source) =>
            source.name.trim() === '' ||
            source.instance_id.trim() === '' ||
            source.region.trim() === '' ||
            source.source_profile.trim() === '' ||
            source.package_name.trim() === '',
    );
    if (empty >= 0) {
        return `Complete todos los datos del origen ${empty + 1}.`;
    }
    const repeated = (values: string[]) =>
        new Set(values.map((value) => value.trim().toLowerCase())).size !==
        values.length;
    if (repeated(sources.map((source) => source.instance_id))) {
        return 'Cada origen es un servidor distinto: no repita el ID de instancia.';
    }
    if (repeated(sources.map((source) => source.package_name))) {
        return 'Cada origen necesita su propio nombre de paquete.';
    }
    if (repeated(sources.map((source) => source.name))) {
        return 'Cada origen necesita un nombre distinto.';
    }

    return null;
}

export function formatSize(bytes: number): string {
    if (bytes < 1048576) {
        return `${Math.max(1, Math.round(bytes / 1024))} KiB`;
    }
    if (bytes < 1073741824) {
        return `${(bytes / 1048576).toFixed(1)} MiB`;
    }

    return `${(bytes / 1073741824).toFixed(1)} GiB`;
}

export function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }
    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleString('es', {
              dateStyle: 'medium',
              timeStyle: 'short',
          });
}
