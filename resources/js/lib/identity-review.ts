/**
 * The fuzzy identity review in plain words: which accounts a group joins, what
 * differs between them, the defaults of a merge and what is still missing
 * before the review can be applied.
 */

export type IdentityMemberData = {
    key: string;
    name: string | null;
    email: string | null;
    username: string | null;
    document: string | null;
};

export type IdentitySide = {
    source: string | null;
    user_id: string | null;
    canonical_id: string | null;
    name: string | null;
    email: string | null;
    google: string | null;
    members: string[];
    /** Agent 1.14+: each member's existing username and document ending. */
    accounts?: IdentityMemberData[];
};

export type IdentityCandidate = {
    group: string;
    type: string | null;
    confidence: string | null;
    name_similarity: string | null;
    email_similarity: string | null;
    identifier_match: string | null;
    evidence: string | null;
    a: IdentitySide;
    b: IdentitySide;
};

export type IdentityResolution = '' | 'MERGE' | 'KEEP_SEPARATE' | 'IGNORE';

export type IdentityDecision = {
    group: string;
    resolution: IdentityResolution;
    canonical_target: string;
    canonical_email: string;
    canonical_username: string;
    oauth_policy: 'canonical_account' | 'none';
    justification: string;
};

export type IdentityMember = IdentityMemberData & {
    /** The source the account comes from, e.g. «pregrado». */
    source: string;
    google: string | null;
};

export const resolutionLabels: Record<
    Exclude<IdentityResolution, ''>,
    { label: string; help: string }
> = {
    MERGE: {
        label: 'Es la misma persona: unir',
        help: 'En el Moodle consolidado queda una sola cuenta, con el correo y el usuario que elija abajo. Sus cursos y calificaciones de ambos orígenes quedan juntos.',
    },
    KEEP_SEPARATE: {
        label: 'Son personas distintas',
        help: 'Quedan dos cuentas. Se registra que usted revisó el caso y que no son la misma persona.',
    },
    IGNORE: {
        label: 'Dejar como está, sin unir',
        help: 'No cambia nada: quedan dos cuentas, como si no se hubiera detectado la coincidencia. Úselo solo si no puede decidirlo ahora.',
    },
};

const sameText = (left: string | null, right: string | null) =>
    (left ?? '').trim().toLocaleLowerCase('es') ===
    (right ?? '').trim().toLocaleLowerCase('es');

/** Every account of the group, with what the agent knows of it. */
export function identityMembers(
    candidate: IdentityCandidate,
): IdentityMember[] {
    const members: IdentityMember[] = [];
    for (const side of [candidate.a, candidate.b]) {
        const keys = side.members.length > 0 ? side.members : [];
        keys.forEach((key, position) => {
            if (members.some((member) => member.key === key)) {
                return;
            }
            const known = side.accounts?.find((account) => account.key === key);
            // Without the agent's details, the side's own name and e-mail describe its first account.
            const fallback = position === 0 ? side : null;
            members.push({
                key,
                source: key.split(':')[0] ?? side.source ?? '',
                name: known?.name ?? fallback?.name ?? null,
                email: known?.email ?? fallback?.email ?? null,
                username: known?.username ?? null,
                document: known?.document ?? null,
                google: fallback?.google ?? null,
            });
        });
    }
    return members;
}

/** How a person reads an account: name, e-mail and where it comes from; never its id. */
export function memberLabel(member: IdentityMember): string {
    const name = member.name ?? 'Sin nombre';
    const email = member.email ?? 'sin correo';
    return `${name} · ${email} (${member.source})`;
}

/** The username a merge keeps by default: the account's own; failing that, the part of its e-mail before the @. */
export function defaultUsername(member: IdentityMember | undefined): string {
    const username = (member?.username ?? '').trim().toLowerCase();
    if (username !== '') {
        return username;
    }
    const local = (member?.email ?? '').split('@')[0] ?? '';
    return local
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9._-]/g, '');
}

export function initialDecision(
    candidate: IdentityCandidate,
): IdentityDecision {
    const first = identityMembers(candidate)[0];
    return {
        group: candidate.group,
        resolution: '',
        canonical_target: first?.key ?? '',
        canonical_email: (first?.email ?? '').trim().toLowerCase(),
        canonical_username: defaultUsername(first),
        oauth_policy: 'canonical_account',
        justification: '',
    };
}

/**
 * Choosing another canonical account brings its e-mail and username along,
 * unless the administrator already wrote their own.
 */
export function chooseCanonical(
    decision: IdentityDecision,
    members: IdentityMember[],
    key: string,
): IdentityDecision {
    const previous = members.find(
        (member) => member.key === decision.canonical_target,
    );
    const next = members.find((member) => member.key === key);
    const previousEmail = (previous?.email ?? '').trim().toLowerCase();
    const emailEdited =
        decision.canonical_email.trim().toLowerCase() !== previousEmail;
    const usernameEdited =
        decision.canonical_username !== defaultUsername(previous);
    return {
        ...decision,
        canonical_target: key,
        canonical_email: emailEdited
            ? decision.canonical_email
            : (next?.email ?? '').trim().toLowerCase(),
        canonical_username: usernameEdited
            ? decision.canonical_username
            : defaultUsername(next),
    };
}

export type IdentityFact = {
    label: string;
    a: string;
    b: string;
    same: boolean;
};

/** Side by side, what matches and what differs between the two accounts. */
export function identityFacts(candidate: IdentityCandidate): IdentityFact[] {
    const [a, b] = [candidate.a, candidate.b].map((side) =>
        side.accounts?.find((account) => account.key === side.members[0]),
    );
    const google = (side: IdentitySide) =>
        side.google === 'verified' ? 'Vinculada' : 'Sin vincular';
    const facts: IdentityFact[] = [
        {
            label: 'Nombre',
            a: candidate.a.name ?? 'Sin nombre',
            b: candidate.b.name ?? 'Sin nombre',
            same: sameText(candidate.a.name, candidate.b.name),
        },
        {
            label: 'Correo',
            a: candidate.a.email ?? 'Sin correo',
            b: candidate.b.email ?? 'Sin correo',
            same: sameText(candidate.a.email, candidate.b.email),
        },
    ];
    if (a?.username || b?.username) {
        facts.push({
            label: 'Usuario',
            a: a?.username ?? '—',
            b: b?.username ?? '—',
            same: sameText(a?.username ?? null, b?.username ?? null),
        });
    }
    const documentMatch = candidate.identifier_match === 'true';
    if (documentMatch || a?.document || b?.document) {
        facts.push({
            label: 'Documento',
            a: a?.document
                ? `termina en ${a.document.replace('…', '')}`
                : 'Sin documento',
            b: b?.document
                ? `termina en ${b.document.replace('…', '')}`
                : 'Sin documento',
            same: documentMatch,
        });
    }
    facts.push({
        label: 'Cuenta de Google',
        a: google(candidate.a),
        b: google(candidate.b),
        same: candidate.a.google === candidate.b.google,
    });
    facts.push({
        label: 'Origen',
        a: candidate.a.source ?? '—',
        b: candidate.b.source ?? '—',
        same: false,
    });
    return facts;
}

export function confidenceLabel(confidence: string | null): string {
    switch ((confidence ?? '').toUpperCase()) {
        case 'HIGH':
            return 'Coincidencia alta';
        case 'MEDIUM':
            return 'Coincidencia media';
        case 'LOW':
            return 'Coincidencia baja';
        default:
            return 'Posible coincidencia';
    }
}

/** Why the Consolidator paired the two accounts, in words. */
export function matchReasons(candidate: IdentityCandidate): string[] {
    const reasons: string[] = [];
    const score = (value: string | null) => Number.parseFloat(value ?? '');
    if (candidate.identifier_match === 'true') {
        reasons.push('tienen el mismo documento de identidad');
    }
    if (sameText(candidate.a.email, candidate.b.email)) {
        reasons.push('tienen el mismo correo');
    } else if (score(candidate.email_similarity) >= 0.76) {
        reasons.push('sus correos se parecen');
    }
    const name = score(candidate.name_similarity);
    if (sameText(candidate.a.name, candidate.b.name) || name >= 0.99) {
        reasons.push('tienen el mismo nombre');
    } else if (name >= 0.84) {
        reasons.push('sus nombres son muy parecidos');
    } else if (name >= 0.72) {
        reasons.push('sus nombres se parecen');
    }
    return reasons;
}

/** A group a person would merge without hesitation: high confidence, same e-mail and same document. */
export function isEvidentMatch(candidate: IdentityCandidate): boolean {
    return (
        (candidate.confidence ?? '').toUpperCase() === 'HIGH' &&
        candidate.identifier_match === 'true' &&
        (candidate.a.email ?? '') !== '' &&
        sameText(candidate.a.email, candidate.b.email)
    );
}

export const MIN_JUSTIFICATION = 3;

/** What a decision still lacks, or null when it is complete. */
export function decisionGap(decision: IdentityDecision): string | null {
    if (decision.resolution === '') {
        return 'falta elegir una opción';
    }
    if (decision.resolution === 'MERGE') {
        if (decision.canonical_target === '') {
            return 'falta elegir la cuenta que se conserva';
        }
        if (
            !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(decision.canonical_email.trim())
        ) {
            return 'el correo de la cuenta unida no es válido';
        }
        if (!/^[a-z0-9._@-]{1,100}$/.test(decision.canonical_username)) {
            return 'el usuario de la cuenta unida solo admite minúsculas, números y . _ - @';
        }
    }
    if (decision.justification.trim().length < MIN_JUSTIFICATION) {
        return 'falta el motivo de la decisión';
    }
    return null;
}

/** Two accounts with the same e-mail stay apart: worth a warning before deciding so. */
export function keepsDuplicateEmail(
    candidate: IdentityCandidate,
    decision: IdentityDecision,
): boolean {
    return (
        (decision.resolution === 'KEEP_SEPARATE' ||
            decision.resolution === 'IGNORE') &&
        (candidate.a.email ?? '') !== '' &&
        sameText(candidate.a.email, candidate.b.email)
    );
}
