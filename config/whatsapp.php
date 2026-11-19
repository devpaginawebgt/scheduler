<?php

return [
    'meta_app_secret' => env('META_APP_SECRET'),
    'flow_private_key_path' => env('FLOW_PRIVATE_KEY_PATH', 'storage/keys/flow_private.pem'),
    'flow_private_key_passphrase' => env('FLOW_PRIVATE_KEY_PASSPHRASE'),
    'flow_timezone' => env('FLOW_TIMEZONE', 'America/Guatemala'),
];
