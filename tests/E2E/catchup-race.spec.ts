import {
    test,
    expect,
    login,
    ready,
    start,
    worker,
    snapshot,
    control,
    visibleStatus,
} from './helpers';

test('Reverb durante una consulta pendiente conserva la última transición sin polling', async ({
    page,
    password,
}) => {
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
    let execution = '';
    const notified = new Set<number>();
    page.on('websocket', (socket) =>
        socket.on('framereceived', ({ payload }) => {
            try {
                const envelope = JSON.parse(String(payload));
                const data =
                    typeof envelope.data === 'string'
                        ? JSON.parse(envelope.data)
                        : envelope.data;
                if (
                    data?.execution_uuid === execution &&
                    typeof data.event?.sequence === 'number'
                ) {
                    notified.add(data.event.sequence);
                }
            } catch {
                /* Protocol control frames have no execution payload. */
            }
        }),
    );
    await login(page, password);
    const project = ready();
    execution = await start(page, project);
    await expect(
        page.getByText('Tiempo real conectado', { exact: true }),
    ).toBeVisible();
    worker('once');
    await visibleStatus(page, 'RUNNING');
    expect(snapshot(project.uuid).execution.progress).toBe(25);

    let holding = false;
    let resolveHeld!: () => void;
    const held = new Promise<void>((resolve) => {
        resolveHeld = resolve;
    });
    let release!: () => void;
    const gate = new Promise<void>((resolve) => {
        release = resolve;
    });
    await page.route('**/events?after=*', async (route) => {
        if (holding) {
            await route.continue();
            return;
        }
        holding = true;
        const response = await route.fetch();
        expect((await response.json()).execution.status).toBe('RUNNING');
        resolveHeld();
        await gate;
        await route.fulfill({ response });
    });
    try {
        control('event', { execution, message: 'Controlled catch-up barrier' });
        await held;
        worker('once');
        const final = snapshot(project.uuid);
        expect(final.execution.status).toBe('VERIFYING');
        // Delivery is proved before releasing the older HTTP snapshot.
        await expect
            .poll(() => notified.has(final.execution.last_event_sequence))
            .toBe(true);
        release();
        await visibleStatus(page, 'VERIFYING');
        expect(snapshot(project.uuid).execution_count).toBe(1);
    } finally {
        release();
        await page.unroute('**/events?after=*');
    }
});
