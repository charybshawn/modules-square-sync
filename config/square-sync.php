<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | Secrets live in .env only and are never committed. The access token is
    | redacted before any request is written to the events audit log.
    |
    */

    'access_token' => env('SQUARE_ACCESS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Location
    |--------------------------------------------------------------------------
    |
    | The location id is not a secret, so it no longer has to live here to be
    | changed -- an admin can pick it from a dropdown of the token's actual
    | Square locations on the Square Sync page, which saves it as the
    | 'square_sync.location_id' site setting instead. This env value is only
    | the fallback Cultpantry\SquareSync\Actions\GetSquareLocationId reads
    | when no setting has been saved yet, so existing deployments that only
    | ever set SQUARE_LOCATION_ID keep working unchanged. Every read of the
    | location id elsewhere in this module goes through that resolver, never
    | straight to this config key.
    |
    */

    'location_id' => env('SQUARE_LOCATION_ID'),

    'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | 'sandbox' or 'production'. Drives which base URL the client talks to.
    |
    */

    'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),

    'base_url' => env('SQUARE_ENVIRONMENT', 'sandbox') === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com',

    /*
    |--------------------------------------------------------------------------
    | API version
    |--------------------------------------------------------------------------
    |
    | Sent as the Square-Version header. Pinning this is what lets us talk to
    | the REST API directly without taking on SDK upgrade churn -- bump it
    | deliberately, never implicitly.
    |
    */

    'version' => env('SQUARE_API_VERSION', '2025-01-23'),

    /*
    |--------------------------------------------------------------------------
    | Webhook notification URL
    |--------------------------------------------------------------------------
    |
    | Square computes its signature over (notification_url + raw body), so this
    | must match the URL registered in the Square Developer Console byte for
    | byte or every signature check fails.
    |
    */

    'notification_url' => env('SQUARE_NOTIFICATION_URL'),

    /*
    |--------------------------------------------------------------------------
    | HTTP behaviour
    |--------------------------------------------------------------------------
    */

    'timeout' => (int) env('SQUARE_TIMEOUT', 15),

    'retry_times' => (int) env('SQUARE_RETRY_TIMES', 3),

    'retry_sleep_ms' => (int) env('SQUARE_RETRY_SLEEP_MS', 200),

];
