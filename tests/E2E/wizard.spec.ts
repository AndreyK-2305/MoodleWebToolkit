import { test, expect, login, request, snapshot, wizard } from './helpers';

for (const type of ['COLLECT', 'CONSOLIDATE', 'INTEGRATE']) {
    for (const scenario of ['SUCCESS', 'WARNING', 'ERROR']) {
        test(`wizard ${type} ${scenario}: persistencia, navegación y confirmación`, async ({
            page,
            password,
        }) => {
            await login(page, password);
            const uuid = await wizard(page, type, scenario);
            const state = snapshot(uuid);
            expect(state.execution_count).toBe(0);
            const version = state.configuration.version;
            if (scenario === 'WARNING') {
                await page
                    .getByRole('button', { name: 'Confirmar configuración' })
                    .click();
                await expect(page.getByRole('checkbox').first()).toBeVisible();
                expect(snapshot(uuid).status).toBe('CONFIGURING');
                for (const checkbox of await page.getByRole('checkbox').all())
                    await checkbox.check();
            }
            await page
                .getByRole('button', { name: 'Confirmar configuración' })
                .click();
            if (scenario === 'ERROR') {
                await expect(
                    page.getByText('La configuración no puede confirmarse'),
                ).toBeVisible();
                expect(
                    (
                        await request(
                            page,
                            `/projects/${uuid}/wizard/confirm`,
                            {
                                configuration_version: version,
                                accepted_warning_ids: [],
                            },
                        )
                    ).status(),
                ).toBe(422);
                expect(snapshot(uuid).status).toBe('CONFIGURING');
            } else {
                await expect(
                    page.getByText('Proyecto listo', { exact: true }),
                ).toBeVisible();
                const confirmed = snapshot(uuid);
                expect(confirmed.status).toBe('READY');
                expect(confirmed.execution_count).toBe(0);
                const before = confirmed.audit.length;
                const accepted =
                    scenario === 'WARNING'
                        ? state.configuration.settings.preflight.checks
                              .filter((c) => c.result === 'WARNING')
                              .map((c) => c.id)
                        : [];
                expect(
                    (
                        await request(
                            page,
                            `/projects/${uuid}/wizard/confirm`,
                            {
                                configuration_version: version,
                                accepted_warning_ids: accepted,
                            },
                        )
                    ).status(),
                ).toBe(302);
                expect(snapshot(uuid).audit.length).toBe(before);
                if (scenario === 'WARNING')
                    expect(JSON.stringify(confirmed.audit)).toContain(
                        accepted[0],
                    );
                await page.reload();
                await expect(
                    page.getByText('Proyecto listo', { exact: true }),
                ).toBeVisible();
            }
        });
    }
    test(`wizard ${type}: URL insegura, reemplazo e intercambio, preflight obsoleto`, async ({
        page,
        password,
    }) => {
        await login(page, password);
        const uuid = await wizard(page, type);
        const state = snapshot(uuid);
        const instances = state.instances.map((i) => ({
            uuid: i.uuid,
            server_uuid: i.server.uuid,
            role: i.role,
            name: i.name,
            server_name: i.server.name,
            server_host: i.server.host,
            base_url: i.base_url,
            moodle_version: i.moodle_version,
            validated: i.validated,
            destination_kind: i.destination_kind,
        }));
        for (const url of [
            'https://user:fixture-secret@moodle.test',
            'https://moodle.test:99999',
            'x'.repeat(2049),
        ]) {
            const rejected = await request(
                page,
                `/projects/${uuid}/wizard/instances`,
                {
                    instances: instances.map((i, n) =>
                        n === 0 ? { ...i, base_url: url } : i,
                    ),
                },
                undefined,
                'PUT',
            );
            expect(rejected.status()).toBe(422);
            expect(await rejected.text()).not.toContain('fixture-secret');
        }
        const updated = instances.map((i) => ({ ...i }));
        if (updated.length > 1) {
            [updated[0].name, updated[1].name] = [
                updated[1].name,
                updated[0].name,
            ];
            [updated[0].server_name, updated[1].server_name] = [
                updated[1].server_name,
                updated[0].server_name,
            ];
        } else {
            Object.assign(updated[0], {
                uuid: null,
                server_uuid: null,
                server_host: 'replacement.test',
                base_url: 'https://replacement.test',
            });
        }
        expect(
            (
                await request(
                    page,
                    `/projects/${uuid}/wizard/instances`,
                    { instances: updated },
                    undefined,
                    'PUT',
                )
            ).status(),
        ).toBe(302);
        expect(
            (
                await request(page, `/projects/${uuid}/wizard/confirm`, {
                    configuration_version: state.configuration.version,
                    accepted_warning_ids: [],
                })
            ).status(),
        ).toBe(422);
        expect(snapshot(uuid).execution_count).toBe(0);
        await page.reload();
        await page
            .getByRole('button', { name: /Instancias simuladas/ })
            .click();
        await expect(page.locator('#instance-name-0')).toHaveValue(
            updated[0].name,
        );
    });
}
