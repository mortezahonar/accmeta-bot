<?php

declare(strict_types=1);

/* Copy to secrets.php for local development. Never commit secrets.php. */
return [
    'bot_token' => 'PASTE_ACCMETA_TELEGRAM_BOT_TOKEN',
    'chat_id' => 'PASTE_TARGET_GROUP_CHAT_ID',
    'gemini_api_key' => 'PASTE_GEMINI_API_KEY',
    'gemini_model' => 'gemini-3.5-flash,gemini-3.1-flash-lite',
    'fallback_api_key' => 'PASTE_OPTIONAL_FALLBACK_API_KEY',
    'fallback_base_url' => 'https://tokenharbor.ai/v1',
    'fallback_model' => 'deepseek-v4.1-flash:free',
    'proxy' => '',
    'allowed_user_ids' => [],
];
