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

    'worldcup26' => [
        'base_url' => env('WORLDCUP26_BASE_URL', 'https://worldcup26.ir/'),
    ],

    'futbolfantasy' => [
        'base_url' => env('FUTBOLFANTASY_BASE_URL', 'https://www.futbolfantasy.com/'),
    ],

    /*
     * Instagram API with Instagram Login (graph.instagram.com). The access token here only seeds the first weekly
     * `instagram:refresh-token`; afterwards the newest refreshed token (instagram_access_tokens table) is used.
     */
    'instagram' => [
        'base_url' => env('INSTAGRAM_BASE_URL', 'https://graph.instagram.com/'),
        'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v24.0'),
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        'user_id' => env('INSTAGRAM_USER_ID'),
    ],

    /*
     * The external Remotion project (comando-lechuga-research/video) that renders the «Compras del mercado» stories.
     * Contract: the JSON goes to <path>/<data_directory>/compras-<date>.json, then `npm run <build_script> -- <date>`
     * writes <path>/<generated_directory>/compras-<date>.json ({parts: [{frames: [{kind}]}]}) and
     * `npm run <render_script> -- <date>` renders <path>/<output_directory>/compras-<date>.mp4 (one part) or
     * compras-<date>-p1.mp4, -p2… (one composition per part).
     */
    'remotion' => [
        'path' => env('REMOTION_PROJECT_PATH', base_path('video')),
        'build_script' => env('REMOTION_COMPRAS_BUILD_SCRIPT', 'build:compras'),
        'render_script' => env('REMOTION_COMPRAS_RENDER_SCRIPT', 'render:compras'),
        'data_directory' => 'data',
        'generated_directory' => 'src/generated',
        'output_directory' => 'out',
        'timeout' => (int) env('REMOTION_TIMEOUT', 600),
        // Chrome tabs per render; empty lets Remotion pick (one per core). Production (2 cores, 3.7 GB) uses 1.
        'concurrency' => env('REMOTION_CONCURRENCY', ''),
        // Run the render with `nice -n 10` (Linux only) so the scheduler's syncs keep priority.
        'nice' => (bool) env('REMOTION_NICE', false),
    ],

    'god_mode' => [
        'key' => env('GOD_MODE_KEY'),
    ],
];
