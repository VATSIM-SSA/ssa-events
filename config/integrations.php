<?php

use App\Integrations\ControlCenter\ControlCenter;
use App\Integrations\Discord\Discord;

return [

    /*
    |--------------------------------------------------------------------------
    | Integrations
    |--------------------------------------------------------------------------
    |
    | The Integrations the Hub offers (classes extending App\Integrations\Integration).
    | Admins switch them on and set them up on the Integrations admin page.
    |
    */

    'registered' => [
        ControlCenter::class,
        Discord::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings Set On The Server
    |--------------------------------------------------------------------------
    |
    | Setting an Integration's setting here locks it: the app shows it as set by
    | the server and admins can't change it. Keyed by the Integration's key, then
    | the setting's key, each read from the Setting's environment variable.
    |
    */

    'settings' => [
        'control-center' => [
            'url' => env('CONTROL_CENTER_URL'),
            'token' => env('CONTROL_CENTER_TOKEN'),
            'areas' => env('CONTROL_CENTER_AREAS'),
        ],
        'discord' => [
            'events_webhook' => env('DISCORD_EVENTS_WEBHOOK'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | VATSSA: One Roster For The Division
    |--------------------------------------------------------------------------
    |
    | On, Control Center's roster sync gives anyone active in any area, or with
    | a visiting endorsement, one Controller role across the hub, and ignores
    | the area map. Off keeps upstream's Controller role per mapped FIR.
    |
    */

    'control_center_hub_wide' => (bool) env('CONTROL_CENTER_HUB_WIDE', false),

];
