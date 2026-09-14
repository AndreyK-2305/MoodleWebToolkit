import { createHash, randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
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
    control,
    wizard,
} from './helpers';

for (const type of ['COLLECT', 'CONSOLIDATE', 'INTEGRATE']) {
    test(`vertical completa ${type}: wizard, Redis, worker, Reverb, review y cierre inmutable`, async ({
        page,
        password,
    }) => {
        // Disable only the periodic fallback. Reverb notifications legitimately
        // trigger HTTP catch-up to load the authoritative execution and review.
        await page.addInitScript(() => {
            const interval = window.setInterval.bind(window);
            window.setInterval = ((
                handler: TimerHandler,
                timeout?: number,
                ...args: unknown[]
            ) =>
                timeout === 15_000
                    ? 0
                    : interval(
                          handler,
                          timeout,
                          ...args,
                      )) as typeof window.setInterval;
        });
        let notifications = 0;
        const receivedEvents: string[] = [];
        page.on('websocket', (socket) => {
            socket.on('framereceived', ({ payload }) => {
                if (String(payload).includes('execution.event')) {
                    notifications++;
                    receivedEvents.push(String(payload));
                }
            });
        });
        await login(page, password);
        const project = await wizard(page, type);
        await page
            .getByRole('button', { name: 'Confirmar configuración' })
            .click();
        await expect(
            page.getByText('Proyecto listo', { exact: true }),
        ).toBeVisible();
        const execution = await start(page, {
            uuid: project,
            version: snapshot(project).configuration.version,
        });
        await expect(
            page.getByText('Tiempo real conectado', { exact: true }),
        ).toBeVisible();
        const firstPid = worker('once');
        await visibleStatus(page, 'RUNNING');
        expect(notifications).toBeGreaterThan(0);
        expect(snapshot(project).execution.progress).toBe(25);
        const secondPid = worker('once');
        expect(secondPid).not.toBe(firstPid);
        await visibleStatus(page, 'VERIFYING');
        worker();
        await visibleStatus(page, 'REVIEW');
        expect(snapshot(project).review.tree.length).toBeGreaterThan(0);
        const syntheticSecret = `quality-secret-${randomUUID()}`;
        control('event', {
            execution,
            message: `Calidad 1G Authorization: Bearer ${syntheticSecret}`,
        });
        await expect(
            page.getByText('Calidad 1G Authorization: [REDACTED]', {
                exact: true,
            }),
        ).toBeVisible();
        expect(JSON.stringify(snapshot(project).events)).not.toContain(
            syntheticSecret,
        );
        expect(receivedEvents.join('\n')).not.toContain(syntheticSecret);
        expect(
            await (
                await page.request.get(
                    `${executionPath(project, execution)}/events?after=0`,
                )
            ).text(),
        ).not.toContain(syntheticSecret);
        await page.reload();
        await visibleStatus(page, 'REVIEW');
        const finalizationAccepted = page.waitForResponse(
            (response) =>
                response.url().endsWith('/finalize') &&
                response.request().method() === 'POST',
        );
        await page
            .getByRole('button', { name: 'Finalizar', exact: true })
            .click();
        expect((await finalizationAccepted).status()).toBe(302);
        expect(
            snapshot(project).commands.filter(
                (c) => c.command_type === 'FINALIZE',
            ),
        ).toHaveLength(1);
        worker();
        await expect(
            page.getByText('Proyecto completado · sólo lectura'),
        ).toBeVisible();
        const completed = snapshot(project);
        expect(completed.status).toBe('COMPLETED');
        expect(completed.review.artifacts).toHaveLength(4);
        expect(completed.review.read_only).toBe(true);
        expect(new Date(completed.execution.finished_at).getTime()).toBe(
            new Date(completed.finalization.completed_at).getTime(),
        );
        expect(
            new Date(
                String(completed.review.completion_summary.completed_at),
            ).getTime(),
        ).toBe(new Date(completed.execution.finished_at).getTime());
        for (const artifact of completed.review.artifacts) {
            const path = `${executionPath(project, execution)}/artifacts/${artifact.id}/download?key=${randomUUID()}`;
            const download = await page.request.get(path);
            expect(download.status()).toBe(200);
            expect(download.headers()['content-disposition']).toContain(
                'attachment',
            );
            expect(download.headers()['x-content-type-options']).toBe(
                'nosniff',
            );
            const bytes = await download.body();
            expect(bytes.toString('utf8')).not.toContain(syntheticSecret);
            expect(bytes.length).toBe(artifact.size);
            expect(createHash('sha256').update(bytes).digest('hex')).toBe(
                artifact.sha256,
            );
        }
        const downloadEvent = page.waitForEvent('download');
        await page
            .getByRole('link')
            .filter({ hasText: completed.review.artifacts[0].filename })
            .first()
            .click();
        const file = await downloadEvent;
        const artifact = completed.review.artifacts.find(
            (a) => a.filename === file.suggestedFilename(),
        )!;
        expect(
            createHash('sha256')
                .update(readFileSync((await file.path())!))
                .digest('hex'),
        ).toBe(artifact.sha256);
        for (const action of ['cancel', 'validate', 'proposals']) {
            expect(
                (
                    await request(
                        page,
                        `${executionPath(project, execution)}/${action}`,
                        action === 'proposals'
                            ? {
                                  operation: 'RENAME_CATEGORY',
                                  node_id: (
                                      completed.review.tree[0] as { id: string }
                                  ).id,
                                  value: 'Cambio prohibido después del cierre',
                                  expected_version:
                                      completed.review.proposal_version,
                                  base_fingerprint:
                                      completed.review.fingerprint,
                              }
                            : {},
                    )
                ).status(),
            ).toBe(403);
        }
        // 1F explicitly permits retrieving an existing finalization idempotently.
        const repeatedFinalization = await request(
            page,
            `${executionPath(project, execution)}/finalize`,
        );
        expect(repeatedFinalization.status()).toBe(200);
        expect(await repeatedFinalization.json()).toMatchObject({
            created: false,
            status: 'COMPLETED',
        });
        expect(
            snapshot(project).commands.filter(
                (command) => command.command_type === 'FINALIZE',
            ),
        ).toHaveLength(1);
        expect(
            (
                await request(page, `/projects/${project}/executions`, {
                    configuration_version: completed.configuration.version,
                })
            ).status(),
        ).toBe(409);
        const previous = JSON.stringify({
            execution: completed.execution,
            review: completed.review,
            events: completed.events,
        });
        control('replay', { execution });
        worker();
        const after = snapshot(project);
        expect(
            JSON.stringify({
                execution: after.execution,
                review: after.review,
                events: after.events,
            }),
        ).toBe(previous);
        await page.reload();
        await expect(
            page.getByText('Proyecto completado · sólo lectura'),
        ).toBeVisible();
        await expect(
            page.getByRole('button', {
                name: /Finalizar|Cancelar ejecución|Validar ajustes|Reanudar desde/,
            }),
        ).toHaveCount(0);
    });
}

test('inicio concurrente con Idempotency-Key conserva una ejecución atómica', async ({
    page,
    password,
}) => {
    await login(page, password);
    const project = ready();
    const key = randomUUID();
    const responses = await Promise.all([
        request(
            page,
            `/projects/${project.uuid}/executions`,
            { configuration_version: project.version },
            key,
        ),
        request(
            page,
            `/projects/${project.uuid}/executions`,
            { configuration_version: project.version },
            key,
        ),
    ]);
    expect(responses.map((r) => r.status()).sort((a, b) => a - b)).toEqual([
        200, 201,
    ]);
    const state = snapshot(project.uuid);
    expect(state.execution_count).toBe(1);
    expect(state.commands).toHaveLength(1);
    expect(state.execution.status).toBe('QUEUED');
    expect(
        (
            await request(
                page,
                `/projects/${project.uuid}/executions`,
                { configuration_version: project.version + 1 },
                key,
            )
        ).status(),
    ).toBe(409);
    expect(
        (
            await request(page, `/projects/${project.uuid}/executions`, {
                configuration_version: project.version,
            })
        ).status(),
    ).toBe(409);
    worker();
    expect(snapshot(project.uuid).execution.status).toBe('REVIEW');
});

for (const scenario of ['WARNING', 'INTERVENTION']) {
    test(`${scenario}: decisión idempotente continúa la misma ejecución`, async ({
        page,
        password,
    }) => {
        await login(page, password);
        const project = ready('COLLECT', scenario);
        const execution = await start(page, project);
        worker();
        await visibleStatus(page, 'WAITING_USER_ACTION');
        const before = snapshot(project.uuid);
        const conflict = before.execution.conflicts[0];
        const key = randomUUID();
        const path = `${executionPath(project.uuid, execution)}/conflicts/${conflict.id}/resolve`;
        const payload = {
            decision: conflict.allowed_decisions[0],
            conflict_version: conflict.version,
        };
        expect((await request(page, path, payload, key)).status()).toBe(200);
        expect((await request(page, path, payload, key)).status()).toBe(200);
        worker();
        await visibleStatus(page, 'REVIEW');
        const after = snapshot(project.uuid);
        expect(after.execution.uuid).toBe(execution);
        expect(after.execution_count).toBe(1);
        expect(
            after.commands.filter((c) => c.command_type === 'RESOLVE_CONFLICT'),
        ).toHaveLength(1);
        expect(after.events.map((e) => e.sequence)).toEqual(
            Array.from({ length: after.events.length }, (_, i) => i + 1),
        );
    });
}

test('fallo con checkpoint: reanuda una sola vez con workspace y secuencia nuevos', async ({
    page,
    password,
}) => {
    await login(page, password);
    const project = ready('COLLECT', 'FAILURE');
    const execution = await start(page, project);
    worker();
    await visibleStatus(page, 'FAILED');
    const failed = snapshot(project.uuid);
    const checkpoint = failed.execution.checkpoints[0];
    const key = randomUUID();
    const path = `${executionPath(project.uuid, execution)}/resume`;
    const first = await request(
        page,
        path,
        { checkpoint_id: checkpoint.id },
        key,
    );
    expect(first.status()).toBe(201);
    const resumed = await first.json();
    expect(
        (
            await request(page, path, { checkpoint_id: checkpoint.id }, key)
        ).status(),
    ).toBe(200);
    const next = snapshot(project.uuid);
    expect(next.execution_count).toBe(2);
    expect(next.execution.uuid).toBe(resumed.execution_uuid);
    expect(next.execution.resumed_from_execution_uuid).toBe(execution);
    expect(next.workspace).not.toBe(failed.workspace);
    expect(next.events).toHaveLength(0);
    worker();
    expect(snapshot(project.uuid).events[0].sequence).toBe(1);
    await page.goto(executionPath(project.uuid, next.execution.uuid));
    await visibleStatus(page, 'REVIEW');
    expect(snapshot(project.uuid, execution).execution.status).toBe('FAILED');
});

for (const state of ['QUEUED', 'RUNNING', 'WAITING_USER_ACTION']) {
    test(`cancelación cooperativa e idempotente desde ${state}`, async ({
        page,
        password,
    }) => {
        await login(page, password);
        const project = ready(
            'COLLECT',
            state === 'WAITING_USER_ACTION' ? 'INTERVENTION' : 'SUCCESS',
        );
        const execution = await start(page, project);
        if (state === 'RUNNING') worker('once');
        if (state === 'WAITING_USER_ACTION') worker();
        await visibleStatus(page, state);
        const key = randomUUID();
        const path = `${executionPath(project.uuid, execution)}/cancel`;
        expect((await request(page, path, {}, key)).status()).toBe(202);
        expect((await request(page, path, {}, key)).status()).toBe(200);
        expect(snapshot(project.uuid).execution.status).toBe('CANCELLING');
        worker();
        await visibleStatus(page, 'CANCELLED');
        expect(
            snapshot(project.uuid).commands.filter(
                (c) => c.command_type === 'CANCEL',
            ),
        ).toHaveLength(1);
        const count = snapshot(project.uuid).events.length;
        control('replay', { execution });
        worker();
        expect(snapshot(project.uuid).events).toHaveLength(count);
    });
}

test('reconexión real de Reverb, polling, offline y pestaña recuperan eventos ordenados', async ({
    page,
    password,
    context,
}) => {
    await login(page, password);
    const project = ready('COLLECT', 'INTERVENTION');
    let sockets = 0;
    page.on('websocket', () => sockets++);
    const execution = await start(page, project);
    await expect(page.getByText('Tiempo real conectado')).toBeVisible();
    control('reverb-restart');
    await expect.poll(() => sockets, { timeout: 30_000 }).toBeGreaterThan(1);
    await expect(page.getByText('Tiempo real conectado')).toBeVisible({
        timeout: 30_000,
    });
    worker('once');
    await visibleStatus(page, 'RUNNING');
    control('event', {
        execution,
        message: 'Evento después de reiniciar Reverb',
    });
    await expect(
        page.getByText('Evento después de reiniciar Reverb', { exact: true }),
    ).toBeVisible();
    const other = await context.newPage();
    await other.goto('/dashboard');
    await other.bringToFront();
    await context.setOffline(true);
    worker();
    const persisted = snapshot(project.uuid);
    expect(persisted.execution.status).toBe('WAITING_USER_ACTION');
    await context.setOffline(false);
    await page.bringToFront();
    await visibleStatus(page, 'WAITING_USER_ACTION');
    await page.reload();
    const sequences = await page
        .locator('span')
        .filter({ hasText: /^#\d+$/ })
        .allTextContents();
    expect(new Set(sequences).size).toBe(sequences.length);
    expect(sequences.length).toBe(persisted.events.length);
    await other.close();
});

test('polling recupera progreso sin un canal WebSocket disponible', async ({
    page,
    password,
}) => {
    await page.routeWebSocket(/\/app\//, (socket) => socket.close());
    await login(page, password);
    const project = ready();
    await start(page, project);
    await expect(page.getByText('Recuperación activa')).toBeVisible();
    worker();
    await visibleStatus(page, 'REVIEW');
});
