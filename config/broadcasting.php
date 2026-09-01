<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default broadcaster
    |--------------------------------------------------------------------------
    |
    | `null` is the default so the application runs identically with realtime
    | switched off: every Livewire component still works, it simply does not
    | refresh by itself. Set BROADCAST_CONNECTION=reverb once a Reverb service
    | is running.
    |
    | Only the connections this product actually uses are declared. An unused
    | connection in configuration is a credential nobody audits.
    |
    */

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                //
            ],
        ],

        // Writes broadcasts to the log. Useful when developing an event
        // without running a websocket server.
        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
