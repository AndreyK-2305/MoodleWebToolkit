import type { Reporter, TestCase, TestResult } from '@playwright/test/reporter';

export default class FailureReporter implements Reporter {
    onTestEnd(test: TestCase, result: TestResult) {
        if (result.status !== 'passed' && result.status !== 'skipped') {
            process.stderr.write(`\n${test.titlePath().join(' › ')}\n`);
            for (const error of result.errors) {
                process.stderr.write(`${error.message ?? error.value}\n`);
            }
        }
    }
}
