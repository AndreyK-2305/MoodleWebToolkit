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
        [
            'junit',
            {
                outputFile:
                    process.env.QUALITY_JUNIT_FILE ??
                    'quality-results/playwright.xml',
            },
        ],
    ],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: 'http://localhost:8080',
        // Traces/videos can retain generated login credentials in request bodies.
        trace: 'off',
        screenshot: 'only-on-failure',
        video: 'off',
    },
});
