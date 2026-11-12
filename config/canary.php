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

    /*
    |--------------------------------------------------------------------------
    | Who test submissions are from
    |--------------------------------------------------------------------------
    |
    | Named in the message of every test submission, such as "Test submission
    | from D3 Creative. Please ignore.", so whoever receives one knows who sent
    | it and that it can be ignored. It doesn't change the emails' from address.
    |
    */

    'from_name' => env('CANARY_FROM_NAME', 'D3 Creative'),

    /*
    |--------------------------------------------------------------------------
    | Forms --submit may send
    |--------------------------------------------------------------------------
    |
    | --submit sends a real submission through each form's listeners and queue,
    | so it only runs on the forms listed here. Only list a form once you know
    | where its submissions go: a CRM integration without a test branch for the
    | test address notifies its real recipients. Comma-separated in the env.
    |
    */

    'submit' => array_values(array_filter(array_map('trim', explode(',', (string) env('CANARY_SUBMIT', ''))))),

    /*
    |--------------------------------------------------------------------------
    | When test contacts are deleted
    |--------------------------------------------------------------------------
    |
    | Minutes after a --submit run before each test contact is deleted from the
    | integrations that can delete one, so their automations have time to send.
    | The delete is a queued job, so it also needs a queue worker.
    |
    */

    'delete_after' => (int) env('CANARY_DELETE_AFTER', 15),

];
