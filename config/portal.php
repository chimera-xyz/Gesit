<?php

return [
    'apps' => [
        'gesit' => [
            'name' => env('PORTAL_GESIT_NAME', 'SiGesit'),
            'description' => env(
                'PORTAL_GESIT_DESCRIPTION',
                'Portal operasional internal, workflow approval, helpdesk, dan AI assistant perusahaan.'
            ),
            'badge' => env('PORTAL_GESIT_BADGE', 'Portal Utama'),
        ],
        'inventaris' => [
            'name' => env('PORTAL_INVENTARIS_NAME', 'Inventaris IT'),
            'description' => env(
                'PORTAL_INVENTARIS_DESCRIPTION',
                'Pendataan asset kantor, lokasi perangkat, histori servis, dan kondisi operasional perangkat.'
            ),
            'badge' => env('PORTAL_INVENTARIS_BADGE', 'Asset Control'),
            'launch_url' => env('PORTAL_INVENTARIS_LAUNCH_URL', 'http://127.0.0.1:8080/login'),
            'client_secret' => env('PORTAL_INVENTARIS_CLIENT_SECRET'),
            'redirect_uris' => array_values(array_filter(array_map(
                static fn (string $uri) => trim($uri),
                explode(',', (string) env('PORTAL_INVENTARIS_REDIRECT_URIS', 'http://127.0.0.1:8000/auth/sso/callback'))
            ))),
        ],
    ],

    'email_domain_defaults' => [
        'gesit.com' => 'gesit',
        'inventaris.com' => 'inventaris',
    ],
];
