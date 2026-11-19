<?php

return [
    'meta_app_secret' => env('META_APP_SECRET'),
    'flow_private_key_path' => env('FLOW_PRIVATE_KEY_PATH', 'storage/keys/flow_private.pem'),
    'flow_private_key_passphrase' => env('FLOW_PRIVATE_KEY_PASSPHRASE'),
    'flow_timezone' => env('FLOW_TIMEZONE', 'America/Guatemala'),
    'phone_number_id' => env('META_PHONE_NUMBER_ID'),
    'access_token' => env('META_ACCESS_TOKEN'),
    'api_version' => env('META_API_VERSION', 'v21.0'),
    'booking_host_email' => env('BOOKING_HOST_EMAIL', 'dev@paginawebguatemala.com'),
];
