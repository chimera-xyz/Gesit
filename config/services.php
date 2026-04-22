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

    'zai' => [
        'api_key' => env('ZAI_API_KEY'),
        'base_url' => env('ZAI_BASE_URL', 'https://api.z.ai/api/paas/v4'),
        'model' => env('ZAI_MODEL', 'glm-5.1'),
        'timeout' => env('ZAI_TIMEOUT', 30),
    ],

    'local_ai' => [
        'api_key' => env('LOCAL_AI_API_KEY'),
        'base_url' => env('LOCAL_AI_BASE_URL'),
        'model' => env('LOCAL_AI_MODEL', 'model-ai-yulie.gguf'),
        'timeout' => env('LOCAL_AI_TIMEOUT', 60),
    ],

    'inventory' => [
        'base_url' => env('INVENTORY_ASSISTANT_BASE_URL'),
        'assistant_secret' => env('INVENTORY_ASSISTANT_SECRET'),
        'timeout' => env('INVENTORY_ASSISTANT_TIMEOUT', 12),
    ],

    'fcm' => [
        'enabled' => env('FCM_ENABLED', true),
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_path' => env('FCM_SERVICE_ACCOUNT_PATH'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'token_uri' => env('FCM_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
    ],

];
