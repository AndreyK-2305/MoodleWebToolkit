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
    control,
    visibleStatus,
} from './helpers';

test('caducar autorización conserva seguimiento y reintenta payload y clave una sola vez', async ({
    page,
    password,
}) => {
    await login(page, password);
    const project = ready('COLLECT', 'INTERVENTION');
    const execution = await start(page, project);
    worker();
    await visibleStatus(page, 'WAITING_USER_ACTION');
    control('expire');
    const requests: Array<{ body: string | null; key: string | undefined }> =
        [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && r.url().endsWith('/resolve'))
            requests.push({
                body: r.postData(),
                key: r.headers()['idempotency-key'],
            });
    });
    await page
        .getByRole('button', { name: 'Confirmar intervención y continuar' })
        .click();
    await expect(page.getByRole('dialog')).toBeVisible();
    const before = snapshot(project.uuid);
    expect(before.execution.status).toBe('WAITING_USER_ACTION');
    expect(
        before.commands.filter((c) => c.command_type === 'RESOLVE_CONFLICT'),
    ).toHaveLength(0);
    // Events continue while the password dialog is open.
    control('event', {
        execution,
        message: 'Seguimiento durante autorización vencida',
    });
    await expect(
        page.getByText('Seguimiento durante autorización vencida', {
            exact: true,
        }),
    ).toBeVisible();
    await page.locator('#action-confirmation-password').fill('wrong-password');
    await page.getByRole('button', { name: 'Confirmar y reintentar' }).click();
    await expect(page.getByRole('dialog')).toBeVisible();
    expect(
        snapshot(project.uuid).commands.filter(
            (c) => c.command_type === 'RESOLVE_CONFLICT',
        ),
    ).toHaveLength(0);
    await page.locator('#action-confirmation-password').fill(password);
    await page.getByRole('button', { name: 'Confirmar y reintentar' }).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual(requests[0]);
    expect(requests[1].key).toBeTruthy();
    expect(
        snapshot(project.uuid).commands.filter(
            (c) => c.command_type === 'RESOLVE_CONFLICT',
        ),
    ).toHaveLength(1);
    worker();
    await visibleStatus(page, 'REVIEW');
});

test('Recordarme recupera observación sin renovar permiso de modificación', async ({
    page,
    password,
    context,
    browser,
}) => {
    await login(page, password, 'admin', true);
    const project = ready();
    const execution = await start(page, project);
    const cookies = (await context.cookies()).filter((c) =>
        c.name.startsWith('remember_'),
    );
    expect(cookies).toHaveLength(1);
    const recovered = await browser.newContext({
        baseURL: 'http://localhost:8080',
    });
    await recovered.addCookies(cookies);
    const restored = await recovered.newPage();
    await restored.goto(executionPath(project.uuid, execution));
    await visibleStatus(restored, 'QUEUED');
    expect(
        (
            await request(
                restored,
                `${executionPath(project.uuid, execution)}/cancel`,
            )
        ).status(),
    ).toBe(423);
    worker();
    await visibleStatus(restored, 'REVIEW');
    expect(snapshot(project.uuid).execution.status).toBe('REVIEW');
    await recovered.close();
});

for (const kind of ['assignment', 'role', 'inactive']) {
    test(`revocar ${kind} invalida HTTP y suscripción privada activa`, async ({
        page,
        password,
    }) => {
        const user = kind === 'role' ? 'admin' : 'operator';
        await login(page, password, user);
        const project = ready('COLLECT', 'INTERVENTION');
        const execution = await start(page, project);
        worker();
        await visibleStatus(page, 'WAITING_USER_ACTION');
        await expect(page.getByText('Tiempo real conectado')).toBeVisible();
        control('revoke', { user, kind });
        const marker = 'No visible después de revocar ' + kind;
        control('event', { execution, message: marker });
        const response = await page.request.get(
            `${executionPath(project.uuid, execution)}/events`,
            { maxRedirects: 0 },
        );
        expect([302, 403]).toContain(response.status());
        expect(
            (
                await request(
                    page,
                    `${executionPath(project.uuid, execution)}/cancel`,
                )
            ).status(),
        ).toBe(kind === 'inactive' ? 401 : 403);
        await expect(page.getByText(marker, { exact: true })).toHaveCount(0);
        expect(snapshot(project.uuid).execution.status).toBe(
            'WAITING_USER_ACTION',
        );
    });
}

test('más de 24 horas: workers nuevos, navegador cerrado, recuperación y cierre único', async ({
    page,
    password,
    browser,
}) => {
    await login(page, password);
    const project = ready();
    const execution = await start(page, project);
    const firstPid = worker('once');
    const started = snapshot(project.uuid).execution.started_at;
    await page.close();
    const clock = new Date(
        new Date(started).getTime() + 25 * 60 * 60 * 1000,
    ).toISOString();
    const persistedCommands = snapshot(project.uuid).commands.map((c) => c.id);
    control('lose-queue');
    expect(control('recover', { clock })).toEqual({ exit: 0 });
    expect(snapshot(project.uuid).commands.map((c) => c.id)).toEqual(
        persistedCommands,
    );
    const secondPid = worker('drain', clock);
    expect(firstPid).not.toBe(secondPid);
    const advanced = snapshot(project.uuid);
    expect(advanced.execution.status).toBe('REVIEW');
    expect(
        Math.max(
            ...advanced.events.map((e) => new Date(e.created_at).getTime()),
        ) - new Date(started).getTime(),
    ).toBeGreaterThan(24 * 60 * 60 * 1000);
    const recovered = await browser.newContext({
        baseURL: 'http://localhost:8080',
    });
    const observer = await recovered.newPage();
    await observer.goto(executionPath(project.uuid, execution));
    await expect(observer).toHaveURL(/\/login$/);
    await login(observer, password);
    control('expire');
    await observer.goto(executionPath(project.uuid, execution));
    await visibleStatus(observer, 'REVIEW');
    expect(
        (
            await request(
                observer,
                `${executionPath(project.uuid, execution)}/finalize`,
            )
        ).status(),
    ).toBe(423);
    expect(
        (
            await request(observer, '/auth/confirm-action-password', {
                password,
            })
        ).status(),
    ).toBe(200);
    expect(
        (
            await request(
                observer,
                `${executionPath(project.uuid, execution)}/finalize`,
            )
        ).status(),
    ).toBe(202);
    worker('drain', clock);
    expect(snapshot(project.uuid).execution.status).toBe('COMPLETED');
    const before = snapshot(project.uuid).events.length;
    control('replay', { execution });
    worker('drain', clock);
    expect(snapshot(project.uuid).events).toHaveLength(before);
    expect(snapshot(project.uuid).review.artifacts).toHaveLength(4);
    await recovered.close();
});
