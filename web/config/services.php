<?php

return [

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

    // сервис обработки документов (../processor); токен — PROCESSOR_API_TOKEN из его .env
    'processor' => [
        'url' => env('PROCESSOR_URL', 'http://127.0.0.1:8001'),
        'token' => env('PROCESSOR_API_TOKEN'),
        'timeout' => (int) env('PROCESSOR_TIMEOUT', 10),
        // обработка документа: бюджет сервиса 30 с плюс очередь и OCR сканов
        'extract_timeout' => (int) env('PROCESSOR_EXTRACT_TIMEOUT', 120),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
