<?php

return [
    'url' => env('CHATWOOT_URL', 'https://app.chatwoot.com'),
    'account_id' => env('CHATWOOT_ACCOUNT_ID'),
    'bot_token' => env('CHATWOOT_BOT_TOKEN'),
    'webhook_secret' => env('CHATWOOT_WEBHOOK_SECRET'),
    'verify_signature' => env('CHATWOOT_VERIFY_SIGNATURE', true),
    'whatsapp_flow_id' => env('WHATSAPP_FLOW_ID'),
    'gemini_api_key' => env('GEMINI_API_KEY'),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-2.0-flash-lite'),
];
