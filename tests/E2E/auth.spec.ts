import { randomBytes } from 'node:crypto';
import { test, expect, login, request } from './helpers';

test('login incorrecto, correcto y logout revocan acceso a rutas internas', async ({
    page,
    password,
}) => {
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/login$/);
    await page.getByLabel('Correo electrónico').fill('admin@quality.test');
    await page.locator('#password').fill('incorrect-password');
    const failed = page.waitForResponse(
        (r) => r.url().endsWith('/login') && r.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Ingresar' }).click();
    await failed;
    await expect(page).toHaveURL(/\/login$/);
    await login(page, password, 'admin', false, /\/projects$/);
    expect((await request(page, '/logout')).status()).toBe(302);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('usuario inactivo rechazado por el servidor', async ({
    page,
    password,
}) => {
    await page.goto('/login');
    const response = await request(page, '/login', {
        email: 'inactive@quality.test',
        password,
    });
    expect(response.status()).toBe(422);
    await page.goto('/dashboard');
    await expect(page).toHaveURL(/\/login$/);
});

test('contraseña temporal exige cambio antes de acceder al proyecto', async ({
    page,
    password,
}) => {
    await login(page, password, 'temporary');
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/settings\/security$/);
    const next = randomBytes(24).toString('base64url');
    expect(
        (
            await request(
                page,
                '/settings/password',
                {
                    current_password: password,
                    password: next,
                    password_confirmation: next,
                },
                undefined,
                'PUT',
            )
        ).status(),
    ).toBe(302);
    await page.goto('/projects');
    await expect(page).toHaveURL(/\/projects$/);
});

test('navegación y recarga directa de todas las páginas Inertia', async ({
    page,
    password,
}) => {
    await login(page, password);
    for (const [name, path] of [
        ['Proyectos', '/projects'],
        ['Manuales', '/manuals'],
        ['Acerca de', '/about'],
        ['Inicio', '/dashboard'],
    ]) {
        await page.getByRole('link', { name, exact: true }).first().click();
        await expect(page).toHaveURL(new RegExp(`${path}$`));
        const response = await page.reload();
        expect(response?.status()).toBe(200);
        await expect(page.locator('main')).toBeVisible();
    }
    for (const path of [
        '/settings/profile',
        '/settings/security',
        '/settings/appearance',
    ]) {
        expect((await page.goto(path))?.status()).toBe(200);
        expect((await page.reload())?.status()).toBe(200);
    }
});

test('tema claro, oscuro y sistema persisten y responden al sistema', async ({
    page,
    password,
}) => {
    await login(page, password);
    await page.goto('/settings/appearance');
    for (const [label, value] of [
        ['Dark', 'dark'],
        ['Light', 'light'],
        ['System', 'system'],
    ]) {
        await page.getByRole('button', { name: label, exact: true }).click();
        expect(
            await page.evaluate(() => localStorage.getItem('appearance')),
        ).toBe(value);
        await page.reload();
        expect(
            await page.evaluate(() => localStorage.getItem('appearance')),
        ).toBe(value);
    }
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(page.locator('html')).not.toHaveClass(/dark/);
});
