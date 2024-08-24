<?php

return [
    // The token to authenticate the application with the Flyo API.
    'token' => env('FLYO_TOKEN', 'ADD_YOUR_TOKEN_HERE'),

    // If enabled, the user can interact with the components, also it represents the application to be in development mode.
    'live_edit' => env('FLYO_LIVE_EDIT', true),

    // The namespace for the views, which means the views will be stored in resources/views/flyo.
    'views_namespace' => 'flyo',
];
