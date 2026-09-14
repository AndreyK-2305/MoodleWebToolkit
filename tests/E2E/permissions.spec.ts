import { randomUUID } from 'node:crypto';
import {
    test,
    expect,
    login,
    ready,
    worker,
    snapshot,
    request,
    executionPath,
    control,
} from './helpers';

for (const role of ['admin', 'operator', 'outsider', 'auditor']) {
    test(`matriz HTTP y navegador: ${role}`, async ({ page, password }) => {
        await login(page, password, role);
        const canView = role !== 'outsider';
        const canModify = role === 'admin' || role === 'operator';
        const project = ready();
        expect((await page.goto(`/projects/${project.uuid}`))?.status()).toBe(
            canView ? 200 : 403,
        );
        const state = snapshot(project.uuid);
        expect(
            (
                await request(
                    page,
                    `/projects/${project.uuid}/wizard/basics`,
                    { name: 'Cambio autorizado', type: 'COLLECT' },
                    undefined,
                    'PATCH',
                )
            ).status(),
        ).toBe(canModify ? 302 : 403);
        // A separate READY project keeps configuration and execution independent.
        const executable = ready();
        expect(
            (
                await request(page, `/projects/${executable.uuid}/executions`, {
                    configuration_version: executable.version,
                })
            ).status(),
        ).toBe(canModify ? 201 : 403);
        expect(snapshot(executable.uuid).execution_count).toBe(
            canModify ? 1 : 0,
        );
        expect(
            (
                await page.request.get('/admin/users', { maxRedirects: 0 })
            ).status(),
        ).toBe(role === 'admin' ? 200 : 403);
        expect(
            (
                await request(page, '/admin/users', {
                    name: 'E2E additional',
                    email: 'additional@quality.test',
                    role: 'OPERATOR',
                    password,
                    password_confirmation: password,
                })
            ).status(),
        ).toBe(role === 'admin' ? 302 : 403);
        expect(state.execution_count).toBe(0);

        for (const scenario of ['WARNING', 'FAILURE', 'SUCCESS']) {
            const item = ready('COLLECT', scenario);
            const execution = control<{ uuid: string }>('start', {
                project: item.uuid,
            }).uuid;
            worker();
            const current = snapshot(item.uuid);
            const path = executionPath(item.uuid, execution);
            expect((await page.goto(path))?.status()).toBe(canView ? 200 : 403);
            if (!canModify)
                await expect(
                    page.getByRole('button', {
                        name: /Cancelar ejecución|Finalizar|Aceptar advertencia|Reanudar desde/,
                    }),
                ).toHaveCount(0);
            expect(
                (await page.request.get(`${path}/events?after=0`)).status(),
            ).toBe(canView ? 200 : 403);
            // Reverb authorization is tested by an actual HTTP subscription request.
            if (canView) {
                const initialPage = await page
                    .locator('script[type="application/json"][data-page="app"]')
                    .textContent();
                const channel: string = JSON.parse(initialPage!).props
                    .realtimeChannel;
                expect(channel).toBeTruthy();
                expect(
                    (
                        await request(page, '/broadcasting/auth', {
                            socket_id: '123.456',
                            channel_name: `private-${channel}`,
                        })
                    ).status(),
                ).toBe(200);
            }
            expect(
                (
                    await request(page, '/broadcasting/auth', {
                        socket_id: '123.456',
                        channel_name: 'private-projects.forged.session.forged',
                    })
                ).status(),
            ).toBe(403);
            if (scenario === 'WARNING') {
                const conflict = current.execution.conflicts[0];
                expect(
                    (
                        await request(
                            page,
                            `${path}/conflicts/${conflict.id}/resolve`,
                            {
                                decision: 'ACCEPT',
                                conflict_version: conflict.version,
                            },
                        )
                    ).status(),
                ).toBe(canModify ? 200 : 403);
                expect((await request(page, `${path}/cancel`)).status()).toBe(
                    canModify ? 202 : 403,
                );
                worker();
            } else if (scenario === 'FAILURE') {
                expect(
                    (
                        await request(page, `${path}/resume`, {
                            checkpoint_id: current.execution.checkpoints[0].id,
                        })
                    ).status(),
                ).toBe(canModify ? 201 : 403);
                worker();
            } else {
                const payload = {
                    operation: 'RENAME_CATEGORY',
                    node_id: 'cat:collection-academic',
                    value: 'Nombre revisado',
                    expected_version: 0,
                    base_fingerprint: current.review.fingerprint,
                };
                expect(
                    (
                        await request(page, `${path}/proposals`, payload)
                    ).status(),
                ).toBe(canModify ? 201 : 403);
                if (canModify) {
                    expect(
                        (await request(page, `${path}/validate`)).status(),
                    ).toBe(202);
                    worker();
                }
                control('finalize', { execution });
                worker();
                const artifact = snapshot(item.uuid).review.artifacts[0];
                expect(
                    (
                        await page.request.get(
                            `${path}/artifacts/${artifact.id}/download?key=${randomUUID()}`,
                        )
                    ).status(),
                ).toBe(canView ? 200 : 403);
                expect(
                    (
                        await page.request.get(
                            `${executionPath(project.uuid, execution)}/artifacts/${artifact.id}/download`,
                        )
                    ).status(),
                ).toBe(404);
            }
        }
    });
}

test('UUID, checkpoint y conflicto de otro proyecto nunca autorizan mutaciones', async ({
    page,
    password,
}) => {
    await login(page, password);
    const a = ready('COLLECT', 'FAILURE');
    const b = ready('COLLECT', 'FAILURE');
    const ea = control<{ uuid: string }>('start', { project: a.uuid }).uuid;
    const eb = control<{ uuid: string }>('start', { project: b.uuid }).uuid;
    worker();
    expect(
        (
            await request(page, `${executionPath(a.uuid, ea)}/resume`, {
                checkpoint_id: snapshot(b.uuid).execution.checkpoints[0].id,
            })
        ).status(),
    ).toBe(404);
    expect(
        (
            await request(page, `${executionPath(a.uuid, eb)}/cancel`, {
                execution_command_id: snapshot(a.uuid).commands[0].id,
            })
        ).status(),
    ).toBe(404);
    const c = ready('COLLECT', 'WARNING');
    const ec = control<{ uuid: string }>('start', { project: c.uuid }).uuid;
    worker();
    expect(
        (
            await request(
                page,
                `${executionPath(a.uuid, ea)}/conflicts/${snapshot(c.uuid).execution.conflicts[0].id}/resolve`,
                { decision: 'ACCEPT', conflict_version: 1 },
            )
        ).status(),
    ).toBe(404);
    expect(snapshot(a.uuid).execution_count).toBe(1);
    expect(snapshot(c.uuid).execution.uuid).toBe(ec);
    expect(snapshot(c.uuid).execution.status).toBe('WAITING_USER_ACTION');
});
