<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Test address
    |--------------------------------------------------------------------------
    |
    | Every email canary:forms sends goes here, and only here. It's usually
    | set per environment. The --to option overrides it.
    |
    */

    'to' => env('CANARY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    |
    | Handles of the forms checked when canary:forms runs without arguments.
    | Leave it empty to pick forms from a list, and optionally save them.
    |
    */

    'forms' => [],

];
