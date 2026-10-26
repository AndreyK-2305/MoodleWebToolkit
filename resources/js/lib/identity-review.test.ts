import { describe, expect, it } from 'vitest';
import {
    chooseCanonical,
    decisionGap,
    evidentCases,
    defaultUsername,
    identityFacts,
    identityMembers,
    initialDecision,
    isEvidentMatch,
    keepsDuplicateEmail,
    matchReasons,
    memberLabel,
    mergeEvident,
} from './identity-review';
import type { IdentityCandidate } from './identity-review';

const candidate = (
    changes: Partial<IdentityCandidate> = {},
): IdentityCandidate => ({
    group: 'CAND-0123456789ABCDEF',
    type: 'POSSIBLE_IDENTITY_MATCH',
    confidence: 'HIGH',
    name_similarity: '1.0000',
    email_similarity: '0.6429',
    identifier_match: 'false',
    evidence: 'google_sub=missing',
    a: {
        source: 'pregrado',
        user_id: '3',
        canonical_id: 'C-1',
        name: 'Laura Gómez',
        email: 'laura.gomez@uni-lab.example',
        google: 'missing',
        members: ['pregrado:3'],
        accounts: [
            {
                key: 'pregrado:3',
                name: 'Laura Gómez',
                email: 'laura.gomez@uni-lab.example',
                username: 'lgomez',
                document: '…5432',
            },
        ],
    },
    b: {
        source: 'posgrado',
        user_id: '19',
        canonical_id: 'C-2',
        name: 'Laura Gomez',
        email: 'lgomez@posgrado-lab.example',
        google: 'missing',
        members: ['posgrado:19'],
        accounts: [
            {
                key: 'posgrado:19',
                name: 'Laura Gomez',
                email: 'lgomez@posgrado-lab.example',
                username: 'laura.posgrado',
                document: '…9876',
            },
        ],
    },
    ...changes,
});

describe('identity review', () => {
    it('starts a merge from the first account with its own username, not its e-mail', () => {
        const decision = initialDecision(candidate());

        expect(decision).toMatchObject({
            canonical_target: 'pregrado:3',
            canonical_email: 'laura.gomez@uni-lab.example',
            canonical_username: 'lgomez',
        });
    });

    it('falls back to the part of the e-mail before the @ when the agent has no username', () => {
        const old = candidate();
        old.a.accounts = undefined;

        expect(initialDecision(old).canonical_username).toBe('laura.gomez');
        expect(defaultUsername(undefined)).toBe('');
    });

    it('brings the e-mail and username of the chosen canonical account along', () => {
        const group = candidate();
        const members = identityMembers(group);
        const chosen = chooseCanonical(
            initialDecision(group),
            members,
            'posgrado:19',
        );

        expect(chosen).toMatchObject({
            canonical_target: 'posgrado:19',
            canonical_email: 'lgomez@posgrado-lab.example',
            canonical_username: 'laura.posgrado',
        });
    });

    it('keeps what the administrator wrote when the canonical account changes', () => {
        const group = candidate();
        const members = identityMembers(group);
        const edited = {
            ...initialDecision(group),
            canonical_email: 'laura@uni-lab.example',
        };
        const chosen = chooseCanonical(edited, members, 'posgrado:19');

        expect(chosen.canonical_email).toBe('laura@uni-lab.example');
        expect(chosen.canonical_username).toBe('laura.posgrado');
    });

    it('describes each account by name and e-mail, never by its id', () => {
        const labels = identityMembers(candidate()).map(memberLabel);

        expect(labels).toEqual([
            'Laura Gómez · laura.gomez@uni-lab.example (pregrado)',
            'Laura Gomez · lgomez@posgrado-lab.example (posgrado)',
        ]);
        expect(labels.join(' ')).not.toContain(':19');
    });

    it('shows what differs and explains the match in words', () => {
        const facts = identityFacts(candidate());

        expect(facts.find((fact) => fact.label === 'Correo')?.same).toBe(false);
        expect(facts.find((fact) => fact.label === 'Documento')).toMatchObject({
            a: 'termina en 5432',
            same: false,
        });
        expect(matchReasons(candidate())).toEqual(['tienen el mismo nombre']);
    });

    it('offers the evident groups for a bulk merge only with the same e-mail and document', () => {
        const same = candidate({ identifier_match: 'true' });
        same.b.email = 'Laura.Gomez@uni-lab.example';

        expect(isEvidentMatch(same)).toBe(true);
        expect(isEvidentMatch(candidate())).toBe(false);
        expect(isEvidentMatch({ ...same, confidence: 'MEDIUM' })).toBe(false);
    });

    it('treats the same e-mail, username and name without documents as evident (2026-10-25: Synthetic User 1)', () => {
        const same = candidate();
        same.b = {
            ...same.a,
            source: 'posgrado',
            members: ['posgrado:19'],
            accounts: same.a.accounts!.map((account) => ({
                ...account,
                key: 'posgrado:19',
                document: null,
            })),
        };
        same.a.accounts = same.a.accounts!.map((account) => ({
            ...account,
            document: null,
        }));

        expect(isEvidentMatch(same)).toBe(true);
        // Another username, or two different documents, still needs a person.
        const otherUser = structuredClone(same);
        otherUser.b.accounts![0].username = 'laura.posgrado';
        expect(isEvidentMatch(otherUser)).toBe(false);
        const otherDocument = structuredClone(same);
        otherDocument.a.accounts![0].document = '…5432';
        otherDocument.b.accounts![0].document = '…9876';
        expect(isEvidentMatch(otherDocument)).toBe(false);
    });

    it('offers the bulk merge for a single evident case too (2026-10-24: Laura Gómez, …1006)', () => {
        const evident = candidate({
            group: 'CAND-LAURA',
            identifier_match: 'true',
        });
        evident.b.email = evident.a.email;
        const doubtful = candidate({ group: 'CAND-OTHER' });
        const groups = [doubtful, evident];
        const decisions = groups.map(initialDecision);

        const found = evidentCases(groups, decisions);
        expect(found.map(({ index }) => index)).toEqual([1]);

        const merged = mergeEvident(
            decisions,
            found,
            '  Mismo correo y documento ',
        );
        expect(merged[0]).toEqual(decisions[0]);
        expect(merged[1]).toMatchObject({
            resolution: 'MERGE',
            justification: 'Mismo correo y documento',
            canonical_target: 'pregrado:3',
            canonical_email: 'laura.gomez@uni-lab.example',
            canonical_username: 'lgomez',
        });
        expect(decisionGap(merged[1])).toBeNull();
        // Once decided, the case leaves the bulk action.
        expect(evidentCases(groups, merged)).toEqual([]);
    });

    it('says what a decision lacks and warns when two accounts keep one e-mail', () => {
        const group = candidate();
        group.b.email = group.a.email;
        const decision = initialDecision(group);

        expect(decisionGap(decision)).toBe('falta elegir una opción');
        expect(decisionGap({ ...decision, resolution: 'MERGE' })).toBe(
            'falta el motivo de la decisión',
        );
        expect(
            decisionGap({
                ...decision,
                resolution: 'MERGE',
                justification: 'Misma persona',
            }),
        ).toBeNull();
        expect(
            keepsDuplicateEmail(group, { ...decision, resolution: 'IGNORE' }),
        ).toBe(true);
        expect(
            keepsDuplicateEmail(group, { ...decision, resolution: 'MERGE' }),
        ).toBe(false);
    });
});
