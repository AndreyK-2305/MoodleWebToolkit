import { describe, expect, it } from 'vitest';
import {
    blankLinkedSource,
    formatSize,
    initialSourceMode,
    linkedSourcesProblem,
    packageNameFor,
    preselectedPackages,
} from './consolidation-sources';
import type { LinkedSource } from './consolidation-sources';

const source = (changes: Partial<LinkedSource> = {}): LinkedSource => ({
    name: 'Pregrado',
    instance_id: 'i-0123456789abcdef0',
    region: 'us-east-2',
    source_profile: 'pregrado',
    package_name: 'pregrado',
    ...changes,
});

describe('initialSourceMode', () => {
    it('keeps a pending linked collection', () => {
        expect(
            initialSourceMode({
                linkMode: 'COLLECT_NOW',
                sourceProjectUuid: 'abc',
                configured: true,
                collections: 3,
            }),
        ).toBe('COLLECT_NOW');
    });

    it('reopens a saved choice', () => {
        expect(
            initialSourceMode({
                linkMode: null,
                sourceProjectUuid: 'abc',
                configured: true,
                collections: 0,
            }),
        ).toBe('PROJECT');
        expect(
            initialSourceMode({
                linkMode: null,
                sourceProjectUuid: null,
                configured: true,
                collections: 2,
            }),
        ).toBe('MANUAL');
    });

    it('offers a completed collection first and collecting now otherwise', () => {
        const base = {
            linkMode: null,
            sourceProjectUuid: null,
            configured: false,
        };
        expect(initialSourceMode({ ...base, collections: 1 })).toBe('PROJECT');
        expect(initialSourceMode({ ...base, collections: 0 })).toBe(
            'COLLECT_NOW',
        );
    });
});

describe('preselectedPackages', () => {
    it('selects every package the collection offers', () => {
        const packages = ['a', 'b'].map((uuid) => ({
            uuid,
            name: uuid,
            source_id: uuid,
            sha256: uuid.repeat(64),
            size: 10,
            created_at: null,
            older: 0,
        }));
        expect(
            preselectedPackages({
                uuid: 'p',
                name: 'P',
                completed_at: null,
                packages,
            }),
        ).toEqual(['a', 'b']);
        expect(preselectedPackages(undefined)).toEqual([]);
    });
});

describe('packageNameFor', () => {
    it('derives a name the Recolector accepts', () => {
        expect(packageNameFor('Moodle Pregrado', 1)).toBe('moodle-pregrado');
        expect(packageNameFor('Educación Continua', 2)).toBe(
            'educacion-continua',
        );
        expect(packageNameFor('2024 Posgrado!', 3)).toBe('posgrado');
        expect(packageNameFor('***', 4)).toBe('origen-4');
        expect(packageNameFor('a'.repeat(80), 5)).toHaveLength(64);
    });
});

describe('linkedSourcesProblem', () => {
    it('accepts two complete, distinct sources', () => {
        expect(
            linkedSourcesProblem(
                [
                    source(),
                    source({
                        name: 'Posgrado',
                        instance_id: 'i-0fedcba9876543210',
                        source_profile: 'posgrado',
                        package_name: 'posgrado',
                    }),
                ],
                2,
                8,
            ),
        ).toBeNull();
    });

    it('explains what is missing', () => {
        expect(linkedSourcesProblem([source()], 2, 8)).toContain('al menos 2');
        expect(
            linkedSourcesProblem(
                [source(), blankLinkedSource(2, 'us-east-2')],
                2,
                8,
            ),
        ).toBe('Complete todos los datos del origen 2.');
        expect(
            linkedSourcesProblem(
                [source(), source({ name: 'Otro', package_name: 'otro' })],
                2,
                8,
            ),
        ).toContain('ID de instancia');
        expect(
            linkedSourcesProblem(
                [source(), source({ name: 'Otro', instance_id: 'i-1' })],
                2,
                8,
            ),
        ).toContain('nombre de paquete');
    });
});

describe('formatSize', () => {
    it('uses binary units', () => {
        expect(formatSize(4096)).toBe('4 KiB');
        expect(formatSize(5 * 1048576)).toBe('5.0 MiB');
        expect(formatSize(3 * 1073741824)).toBe('3.0 GiB');
    });
});
