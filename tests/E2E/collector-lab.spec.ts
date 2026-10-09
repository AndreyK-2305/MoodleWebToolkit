import { createHash, randomUUID } from 'node:crypto';
import type { Page } from '@playwright/test';
import {
    test,
    expect,
    login,
    snapshot,
    control,
    executionPath,
    request,
} from './helpers';

test.describe('Recolector 7.4.2 real en Moodle sintético', () => {
    test.skip(
        process.env.QUALITY_COLLECTOR_LAB !== 'true',
        'Requiere el overlay explícito COLLECT LAB.',
    );
    test.setTimeout(360_000);

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
        await expect
            .poll(
                () =>
                    snapshot(project).execution.collector?.operation
                        ?.communication_state,
                { timeout: 20_000 },
            )
            .toBe('CONNECTED');
        expect(snapshot(project).execution_count).toBe(1);
        return { project, execution };
    }

    test('configuración, exportación, sesión independiente, polling, artefactos y cierre real', async ({
        page,
        context,
        password,
    }) => {
        await page.routeWebSocket(/\/app\//, (socket) => socket.close());
        await login(page, password);
        const { project, execution } = await startLaboratory(page);
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
        control('expire');
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
        const { project, execution } = await startLaboratory(page);
        expect(control('lab-capacity-fault', { execution })).toEqual({
            injected: true,
        });
        await expect(
            page.getByText('FAILED', { exact: true }).first(),
        ).toBeVisible({ timeout: 150_000 });
        const failed = snapshot(project);
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
        await expect
            .poll(
                () =>
                    snapshot(project).execution.collector?.operation
                        ?.communication_state,
                { timeout: 20_000 },
            )
            .toBe('CONNECTED');
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
