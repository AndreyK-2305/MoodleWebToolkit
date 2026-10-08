<?php
declare(strict_types=1);

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$actual = $CFG->allowaccountssameemail ?? null;
if (!in_array($actual, [1, '1', true], true)) {
    fwrite(STDERR, 'TARGET_SHARED_EMAIL_POLICY_FAILED expected=1 actual=' .
        (is_scalar($actual) ? var_export($actual, true) : get_debug_type($actual)) . "\n");
    exit(1);
}
echo "TARGET_SHARED_EMAIL_POLICY_OK setting=allowaccountssameemail expected=1 actual=1 status=OK\n";
