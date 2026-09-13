import { test as base, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { randomBytes, randomUUID } from 'node:crypto';

export function control<T = Record<string, unknown>>(
    action: string,
    input: Record<string, unknown> = {},
): T {
    const output = execFileSync(
        'runuser',
        [
            '-u',
            'www-data',
            '--',
            'php',
            'tests/Support/quality-control.php',
            action,
        ],
        {
            input: JSON.stringify(input),
            encoding: 'utf8',
            timeout: 55_000,
        },
    );
    return JSON.parse(output.trim().split('\n').at(-1)!);
}

export const test = base.extend<{ password: string }>({
    password: async ({ browserName }, use) => {
        expect(browserName).toBe('chromium');
        const password = 'E2e9-' + randomBytes(24).toString('base64url');
        expect(control('reset', { password })).toEqual({ users: 6 });
        await use(password);
    },
});
export { expect };
export type Ready = { uuid: string; version: number };
export type Snapshot = {
    status: string;
    execution_count: number;
    workspace: string;
    configuration: {
        version: number;
        settings: {
            wizard: { current_step: number };
            options: Record<string, unknown>;
            preflight: { checks: Array<{ id: string; result: string }> };
        };
    };
    execution: {
        uuid: string;
        status: string;
        progress: number;
        last_event_sequence: number;
        started_at: string;
        finished_at: string;
        resumed_from_execution_uuid: string | null;
        checkpoints: Array<{ id: number }>;
        conflicts: Array<{
            id: number;
            version: number;
            allowed_decisions: string[];
        }>;
    };
    review: {
        read_only: boolean;
        proposal_version: number;
        fingerprint: string;
        validation_current: boolean;
        artifacts: Array<{
            id: number;
            sha256: string;
            filename: string;
            size: number;
        }>;
        completion_summary: Record<string, unknown>;
        tree: unknown[];
    };
    events: Array<{
        sequence: number;
        type: string;
        message: string;
        created_at: string;
    }>;
    commands: Array<{
        id: number;
        command_type: string;
        processed_at: string | null;
        idempotency_key: string;
    }>;
    audit: Array<{ action: string; payload: unknown }>;
    instances: Array<{
        uuid: string;
        name: string;
        server_id: number;
        server: { uuid: string; name: string; host: string };
        base_url: string;
        role: string;
        destination_kind: string | null;
        moodle_version: string;
        validated: boolean;
    }>;
    finalization: { completed_at: string; status: string };
};
export function snapshot(project: string, execution?: string) {
    return control<Snapshot>('snapshot', { project, execution });
}
export function ready(type = 'COLLECT', scenario = 'SUCCESS') {
    return control<Ready>('ready', { type, scenario });
}
export function worker(mode = 'drain', clock?: string) {
    const result = control<{ exit: number; pid: number; error: string }>(
        'worker',
        { mode, clock },
    );
    expect(result.exit, result.error).toBe(0);
    return result.pid;
}
export async function login(
    page: Page,
    password: string,
    user = 'admin',
    remember = false,
    expected = user === 'temporary' ? /\/settings\/security$/ : /\/dashboard$/,
) {
    await page.goto('/login');
    await page.getByLabel('Correo electrónico').fill(`${user}@quality.test`);
    await page.locator('#password').fill(password);
    if (remember) await page.getByLabel('Recordarme').check();
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await expect(page).toHaveURL(expected);
}
export async function request(
    page: Page,
    path: string,
    data: Record<string, unknown> = {},
    key = randomUUID(),
    method = 'POST',
) {
    const token = (await page.context().cookies()).find(
        (cookie) => cookie.name === 'XSRF-TOKEN',
    );
    return page.request.fetch(path, {
        method,
        data,
        maxRedirects: 0,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token?.value ?? ''),
            'Idempotency-Key': key,
        },
    });
}
export function executionPath(project: string, execution: string) {
    return `/projects/${project}/executions/${execution}`;
}
export async function start(page: Page, project: Ready) {
    await page.goto(`/projects/${project.uuid}`);
    await page
        .getByRole('button', { name: 'Iniciar ejecución', exact: true })
        .click();
    await expect(page).toHaveURL(/\/executions\//);
    const state = snapshot(project.uuid);
    expect(state.status).toBe('QUEUED');
    expect(state.execution_count).toBe(1);
    return state.execution.uuid;
}
export async function visibleStatus(page: Page, status: string) {
    await expect(page.getByText(status, { exact: true }).first()).toBeVisible();
}
export async function wizard(
    page: Page,
    type: string,
    scenario = 'SUCCESS',
    processing = 'SUCCESS',
) {
    await page.goto('/projects');
    await page.locator('#project-name').fill(`E2E ${type}`);
    await page.locator('#project-type').selectOption(type);
    await page.getByRole('button', { name: 'Crear y configurar' }).click();
    await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+$/);
    const uuid = page.url().split('/').at(-1)!;
    await page.reload();
    // Creating the project has already saved basics and advances to instances.
    await expect(page.locator('#server-host-0')).toBeVisible();
    await page.getByRole('button', { name: 'Atrás', exact: true }).click();
    await expect(page.locator('#wizard-name')).toHaveValue(`E2E ${type}`);
    await page.getByRole('button', { name: 'Guardar y continuar' }).click();
    const count = type === 'COLLECT' ? 1 : type === 'CONSOLIDATE' ? 3 : 2;
    const instanceDrafts = new Map<string, { host: string; url: string }>();
    for (let i = 0; i < count; i++) {
        await page.locator(`#server-host-${i}`).fill(`moodle-${i}.test`);
        await page.locator(`#base-url-${i}`).fill(`https://moodle-${i}.test`);
        instanceDrafts.set(
            await page.locator(`#instance-name-${i}`).inputValue(),
            {
                host: `moodle-${i}.test`,
                url: `https://moodle-${i}.test`,
            },
        );
    }
    await page.getByRole('button', { name: 'Guardar y continuar' }).click();
    await expect(page.locator('#simulation-scenario')).toBeVisible();
    await page.reload();
    await page.getByRole('button', { name: 'Atrás', exact: true }).click();
    // Persistence orders by role; match each instance by its name, not its position.
    await expect(page.locator('input[id^="base-url-"]')).toHaveCount(count);
    for (let i = 0; i < count; i++) {
        const draft = instanceDrafts.get(
            await page.locator(`#instance-name-${i}`).inputValue(),
        );
        expect(draft).toBeDefined();
        await expect(page.locator(`#base-url-${i}`)).toHaveValue(draft!.url);
        await expect(page.locator(`#server-host-${i}`)).toHaveValue(
            draft!.host,
        );
    }
    await page.getByRole('button', { name: 'Guardar y continuar' }).click();
    await page.locator('#simulation-scenario').selectOption(scenario);
    await page.locator('#processing-scenario').selectOption(processing);
    if (type === 'COLLECT')
        await page.locator('#artifact-name').fill('paquete-e2e');
    await page.getByRole('button', { name: 'Guardar y continuar' }).click();
    await expect(
        page.getByRole('button', { name: 'Ejecutar preflight', exact: true }),
    ).toBeVisible();
    await page.reload();
    await page
        .getByRole('button', { name: 'Ejecutar preflight', exact: true })
        .click();
    await expect(
        page.getByText('5. Confirmación', { exact: true }),
    ).toBeVisible();
    return uuid;
}
