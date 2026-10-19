<?php

// Moodle plugins require this location after loading the approved ephemeral config.
// This public shim contains no connection details and cannot bootstrap Moodle.
if (! defined('CLI_SCRIPT') || CLI_SCRIPT !== true || ! defined('MOODLE_INTERNAL')
    || ! isset($CFG) || ! is_object($CFG) || ($CFG->dirroot ?? null) !== __DIR__) {
    throw new RuntimeException('Synthetic Moodle requires an approved CLI configuration.');
}
