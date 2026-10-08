<?php

// Administrator-owned catalog. HTTP accepts only its opaque profile keys.
return [
    'secret_root' => env('COLLECTOR_LAB_SECRET_ROOT', '/run/secrets/moodle-toolkit-lab'),
    'profiles' => env('COLLECTOR_LAB_SYNTHETIC_PROFILE', false) ? [
        'synthetic-moodle' => [
            'name' => 'Moodle sintético de laboratorio',
            'root' => '/opt/moodle-lab',
            'code' => '/opt/moodle-lab/code',
            'data' => '/opt/moodle-lab/data',
            'base_url' => 'http://moodle-lab.test',
            'db_host' => 'moodle-lab-db',
            'db_port' => 5432,
            'db_name' => 'moodle_lab',
            'db_user' => 'moodle_lab',
            'db_prefix' => 'mdl_',
            'credential_reference' => 'moodle-lab-db',
            'credential_version' => '1',
            'source_id' => 'synthetic-lab',
            'moodle_series' => '4.5',
        ],
    ] : [],
];
