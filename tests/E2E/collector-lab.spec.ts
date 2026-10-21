import { spawn } from 'node:child_process';
import { createHash, randomUUID } from 'node:crypto';
import { createInterface } from 'node:readline';
import type { Page } from '@playwright/test';
import {
    test,
    expect,
    login,
    snapshot,
    control,
    executionPath,
    request,
    expireAuthorization,
} from './helpers';

test.describe('Recolector 7.4.2 real en Moodle sintético', () => {
    test.skip(
        process.env.QUALITY_COLLECTOR_LAB !== 'true',
        'Requiere el overlay explícito COLLECT LAB.',
    );
    test.setTimeout(360_000);

    // CONNECTED only lasts while the export runs, and the 2-course synthetic
    // export can finish before a 20 s poll observes it (run 39298199232). Launch
    // is proven by the operation's started_at, which never reverts.
    async function launched(project: string) {
        await expect
            .poll(
                () =>
                    snapshot(project).execution.collector?.operation
                        ?.started_at,
                { timeout: 20_000 },
            )
            .toEqual(expect.any(String));

        return snapshot(project).execution.collector!.operation!.started_at!;
    }

    // Scenarios that need a live export hold it on a LAB table lock instead of
    // racing its speed: see tests/Support/collector-lab-hold.php.
    async function holdExport() {
        const holder = spawn(
            'runuser',
            [
                '-u',
                'www-data',
                '--',
                'php',
                'tests/Support/collector-lab-hold.php',
            ],
            { stdio: ['pipe', 'pipe', 'inherit'] },
        );
        const states = new Set<string>();
        const listeners = new Set<() => void>();
        let closed: number | null | undefined;
        createInterface({ input: holder.stdout }).on('line', (line) => {
            Object.keys(JSON.parse(line)).forEach((state) => states.add(state));
            listeners.forEach((listener) => listener());
        });
        holder.once('close', (code) => {
            closed = code;
            listeners.forEach((listener) => listener());
        });
        const reached = (state: string) =>
            new Promise<void>((resolve, reject) => {
                const check = () => {
                    if (states.has(state)) {
                        listeners.delete(check);
                        resolve();
                    } else if (closed !== undefined) {
                        listeners.delete(check);
                        reject(
                            new Error(
                                `La retención LAB terminó (${closed}) antes de ${state}.`,
                            ),
                        );
                    }
                };
                listeners.add(check);
                check();
            });
        await reached('held');

        return {
            // An export connection waits on the lock: it is alive and cannot finish.
            blocked: () => reached('blocked'),
            release: async () => {
                holder.stdin.end();
                await reached('released');
            },
        };
    }

    async function startLaboratory(page: Page) {
        await page.goto('/projects');
        await page
            .locator('#project-name')
            .fill('COLLECT real desde navegador');
        await page.locator('#project-type').selectOption('COLLECT');
        await page.getByRole('button', { name: 'Crear y configurar' }).click();
        await expect(page).toHaveURL(/\/projects\/[a-f0-9-]+$/);
        const project = page.url().split('/').at(-1)!;
        await page.locator('#collector-name').fill('browser-proof');
        await page.locator('#collector-capacity').fill('256');
        await page
            .getByRole('button', { name: 'Guardar configuración LAB' })
            .click();
        await expect(
            page.getByText('LABORATORY', { exact: true }),
        ).toBeVisible();
        await page.reload();
        await expect(page.locator('#collector-name')).toHaveValue(
            'browser-proof',
        );
        await page
            .getByRole('button', { name: 'Ejecutar preflight real' })
            .click();
        await page
            .getByRole('checkbox', {
                name: 'Acepto: Uso exclusivo de laboratorio',
            })
            .check();
        await page
            .getByRole('button', { name: 'Confirmar configuración' })
            .click();
        await page
            .getByRole('button', { name: 'Iniciar recolección LAB' })
            .click();
        await expect(page).toHaveURL(/\/executions\//);
        const execution = page.url().split('/').at(-1)!;
        const started = await launched(project);
        expect(snapshot(project).execution_count).toBe(1);
        return { project, execution, started };
    }

    test('configuración, exportación, sesión independiente, polling, artefactos y cierre real', async ({
        page,
        context,
        password,
    }) => {
        await page.routeWebSocket(/\/app\//, (socket) => socket.close());
        await login(page, password);
        const { project, execution, started } = await startLaboratory(page);
        await expect(
            page.getByText('Recuperación activa', { exact: true }),
        ).toBeVisible();
        await page.close();
        await context.clearCookies();
        const observer = await context.newPage();
        await observer.routeWebSocket(/\/app\//, (socket) => socket.close());
        await login(observer, password);
        await observer.goto(executionPath(project, execution));
        await expect(
            observer.getByText('REVIEW', { exact: true }).first(),
        ).toBeVisible({ timeout: 150_000 });
        await expect(
            observer.getByText('Paquete fuente auditado', { exact: true }),
        ).toBeVisible();
        await expect(
            observer.getByText('Revisión académica simulada', { exact: true }),
        ).toHaveCount(0);
        const reviewed = snapshot(project);
        // This observer is slower than the export, as in run 39298199232: the
        // transient CONNECTED is gone while the launch evidence remains.
        expect(
            reviewed.execution.collector?.operation?.communication_state,
        ).toBe('TERMINATED');
        expect(reviewed.execution.collector?.operation?.started_at).toBe(
            started,
        );
        expect(reviewed.review.source_packages).toHaveLength(1);
        expect(reviewed.review.source_packages[0].producer_version).toBe(
            '7.4.2-linux',
        );
        expect(reviewed.review.source_packages[0].courses).toBe(2);
        expect(reviewed.review.artifacts).toHaveLength(6);
        expect(reviewed.execution.checkpoints).toHaveLength(0);
        expect(reviewed.execution.progress).toBeNull();
        const artifact = reviewed.review.artifacts.find(
            (item) => item.type === 'source_package',
        )!;
        const download = await observer.request.get(
            `${executionPath(project, execution)}/artifacts/${artifact.id}/download?key=${randomUUID()}`,
        );
        expect(download.status()).toBe(200);
        expect(
            createHash('sha256')
                .update(await download.body())
                .digest('hex'),
        ).toBe(artifact.sha256);
        await observer.reload();
        await expect(observer.getByText('SourcePackage · VALID')).toBeVisible();
        await expireAuthorization(observer);
        await observer
            .getByRole('button', { name: 'Finalizar', exact: true })
            .click();
        await expect(
            observer.locator('#action-confirmation-password'),
        ).toBeVisible();
        expect(snapshot(project).execution.status).toBe('REVIEW');
        await observer.locator('#action-confirmation-password').fill(password);
        await observer
            .getByRole('button', { name: 'Confirmar y reintentar' })
            .click();
        await expect(
            observer.getByText('COMPLETED', { exact: true }).first(),
        ).toBeVisible({ timeout: 60_000 });
        const completed = snapshot(project);
        expect(completed.review.artifacts).toHaveLength(10);
        expect(completed.execution_count).toBe(1);
        expect(
            completed.commands.filter(
                (command) => command.command_type === 'FINALIZE',
            ),
        ).toHaveLength(1);
        expect(
            new Set(completed.events.map((event) => event.sequence)).size,
        ).toBe(completed.events.length);
        await observer.close();
    });

    test('capacidad consumida, nueva Execution con linaje y cancelación desde la misma pantalla', async ({
        page,
        password,
    }) => {
        await login(page, password);
        const capacity = await holdExport();
        const { project, execution } = await startLaboratory(page);
        await capacity.blocked();
        // Held on the lock, the export is alive: the bridge (every 1 s) and the
        // runner (heartbeat and after exit) measure the fault before it can exit.
        expect(
            snapshot(project).execution.collector?.operation
                ?.communication_state,
        ).toBe('CONNECTED');
        expect(control('lab-capacity-fault', { execution })).toEqual({
            injected: true,
        });
        await capacity.release();
        await expect(
            page.getByText('FAILED', { exact: true }).first(),
        ).toBeVisible({ timeout: 150_000 });
        const failed = snapshot(project);
        const cancellation = await holdExport();
        await page
            .getByRole('button', { name: 'Nueva exportación LAB' })
            .click();
        await page
            .getByRole('checkbox', {
                name: 'Acepto una nueva exportación sobre Moodle sintético',
            })
            .check();
        await page
            .getByRole('button', { name: 'Confirmar nueva exportación' })
            .click();
        await expect(page).not.toHaveURL(new RegExp(`${execution}$`));
        const next = page.url().split('/').at(-1)!;
        expect(next).not.toBe(execution);
        await launched(project);
        await cancellation.blocked();
        expect(
            snapshot(project).execution.collector?.operation
                ?.communication_state,
        ).toBe('CONNECTED');
        expect(snapshot(project).execution.retried_from_execution_uuid).toBe(
            execution,
        );
        expect(snapshot(project).workspace).not.toBe(failed.workspace);
        const prior = snapshot(project, execution);
        expect(prior.execution.status).toBe('FAILED');
        expect(prior.events).toEqual(failed.events);
        await page
            .getByRole('button', { name: 'Solicitar cancelación' })
            .click();
        // CANCELLING is committed with the request and closes any later exit as a
        // cancellation, so the export may only continue once it is durable.
        await expect
            .poll(() => snapshot(project).execution.status, {
                timeout: 20_000,
            })
            .toMatch(/^CANCELL(ING|ED)$/);
        await cancellation.release();
        await expect(
            page.getByText('CANCELLED', { exact: true }).first(),
        ).toBeVisible({ timeout: 150_000 });
        const cancelled = snapshot(project);
        expect(cancelled.execution_count).toBe(2);
        expect(cancelled.execution.checkpoints).toHaveLength(0);
        expect(cancelled.events[0].sequence).toBe(1);
        const command = cancelled.commands.find(
            (item) => item.command_type === 'CANCEL',
        )!;
        expect(
            (
                await request(
                    page,
                    `${executionPath(project, next)}/cancel`,
                    {},
                    command.idempotency_key,
                )
            ).status(),
        ).toBe(200);
        expect(
            snapshot(project).commands.filter(
                (item) => item.command_type === 'CANCEL',
            ),
        ).toHaveLength(1);
    });
});
