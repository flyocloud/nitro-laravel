<?php

return [
    // The token to authenticate the application with the Flyo API.
    // The production token can be set here, if there is no token set in the .env file, the production token will be used.
    'token' => env('FLYO_TOKEN', 'ADD_PRODUCTION_TOKEN_HERE'),

    // If enabled, the user can interact with the components, also it represents the application to be in development mode.
    // The default setting false represents the application to be in production mode.
    'live_edit' => env('FLYO_LIVE_EDIT', false),

    // The namespace for the views, which means the views will be stored in resources/views/flyo.
    'views_namespace' => 'flyo',

    // TTL (Time-To-Live) for server-side cache headers, in seconds.
    // Default is 900 seconds (15 minutes) its only availble if the liveEdit is disabled. Use 0 to disable server caching.
    // will set Vercel-CDN-Cache-Control and CDN-Cache-Control headers
    'server_cache_ttl' => env('FLYO_SERVER_CACHE_TTL', 900),

    // The TTL for client-side cache headers, in seconds.
    // Default is 1200 seconds (20 minutes) its only availble if the liveEdit is disabled. Use 0 to disable client caching.
    // will set Cache-Control header
    'client_cache_ttl' => env('FLYO_CLIENT_CACHE_TTL', 1200),

    // The default route to be used for the detail pages.
    // Routes/Links can be defined in the Flyo interface for each corresponding entity, the default name is 'detail'.
    'default_route' => env('FLYO_DEFAULT_ROUTE', 'detail'),

    // The list of supported locales as defined in the Flyo interface.
    // By default, APP_LOCALE will be used for routes without a locale prefix.
    // If this array is empty, no locale prefixing will be applied to config or page requests,
    // effectively disabling multilingual support for the website.
    'locales' => [
        /*
        'en',
        'de',
        'fr',
        */
    ],
];
