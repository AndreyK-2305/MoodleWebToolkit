import { randomUUID } from 'node:crypto';
import {
    test,
    expect,
    login,
    ready,
    start,
    worker,
    snapshot,
    request,
    executionPath,
    visibleStatus,
} from './helpers';

test('review rechaza ciclos, conserva formulario y permite validar cambios opcionales', async ({
    page,
    password,
}) => {
    await login(page, password);
    const project = ready();
    const execution = await start(page, project);
    worker();
    await visibleStatus(page, 'REVIEW');
    const before = snapshot(project.uuid);
    const path = executionPath(project.uuid, execution);
    expect(
        (
            await request(page, `${path}/proposals`, {
                operation: 'MOVE_CATEGORY',
                node_id: 'cat:collection-root',
                value: 'cat:collection-academic',
                expected_version: 0,
                base_fingerprint: before.review.fingerprint,
            })
        ).status(),
    ).toBe(422);
    expect(snapshot(project.uuid).review.proposal_version).toBe(0);
    await page.locator('#proposal-operation').selectOption('RENAME_CATEGORY');
    await page
        .locator('#proposal-node')
        .selectOption('cat:collection-academic');
    await page.locator('#proposal-value').fill('Oferta académica 1G');
    await page.getByRole('button', { name: 'Guardar propuesta' }).click();
    await expect
        .poll(() => snapshot(project.uuid).review.proposal_version)
        .toBe(1);
    expect(snapshot(project.uuid).review.validation_current).toBe(false);
    await expect(
        page.getByRole('button', { name: 'Finalizar', exact: true }),
    ).toHaveCount(0);
    await page.reload();
    await expect(
        page.getByText('Oferta académica 1G', { exact: true }).first(),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Validar ajustes' }).click();
    await visibleStatus(page, 'VERIFYING');
    worker();
    await visibleStatus(page, 'REVIEW');
    expect(snapshot(project.uuid).review.validation_current).toBe(true);
    const key = randomUUID();
    const result = await Promise.all([
        request(page, `${path}/finalize`, {}, key),
        request(page, `${path}/finalize`, {}, key),
    ]);
    expect(result.map((r) => r.status()).sort((a, b) => a - b)).toEqual([
        200, 202,
    ]);
    worker('once');
    const partial = snapshot(project.uuid);
    expect(partial.status).not.toBe('COMPLETED');
    expect(
        partial.commands.filter((c) => c.command_type === 'FINALIZE'),
    ).toHaveLength(1);
    worker();
    expect(snapshot(project.uuid).review.artifacts).toHaveLength(4);
    await expect(
        page.getByText('Proyecto completado · sólo lectura'),
    ).toBeVisible();
});

test('doble clic de inicio desde React no duplica el intento', async ({
    page,
    password,
}) => {
    await login(page, password);
    const project = ready();
    await page.goto(`/projects/${project.uuid}`);
    await page
        .getByRole('button', { name: 'Iniciar ejecución', exact: true })
        .evaluate((button) => {
            button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
            button.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        });
    await expect(page).toHaveURL(/\/executions\//);
    expect(snapshot(project.uuid).execution_count).toBe(1);
    expect(snapshot(project.uuid).commands).toHaveLength(1);
    worker();
    await visibleStatus(page, 'REVIEW');
});
