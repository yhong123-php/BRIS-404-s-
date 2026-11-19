<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

    // H1 — AI-Powered Barangay Assistant: locally hosted model via Ollama (SPMP §5.5.4).
    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen2.5:3b'),
        // Seconds before the assistant reports itself temporarily unavailable (SRS §3.3.3).
        'timeout' => env('OLLAMA_TIMEOUT', 180),
        // Days a conversation is kept before deletion (SPMP §5.5.5; confirm with the barangay).
        'retention_days' => env('ASSISTANT_RETENTION_DAYS', 30),
    ],

    // D3 — Backup and restore (SDD §6). Paths to the MySQL client tools.
    'backup' => [
        'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
        'mysql' => env('MYSQL_CLIENT_PATH', 'mysql'),
    ],

];
