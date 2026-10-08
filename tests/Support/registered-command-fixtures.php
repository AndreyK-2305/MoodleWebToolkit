<?php

// This versioned registry is available only to an explicitly opted-in testing
// runtime. Request descriptors never define or replace its executable argv.
return [
    'platform_health' => [
        'executable' => PHP_BINARY,
        'fixed_arguments' => ['-r', 'fwrite(STDOUT, "pass"."word="."private-value");'],
        'parameters' => [],
        'timeout' => 5,
        'cancellable' => true,
    ],
    'platform_long' => [
        'executable' => PHP_BINARY,
        'fixed_arguments' => ['-r', 'usleep(5000000);'],
        'parameters' => [],
        'timeout' => 15,
        'cancellable' => true,
    ],
    'platform_concurrent' => [
        'executable' => PHP_BINARY,
        'fixed_arguments' => ['-r', 'usleep(15000000);'],
        'parameters' => [],
        'timeout' => 20,
        'cancellable' => true,
    ],
    'platform_export' => [
        'executable' => PHP_BINARY,
        'fixed_arguments' => ['-r', 'exit(0);'],
        'parameters' => ['source_id' => ['pattern' => '/^[a-z0-9_-]{1,40}$/D']],
        'timeout' => 5,
    ],
    'platform_tree' => [
        'executable' => PHP_BINARY,
        'fixed_arguments' => ['-r', '$child=pcntl_fork(); if($child===0){file_put_contents(getenv("TMPDIR")."/child-process.pid",(string)getmypid()); while(true){usleep(100000);}} while(true){usleep(100000);}'],
        'parameters' => [],
        'timeout' => 20,
        'cancellable' => true,
    ],
];
