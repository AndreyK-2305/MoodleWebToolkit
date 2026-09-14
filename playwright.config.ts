import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/E2E',
    fullyParallel: false,
    workers: 1,
    forbidOnly: true,
    retries: 0,
    timeout: 60_000,
    expect: { timeout: 20_000 },
    outputDir: 'quality-results/playwright',
    reporter: [
        ['list'],
        ['./tests/E2E/failure-reporter.ts'],
        ['junit', { outputFile: 'quality-results/playwright.xml' }],
    ],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://localhost:8080',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
});
