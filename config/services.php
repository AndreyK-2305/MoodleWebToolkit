<?php

return [

    'finalization' => [
        'records_per_job' => (int) env('FINALIZATION_RECORDS_PER_JOB', 200),
        'bytes_per_job' => (int) env('FINALIZATION_BYTES_PER_JOB', 1_048_576),
        'time_budget_seconds' => (int) env('FINALIZATION_TIME_BUDGET_SECONDS', 105),
        'max_record_bytes' => (int) env('FINALIZATION_MAX_RECORD_BYTES', 1_048_576),
        'verification_bytes_per_job' => (int) env('FINALIZATION_VERIFICATION_BYTES_PER_JOB', 1_048_576),
        'cleanup_minimum_age_seconds' => (int) env('FINALIZATION_CLEANUP_MINIMUM_AGE_SECONDS', 86_400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
