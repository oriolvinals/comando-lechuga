<?php

declare(strict_types=1);

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'la_liga_fantasy' => [
        'base_url' => env('LA_LIGA_FANTASY_BASE_URL', 'https://fantasy-api.llt-services.com/'),
    ],

    'la_liga_login' => [
        'base_url' => env('LA_LIGA_LOGIN_BASE_URL', 'https://login.laliga.es'),
        'email' => env('LA_LIGA_LOGIN_EMAIL'),
        'password' => env('LA_LIGA_LOGIN_PASSWORD'),
        'policy' => env('LA_LIGA_LOGIN_POLICY', 'B2C_1A_5ULAIP_PARAMETRIZED_SignIn'),
        'client_id' => env('LA_LIGA_LOGIN_CLIENT_ID', 'af88bcff-1157-40a0-b579-030728aacf0b'),
        'redirect_uri' => env('LA_LIGA_LOGIN_REDIRECT_URI', 'authredirect://com.lfp.laligafantasy/'),
    ],
];
